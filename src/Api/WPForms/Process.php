<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\WPForms;

use Ifthenpay\WPForms\Admin\Settings;
use Ifthenpay\WPForms\Api\Ifthenpay\IfthenpayClient;
use Ifthenpay\WPForms\Api\Ifthenpay\IfthenpayFormData;
use Ifthenpay\WPForms\Api\Ifthenpay\IfthenpayPayload;
use Ifthenpay\WPForms\Api\WPForms\Process\EntryManager;
use Ifthenpay\WPForms\Api\WPForms\Process\EntryPreviewRenderer;
use Ifthenpay\WPForms\Api\WPForms\Process\PaymentDataPreparer;
use Ifthenpay\WPForms\Api\WPForms\Process\PaymentRecordStore;
use Ifthenpay\WPForms\Api\WPForms\Process\WebhookContextStore;
use Ifthenpay\WPForms\Api\WPForms\Process\WebhookHandler;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Orchestrates a Pay By Link payment attempt end to end: builds the ifthenpay payload and
 * creates the WPForms payment row up front (handle_process()), then reports/verifies status
 * back to the frontend (ajax_verify_payment()). The WPForms submission-lifecycle hooks and the
 * asynchronous ifthenpay webhook are delegated to dedicated collaborators — see
 * Process\PaymentDataPreparer and Process\WebhookHandler — since neither is called by anything
 * outside this class's own constructor.
 */
class Process
{
	private PaymentRecordStore $paymentRecordStore;
	private WebhookContextStore $webhookContextStore;
	private EntryManager $entryManager;
	private WebhookHandler $webhookHandler;

	public function __construct(
		private string $slug,
	) {
		$this->paymentRecordStore  = new PaymentRecordStore( $slug );
		$this->webhookContextStore = new WebhookContextStore();
		$this->entryManager        = new EntryManager( $this->webhookContextStore );
		$this->webhookHandler       = new WebhookHandler( $this->paymentRecordStore, $this->entryManager, $this->webhookContextStore );

		$paymentDataPreparer = new PaymentDataPreparer( $this->paymentRecordStore, $this->entryManager );

		add_filter('wpforms_forms_submission_prepare_payment_data', [$paymentDataPreparer, 'prepare_payment_data'], 10, 3);
		add_filter('wpforms_forms_submission_prepare_payment_meta', [$paymentDataPreparer, 'prepare_payment_meta'], 10, 3);
		add_filter('wpforms_process_initial_errors', [$paymentDataPreparer, 'payment_check_process'], 10, 2);
		add_action('wpforms_process_payment_saved', [$paymentDataPreparer, 'payment_saved_process'], 10, 3);
		add_action('wpforms_process_entry_saved', [$paymentDataPreparer, 'entry_saved_process'], 10, 5);
		add_action('wpforms_process_complete', [$paymentDataPreparer, 'payment_confirmation_process'], 10, 4);
		add_action('init', [$this->webhookHandler, 'handle_ifthenpay_webhook']);
	}

	/**
	 * Handle the payment process from form submission and create a payment session.
	 * @param array<string, mixed> $fields The submitted form fields
	 * @param array<string, mixed> $entry The entry data
	 * @param array<string, mixed> $form_data The form configuration data
	 * @return array<string, mixed> Returns success state, message, and payment data if created
	 */
	public function handle_process( array $fields, array $entry, array $form_data, bool $is_ajax_request = false ): array {
		try {
			if ( ! $this->should_handle( $form_data, $is_ajax_request ) ) {
				return array(
					'success' => true,
					'handled' => false,
				);
			}

			$payment_id = absint( filter_input( INPUT_POST, 'iftp_pbl_payment_id', FILTER_SANITIZE_NUMBER_INT ) );

			if ( $payment_id > 0 ) {
				return array(
					'success' => true,
					'handled' => false,
				);
			}

			$backoffice_key = Settings::get_backoffice_key();
			if ( $backoffice_key === '' ) {
				return array(
					'success' => false,
					'message' => 'Gateway not configured',
				);
			}

			$config = isset( $form_data['payments'][ $this->slug ] ) && is_array( $form_data['payments'][ $this->slug ] )
				? $form_data['payments'][ $this->slug ]
				: [];

			$gateway_key = isset( $config['gateway_key'] ) ? trim( (string) $config['gateway_key'] ) : '';
			if ( $gateway_key === '' ) {
				return array(
					'success' => false,
					'message' => 'Gateway key not configured',
				);
			}

			$amount = IfthenpayFormData::resolve_amount( $fields );
			if ( $amount < 0 ) {
				return array(
					'success' => false,
					'message' => 'Amount cannot be lower than 0',
				);
			}

			if ( $amount <= 0 ) {
				return array(
					'success'    => true,
					'skip_payment' => true,
				);
			}

			$methods_config         = IfthenpayPayload::get_gateway_methods_config( $config, $gateway_key );
			$selected_method_entity = IfthenpayPayload::get_selected_method_entity( $config, $methods_config );
			$selected_method_code   = IfthenpayPayload::get_selected_method_code( $config, $methods_config );
			$description            = 'Payment Gateway';
			$expire_days            = isset( $config['expire_days'] ) ? max( 1, absint( $config['expire_days'] ) ) : 1;
			$accounts               = IfthenpayPayload::build_accounts_string( $methods_config );

			if ( $accounts === '' ) {
				return array(
					'success' => false,
					'message' => 'No payment methods configured',
				);
			}

			$summary = IfthenpayFormData::extract_payment_summary( $fields, $entry, $form_data, $this->slug, $selected_method_entity );
			$name    = PaymentDataPreparer::extract_name( $fields );
			$title   = $name !== '' ? $name : 'UserID: ' . IfthenpayPayload::generate_customer_id() . ' Purchase';

			// Reserves a WPForms payment row up front so the pending attempt is tracked (and its
			// id can be used as the pbl_ref/order number shown to the customer) before ifthenpay
			// has confirmed anything. The WPForms *entry* itself is created right after, back in
			// ajax_create_pay_button_payment() — see EntryManager::create_entry_for_payment() —
			// visible in the entries list immediately as "pending", but with its notification
			// emails deferred until the payment actually resolves.
			$form_id    = isset( $form_data['id'] ) ? (int) $form_data['id'] : 0;
			$payment_id = $this->paymentRecordStore->create_wpforms_payment( $form_id, $summary, $title );
			if ( $payment_id <= 0 ) {
				return array(
					'success' => false,
					'message' => 'Unable to reserve payment id',
				);
			}

			$summary['status']     = 'pending';
			$summary['payment_id'] = $payment_id;
			$summary['title']      = $title;

			$this->paymentRecordStore->update_stored_payment_summary( $payment_id, $summary );

			$pbl_ref = $this->webhookContextStore->generate_pbl_ref( $payment_id );

			$base_url = wp_get_referer() ?: home_url( '/' );
			$gateway_urls = IfthenpayPayload::build_gateway_urls( $payment_id, $base_url );

			$payload = IfthenpayPayload::build_pay_by_link_payload(
				array(
					'id'              => $pbl_ref,
					'amount'          => $amount,
					'description'     => $description,
					'accounts'        => $accounts,
					'expire_days'     => $expire_days,
					'success_url'     => $gateway_urls['success_url'],
					'error_url'       => $gateway_urls['error_url'],
					'cancel_url'      => $gateway_urls['cancel_url'],
					'locale'          => get_locale(),
					'callback_url'    => $gateway_urls['callback_url'],
					'selected_method' => $selected_method_code,
				)
			);

			$data = IfthenpayClient::create_payment_link( $gateway_key, $payload );

			if ( ! is_array( $data ) || empty( $data['RedirectUrl'] ) ) {
				$this->paymentRecordStore->mark_wpforms_payments_status( (string) $payment_id, 'failed' );

				return array(
					'success' => false,
					'message' => 'Failed to create payment link',
				);
			}

			$remote_redirect_url = esc_url_raw( (string) $data['RedirectUrl'] );

			if ( $remote_redirect_url === '' ) {
				$this->paymentRecordStore->mark_wpforms_payments_status( (string) $payment_id, 'failed' );

				return array(
					'success' => false,
					'message' => 'Failed to generate payment session',
				);
			}

			$this->webhookContextStore->store( $pbl_ref, $gateway_key, $amount, $payment_id, $remote_redirect_url );
			$this->paymentRecordStore->mark_wpforms_payments_status( (string) $payment_id, 'pending' );

			$payload_data = IfthenpayPayload::build_pay_by_link_session(
				$payment_id,
				$remote_redirect_url,
				$base_url
			);

			return array(
				'success' => true,
				'data'    => $payload_data,
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => 'An error occurred',
			);
		}
	}

	/**
	 * Handle AJAX request to create a Pay By Link payment session.
	 * Validates form, gateway configuration, and returns a payment redirect URL.
	 * @return void
	 */
	public function ajax_create_pay_button_payment(): void {
		try {
			check_ajax_referer( 'iftp_pbl_frontend', 'nonce' );

			$form_id      = isset( $_POST['form_id'] )      ? absint( $_POST['form_id'] )                                                  : 0;
			$gateway_key  = isset( $_POST['gateway_key'] )  ? sanitize_text_field( wp_unslash( (string) $_POST['gateway_key'] ) )          : '';
			$form_payload = '';
			if ( isset( $_POST['form_payload'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Serialized query-string payload is intentionally preserved for wp_parse_str(); values are sanitized after parsing in build_fields_from_request().
				$form_payload = wp_unslash( (string) $_POST['form_payload'] );
			}

			if ( $form_id <= 0 || $gateway_key === '' || $form_payload === '' ) {
				$error_msg = 'Invalid form data';
				if ( $form_id <= 0 ) {
					$error_msg = 'Invalid form ID: ' . $form_id;
				} elseif ( $gateway_key === '' ) {
					$error_msg = 'Gateway key is missing';
				} elseif ( $form_payload === '' ) {
					$error_msg = 'Form payload is missing';
				}
				wp_send_json_error( array( 'message' => $error_msg ) );
			}

			$loaded_form = IfthenpayFormData::load_form_data( $form_id );
			$form_data   = $loaded_form['form_data'];

			if ( empty( $form_data ) ) {
				wp_send_json_error( array( 'message' => 'Form not found or not accessible' ) );
			}

			$submitted_fields = IfthenpayFormData::build_fields_from_request( $form_data, $form_payload );

			$entry            = array(
				'fields' => $submitted_fields,
			);

			$result = $this->handle_process( $submitted_fields, $entry, $form_data, true );

			if ( empty( $result['success'] ) ) {
				wp_send_json_error(
					array(
						'message' => isset( $result['message'] ) ? (string) $result['message'] : 'An error occurred',
					)
				);
			}

			if ( isset( $result['handled'] ) && ! $result['handled'] ) {
				wp_send_json_error( array( 'message' => 'Form not configured for payments' ) );
			}

			if ( ! empty( $result['skip_payment'] ) ) {
				wp_send_json_success(
					array(
						'skip_payment' => true,
					)
				);
			}

			if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) || empty( $result['data'] ) ) {
				wp_send_json_error(
					array(
						'message' => 'Failed to create payment session',
					)
				);
			}

			$reserved_payment_id = isset( $result['data']['payment_id'] ) ? (int) $result['data']['payment_id'] : 0;
			if ( $reserved_payment_id > 0 ) {
				// The raw, complete submission — needed later to create the real WPForms entry
				// once payment is confirmed, whether that happens on a return visit or, for a
				// Multibanco/Payshop reference paid after the customer has left, only via webhook.
				$this->entryManager->store_pending_entry_payload( $reserved_payment_id, $form_id, $form_payload );

				// Create the entry now, visible in the entries list right away as "pending",
				// rather than waiting for payment confirmation — notifications are deferred
				// until the payment actually resolves (see EntryManager::release_deferred_notifications()),
				// so the merchant/customer aren't emailed about an unpaid attempt.
				$this->entryManager->create_entry_for_payment( $reserved_payment_id, true );
			}

			wp_send_json_success( $result['data'] );
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => 'Unable to create the payment link. Please review the ifthenpay configuration and try again.',
				)
			);
		}
	}

	/**
	 * Report a WPForms payment's status after the browser returns from ifthenpay's hosted
	 * payment page.
	 *
	 * IMPORTANT: a client-POSTed "success" outcome is never, by itself, proof of payment.
	 * IfthenpayPayload::build_gateway_urls() builds success/error/cancel_url as a plain,
	 * unsigned query string — nothing here is signed or verified by ifthenpay, so anyone can
	 * forge an identical POST straight to admin-ajax.php (this endpoint is necessarily
	 * `nopriv`, and its nonce is only tied to viewing the form, not to a specific payment).
	 * This method therefore never mutates a payment to "completed" from the client-POSTed
	 * status/params themselves. A "success" return carrying a transaction_id (see
	 * IfthenpayPayload::build_gateway_urls()'s [TRANSACTIONID] placeholder) does get a chance to
	 * resolve the payment right here, via WebhookHandler::confirm_via_transaction_status() —
	 * but that method independently re-verifies the payment server-to-server against ifthenpay's
	 * own API before it may mark anything "completed", exactly like
	 * WebhookHandler::handle_webhook_success() does for the asynchronous merchant-notification
	 * webhook. Until one of those two succeeds, the status here stays "pending" and the frontend
	 * keeps polling (see watchExternalPayment() in frontend.js).
	 */
	public function ajax_verify_payment(): void {

		check_ajax_referer('iftp_pbl_frontend', 'nonce');

		$payment_id    = isset( $_POST['payment_id'] )    ? absint( $_POST['payment_id'] )                                              : 0;
		$return_action = isset( $_POST['return_action'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['return_action'] ) )       : '';

		if ($payment_id <= 0) {
			wp_send_json_error([
				'message' => __('Missing required parameters.', 'ifthenpay-payments-for-wpforms'),
			]);
		}

		// Marks that the browser has genuinely returned from ifthenpay's hosted page for
		// this payment, distinct from the payment's own status. This is what lets the
		// original tab's blind background poll (see watchExternalPayment() in
		// frontend.js, which calls this same endpoint with return_action "poll" — never
		// one of these three) tell "nothing has happened yet, still pending" apart from
		// "ifthenpay's flow just concluded still pending" (e.g. a Multibanco/Payshop
		// reference was only just generated) — the latter should still close the payment
		// tab and show the popup, per Process::respond_with_current_status().
		if (in_array($return_action, ['success', 'cancel', 'error'], true)) {
			$this->paymentRecordStore->update_stored_payment_summary($payment_id, ['browser_returned' => true]);
		}

		if ($return_action === 'cancel') {
			$this->respond_with_status($payment_id, 'cancelled');
		}

		if ($return_action === 'error') {
			$this->respond_with_status($payment_id, 'failed');
		}

		// A genuine "success" return usually carries the transaction id ifthenpay appended to
		// success_url — try to resolve the payment immediately via ifthenpay's own transaction
		// status API rather than waiting on its asynchronous webhook. See
		// WebhookHandler::confirm_via_transaction_status()'s docblock for why a client-supplied
		// transaction_id can't be used to fake a "completed" result.
		if ($return_action === 'success') {
			$transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field(wp_unslash((string) $_POST['transaction_id'])) : '';
			if ($transaction_id !== '') {
				$this->record_received_transaction_id($payment_id, $transaction_id);
				$this->webhookHandler->confirm_via_transaction_status($payment_id, $transaction_id);
			}
		}

		// 'success' and the original tab's own background "poll" action alike only ever
		// report the payment's real, current status — never mutate it to "completed" from
		// client-POSTed data directly. See this method's docblock.
		$this->respond_with_current_status($payment_id);
	}

	/**
	 * Records the transaction id as soon as it's received from the browser — independent of
	 * whether WebhookHandler::confirm_via_transaction_status() (called right after this) manages
	 * to confirm the payment with it. Without this, a transaction id that arrives on a success
	 * return but fails to confirm (e.g. ifthenpay's transaction-status API was transiently
	 * unreachable, or the payment was still a Multibanco/Payshop reference at that point) was
	 * never kept anywhere — it only ever reached storage/entry notes via
	 * complete_confirmed_payment(), i.e. only once the payment was already known paid.
	 *
	 * Guarded against duplicate notes: a customer reloading the success return URL, or the
	 * "poll" and "success" return_actions racing, can both deliver the same transaction id more
	 * than once.
	 */
	private function record_received_transaction_id( int $payment_id, string $transaction_id ): void {
		$summary = $this->paymentRecordStore->get_stored_payment_summary( $payment_id );
		if ( isset( $summary['transaction_id'] ) && (string) $summary['transaction_id'] === $transaction_id ) {
			return;
		}

		$this->paymentRecordStore->update_stored_payment_summary( $payment_id, [ 'transaction_id' => $transaction_id ] );
		$this->entryManager->add_ifthenpay_transaction_id_received_note( $payment_id, $transaction_id );
	}

	/**
	 * Persist and respond with payment status.
	 *
	 * Only ever called with 'cancelled' or 'failed' — both non-terminal (see
	 * PaymentRecordStore::mark_wpforms_payments_status()), so a spoofed call here can, at worst,
	 * temporarily mis-flag a still-pending payment; the genuine
	 * WebhookHandler::handle_webhook_success() can always still resolve it to "completed" later.
	 * Never call this with 'completed' — that status may only ever be set by
	 * handle_webhook_success() itself.
	 */
	private function respond_with_status( int $payment_id, string $status ): void {

		$this->paymentRecordStore->mark_wpforms_payments_status(
			(string) $payment_id,
			$status
		);

		// mark_wpforms_payments_status() refuses to move a payment away from "completed" —
		// reflect the row's real, final status back to the frontend rather than blindly echoing
		// what was requested, so a stale cancel/error check-in is never reported as failed once
		// the payment has actually been confirmed paid.
		$actual_status = $this->paymentRecordStore->get_wpforms_payment_status( $payment_id );
		if ( $actual_status !== '' ) {
			$status = $actual_status;
		}

		wp_send_json_success(
			IfthenpayPayload::build_payment_status_response(
				$status,
				'',
				$this->maybe_build_entry_preview_html( $payment_id, $status ),
				true
			)
		);
	}

	/**
	 * Report a payment's real, current status without mutating it — used for the 'success'
	 * outcome of a gateway return, which is never itself proof of payment (see
	 * ajax_verify_payment()'s docblock), and for the original tab's own background poll
	 * (return_action "poll"). Only WebhookHandler::handle_webhook_success() may ever set
	 * "completed"; until it does, this simply reflects "pending" back to the frontend. The
	 * "returned" flag (see ajax_verify_payment()) additionally tells a background poll
	 * that the browser has already genuinely come back from ifthenpay for this payment,
	 * even though the status itself is still "pending" — e.g. a Multibanco/Payshop
	 * reference was just generated, so there's nothing more happening on this visit.
	 */
	private function respond_with_current_status( int $payment_id ): void {
		$status = $this->paymentRecordStore->get_wpforms_payment_status( $payment_id );
		if ( $status === '' ) {
			$status = 'pending';
		}

		$summary  = $this->paymentRecordStore->get_stored_payment_summary( $payment_id );
		$returned = ! empty( $summary['browser_returned'] );

		wp_send_json_success(
			IfthenpayPayload::build_payment_status_response(
				$status,
				'',
				$this->maybe_build_entry_preview_html( $payment_id, $status ),
				$returned
			)
		);
	}

	/**
	 * Builds the "Show entry preview after confirmation message" HTML for the popup (see
	 * Payments::render_confirmation_messages()), when the form enables it and the payment
	 * has actually completed. Never runs for any other status — showing submitted answers
	 * after a pending/cancelled/failed message isn't what that toggle is for.
	 */
	private function maybe_build_entry_preview_html( int $payment_id, string $status ): string {
		if ( $status !== 'completed' || ! function_exists( 'wpforms' ) ) {
			return '';
		}

		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		if ( ! $payment || empty( $payment->entry_id ) || empty( $payment->form_id ) ) {
			return '';
		}

		$form_data = wpforms()->get( 'form' )->get( (int) $payment->form_id, array( 'content_only' => true ) );
		if ( ! is_array( $form_data ) ) {
			return '';
		}

		$config = Settings::get_form_payment_settings( $form_data );
		if ( empty( $config['confirmations']['paid']['entry_preview'] ) ) {
			return '';
		}

		$entry = wpforms()->obj( 'entry' )->get( (int) $payment->entry_id, array( 'cap' => false ) );
		if ( ! $entry || empty( $entry->fields ) || ! function_exists( 'wpforms_decode' ) ) {
			return '';
		}

		$fields = wpforms_decode( $entry->fields );

		return is_array( $fields ) ? EntryPreviewRenderer::render( $fields ) : '';
	}

	/**
	 * Determine if this gateway should handle the form submission.
	 * Checks if payment is enabled and form has required fields.
	 * @param array<string, mixed> $form_data The form configuration data.
	 * @return bool True if gateway should handle form.
	 */
	public function should_handle( array $form_data, bool $is_ajax_request = false ): bool {
		if ( ! isset( $form_data['payments'][ $this->slug ] ) ) {
			return false;
		}

		$config  = $form_data['payments'][ $this->slug ];
		$enabled = isset( $config['enable'] ) && (string) $config['enable'] === '1';

		if ( ! $enabled ) {
			return false;
		}

		if ( $is_ajax_request ) {
			return true;
		}

		if ( empty( $form_data['fields'] ) || ! is_array( $form_data['fields'] ) ) {
			return false;
		}

		if (
			! IfthenpayFormData::find_field_by_type( $form_data['fields'], 'iftp_pbl_field' )
		) {
			return false;
		}

		return true;
	}
}
