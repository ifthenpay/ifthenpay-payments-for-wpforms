<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\WPForms\Process;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Persists everything needed to validate and, later, act on a future ifthenpay webhook call
 * for a given Pay By Link attempt. Split out from WebhookHandler/EntryManager so both can read
 * this context (WebhookHandler to resolve an incoming callback, EntryManager to look up the
 * redirect URL for an entry note) without depending on each other.
 */
class WebhookContextStore
{
	/**
	 * The reference sent to ifthenpay as the Pay By Link "id" and echoed back as the webhook's
	 * "ref". ifthenpay's own hosted payment page displays this value back to the customer as
	 * "ID #..." — since that's the only "id" ifthenpay exposes at all, it has to be the same
	 * value used for webhook lookups; there's no way to show a customer-facing id without it also
	 * being the tracking reference. Using the WPForms payment id here (rather than an opaque
	 * random string) is what makes that displayed ID match the numeric order the customer/site
	 * owner already sees everywhere else (the entry, the payment record, "Order #18").
	 *
	 * Note: this makes `ref` on the *unauthenticated* WebhookHandler::handle_webhook_failure()
	 * endpoint a small, guessable sequential number rather than a 15-character random string.
	 * That endpoint has no signature check (ifthenpay's cancel/error notifications don't carry
	 * one, unlike its success callback's `apk`), so this trades a little security-through-obscurity
	 * for a correct-looking id; the actual damage a guessed ref allows is limited to flipping a
	 * still-pending payment to "cancelled" — see PaymentRecordStore::can_update_wpforms_payment()
	 * — which a genuine later success webhook can still correct.
	 */
	public function generate_pbl_ref( int $payment_id ): string {
		return (string) $payment_id;
	}

	/**
	 * Persist everything needed to validate and, later, act on a future webhook call for this
	 * Pay By Link attempt. Stored as a plain (non-autoloaded) option rather than a transient:
	 * Multibanco/Payshop references can be paid days after creation, well past a transient's
	 * typical lifetime. Left in place (status updated, not deleted) once resolved, so a payment
	 * that came in with no linked WPForms row stays visible rather than disappearing.
	 */
	public function store( string $pbl_ref, string $gateway_key, float $amount, int $payment_id, string $redirect_url = '' ): void {
		if ( $pbl_ref === '' || $gateway_key === '' ) {
			return;
		}

		update_option(
			$this->option_key( $pbl_ref ),
			[
				'gateway_key'  => $gateway_key,
				'amount'       => $amount,
				'payment_id'   => $payment_id,
				'redirect_url' => $redirect_url,
				'status'       => 'pending',
				'created_at'   => time(),
			],
			false
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function update_status( string $pbl_ref, array $context, string $status ): void {
		if ( $pbl_ref === '' ) {
			return;
		}

		$context['status']      = $status;
		$context['resolved_at'] = time();

		update_option( $this->option_key( $pbl_ref ), $context, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get( string $pbl_ref ): array {
		if ( $pbl_ref === '' ) {
			return [];
		}

		$context = get_option( $this->option_key( $pbl_ref ), [] );

		return is_array( $context ) ? $context : [];
	}

	private function option_key( string $pbl_ref ): string {
		return 'iftp_pbl_webhook_ctx_' . $pbl_ref;
	}
}
