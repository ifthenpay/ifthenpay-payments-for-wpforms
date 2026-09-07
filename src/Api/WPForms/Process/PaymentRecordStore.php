<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\WPForms\Process;

use Ifthenpay\WPForms\Api\Ifthenpay\IfthenpayPayload;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Reads and writes the underlying WPForms payment row (and this plugin's own
 * non-autoloaded "summary" option mirroring it) on behalf of the rest of the Process
 * collaborators. Owns no business rules of its own beyond the "completed" is terminal
 * safeguard in mark_wpforms_payments_status() and the ownership checks below.
 */
class PaymentRecordStore
{
	public function __construct(
		private string $slug,
	) {
	}

	/**
	 * Insert a normal, independently-numbered WPForms payment row via WPForms' own insert API.
	 *
	 * @param array<string, mixed> $summary Output of IfthenpayFormData::extract_payment_summary().
	 */
	public function create_wpforms_payment( int $form_id, array $summary, string $title, int $entry_id = 0 ): int {
		$payment = wpforms()->obj( 'payment' );
		if ( ! $payment || ! method_exists( $payment, 'add' ) ) {
			return 0;
		}

		$payment_id = $payment->add(
			[
				'form_id'         => $form_id,
				'status'          => 'pending',
				'total_amount'    => isset( $summary['total_amount'] ) ? (float) $summary['total_amount'] : 0.0,
				'subtotal_amount' => isset( $summary['subtotal_amount'] ) ? (float) $summary['subtotal_amount'] : 0.0,
				'discount_amount' => isset( $summary['discount_amount'] ) ? (float) $summary['discount_amount'] : 0.0,
				'currency'        => function_exists( 'wpforms_get_currency' ) ? wpforms_get_currency() : 'EUR',
				'gateway'         => $this->slug,
				'type'            => 'one-time',
				'mode'            => 'live',
				'transaction_id'  => '',
				'customer_id'     => IfthenpayPayload::generate_customer_id(),
				'title'           => $title,
				'entry_id'        => $entry_id,
			]
		);

		return (int) $payment_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update_payment_row( int $payment_id, array $data ): bool {
		if ( $payment_id <= 0 || empty( $data ) || ! function_exists( 'wpforms' ) ) {
			return false;
		}

		$data['date_updated_gmt'] = current_time( 'mysql', true );
		$payment                  = wpforms()->obj( 'payment' ) ?: wpforms()->get( 'payment' );

		if ( ! $payment || ! method_exists( $payment, 'update' ) ) {
			return false;
		}

		// WPForms' Payment::update() gates on manage_options by default (no init_allowed()
		// escape hatch, unlike ->get()) — without cap => false this silently no-ops for every
		// guest checkout and for the fully-unauthenticated ifthenpay webhook, leaving payment
		// rows permanently stuck at whatever status they were created with.
		return (bool) $payment->update( $payment_id, $data, '', '', array( 'cap' => false ) );
	}

	public function update_payment_method( int $payment_id, string $payment_method ): void {
		$payment_method = trim( $payment_method );
		if ( $payment_id <= 0 || $payment_method === '' ) {
			return;
		}

		$this->update_stored_payment_summary(
			$payment_id,
			array(
				'payment_method' => $payment_method,
			)
		);

		if ( function_exists( 'wpforms' ) ) {
			$payment_meta = wpforms()->get( 'payment_meta' );
			if ( $payment_meta && method_exists( $payment_meta, 'update_or_add' ) ) {
				$payment_meta->update_or_add( $payment_id, 'method_type', $payment_method );
			}
		}
	}

	/**
	 * Updates the payment status for a given payment ID in WPForms.
	 * @param string $payment_id The ID of the payment to update.
	 * @param string $status     The new status to set (e.g., 'completed', 'cancelled', 'failed').
	 */
	public function mark_wpforms_payments_status( string $payment_id, string $status ): void {
		if ( $payment_id === '' ) {
			return;
		}

		$payment_id = (int) $payment_id;
		$updated    = false;

		if ( function_exists( 'wpforms' ) ) {
			$payment_handler = wpforms()->obj( 'payment' );

			// "Completed" is terminal — once a payment is marked paid, nothing may ever move it
			// away from that status again. A cancelled or failed attempt is deliberately NOT
			// terminal the same way: a Multibanco/Payshop reference (or any method the customer
			// abandoned in the browser without fully reversing) can still genuinely get paid
			// later, and the authoritative signal for that — WebhookHandler::handle_webhook_success(),
			// which verifies ifthenpay's own anti-phishing key and the paid amount — must still be
			// able to mark it completed even though it was previously cancelled/failed.
			if ( $status !== 'completed' && $payment_handler && method_exists( $payment_handler, 'get' ) ) {
				$current = $payment_handler->get( $payment_id, array( 'cap' => false ) );
				if ( $current && isset( $current->status ) && (string) $current->status === 'completed' ) {
					return;
				}
			}

			if ( $payment_handler && method_exists( $payment_handler, 'update' ) ) {
				// See update_payment_row() — Payment::update() requires cap => false to work
				// outside a logged-in admin request (guest checkouts, the ifthenpay webhook).
				$updated = (bool) $payment_handler->update(
					$payment_id,
					array(
						'status'           => $status,
						'date_updated_gmt' => current_time( 'mysql', true ),
					),
					'',
					'',
					array( 'cap' => false )
				);
			}
		}

		if ( ! $updated ) {
			return;
		}

		do_action( 'iftp_pbl_payment_status_changed', $payment_id, $status );
	}

	/**
	 * Update stored payment summary data for a payment record. A plain (non-autoloaded) option
	 * rather than a transient: Multibanco/Payshop references can be paid days after creation,
	 * well past a transient's typical lifetime, and this data is what lets a webhook-only
	 * confirmation (no browser ever returns) still recreate the real WPForms entry correctly.
	 * @param int $payment_id Payment ID.
	 * @param array<string, mixed> $changes Key-value pairs of summary data to update.
	 */
	public function update_stored_payment_summary( int $payment_id, array $changes ): void {
		if ( $payment_id <= 0 ) {
			return;
		}

		$key     = $this->payment_summary_option_key( $payment_id );
		$summary = get_option( $key, [] );
		$summary = is_array( $summary ) ? $summary : [];
		$summary = array_merge( $summary, $changes );

		update_option( $key, $summary, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_stored_payment_summary( int $payment_id ): array {
		if ( $payment_id <= 0 ) {
			return [];
		}

		$summary = get_option( $this->payment_summary_option_key( $payment_id ), [] );

		return is_array( $summary ) ? $summary : [];
	}

	public function clear_stored_payment_summary( int $payment_id ): void {
		delete_option( $this->payment_summary_option_key( $payment_id ) );
	}

	private function payment_summary_option_key( int $payment_id ): string {
		return 'iftp_pbl_payment_summary_' . $payment_id;
	}

	public function get_wpforms_payment_status( int $payment_id ): string {
		if ( $payment_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return '';
		}

		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );

		return $payment && isset( $payment->status ) ? (string) $payment->status : '';
	}

	/**
	 * Read the posted payment ID.
	 *
	 * Deliberately reads $_POST directly rather than filter_input(INPUT_POST, ...):
	 * filter_input() reads PHP's original raw request buffer, captured once at the start of the
	 * request — it never reflects a runtime write to the $_POST superglobal. EntryManager's
	 * create_entry_for_payment_unlocked() relies on exactly that kind of write (setting
	 * $_POST['iftp_pbl_payment_id'] before replaying a submission through WPForms' process()) to
	 * tell this plugin's own hooks (PaymentDataPreparer::prepare_payment_data(),
	 * entry_saved_process(), payment_confirmation_process(), get_context()) which payment they're
	 * confirming. With filter_input(), every one of those hooks would silently see 0 during a
	 * replay, and entry_saved_process() would bail out without ever linking the newly-created
	 * entry back to its payment row. A real browser submission posts this same field normally, so
	 * reading $_POST still works correctly for that case too.
	 */
	public function get_posted_payment_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- WPForms verifies its own submission nonce; this field is read, not trusted as an auth token (see owns_wpforms_payment()).
		return isset( $_POST['iftp_pbl_payment_id'] ) ? absint( wp_unslash( $_POST['iftp_pbl_payment_id'] ) ) : 0;
	}

	/**
	 * Looser ownership check for paths (entry_saved_process()) that legitimately need to update
	 * a payment row regardless of its current status — unlike can_update_wpforms_payment(), a row
	 * already marked "completed" earlier in the same request (see prepare_payment_data(), which
	 * runs first) is still fair game here. Still confirms the row exists and actually belongs to
	 * this gateway before trusting a posted payment_id to target it.
	 */
	public function owns_wpforms_payment( int $payment_id ): bool {
		if ( $payment_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return false;
		}

		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		if ( ! $payment ) {
			return false;
		}

		return ( isset( $payment->gateway ) ? (string) $payment->gateway : '' ) === $this->slug;
	}

	/**
	 * Only ever mutate a WPForms payment row from an unauthenticated webhook when it can be
	 * confirmed to (a) still exist, (b) belong to this gateway, and (c) currently sit in one of
	 * $allowedStatuses — this is the safety net behind the pbl_ref scheme: even in the unlikely
	 * event a stored payment_id turns out to be stale (e.g. WPForms' own row-id counter reused
	 * it for something else), a row that already resolved through another gateway/attempt is
	 * left untouched. Defaults to "pending" only; WebhookHandler::handle_webhook_success() widens
	 * this to also accept "cancelled"/"failed", since ifthenpay can still report a reference as
	 * paid after an earlier failure/cancellation notification (e.g. a Multibanco reference paid
	 * after expiry was first reported as cancelled) — completed is never in the allowed set, so a
	 * stray webhook can't downgrade a payment that's already resolved.
	 *
	 * @param array<int, string> $allowedStatuses
	 */
	public function can_update_wpforms_payment( int $payment_id, array $allowedStatuses = [ 'pending' ] ): bool {
		if ( $payment_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return false;
		}

		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		if ( ! $payment ) {
			return false;
		}

		$gateway = isset( $payment->gateway ) ? (string) $payment->gateway : '';
		$status  = isset( $payment->status ) ? (string) $payment->status : '';

		return $gateway === $this->slug && in_array( $status, $allowedStatuses, true );
	}
}
