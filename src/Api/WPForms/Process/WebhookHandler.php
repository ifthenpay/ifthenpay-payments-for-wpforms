<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\WPForms\Process;

use Ifthenpay\WPForms\Api\Ifthenpay\IfthenpayClient;
use Ifthenpay\WPForms\Api\Ifthenpay\IfthenpayPayload;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Resolves a WPForms payment as "completed", server-to-server, through either of two paths:
 * ifthenpay's asynchronous merchant-notification webhook (handle_ifthenpay_webhook(), the only
 * path a Multibanco/Payshop reference paid after the customer has left the site ever resolves
 * through), or confirm_via_transaction_status(), a synchronous fast path Process::ajax_verify_payment()
 * calls the instant the browser returns with a transaction id, so the "processing" popup doesn't
 * have to sit waiting on the webhook (which can lag a few seconds) or the frontend's own 3s poll
 * tick. Both independently verify the payment against ifthenpay itself before either may mark a
 * payment "completed" — see each method's own docblock for its trust model.
 */
class WebhookHandler
{
	public function __construct(
		private PaymentRecordStore $paymentRecordStore,
		private EntryManager $entryManager,
		private WebhookContextStore $webhookContextStore,
	) {
	}

	/**
	 * Registered against ifthenpay via IfthenpayClient::activate_callback() (see
	 * Payments::activate_ifthenpay_callback()).
	 */
	public function handle_ifthenpay_webhook(): void {
		if ( ! $this->is_ifthenpay_callback_request() ) {
			return;
		}

		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || file_get_contents( 'php://input' ) !== '' ) {
			status_header( 400 );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		if ( isset( $_GET['status'], $_GET['ref'] ) ) {
			$this->handle_webhook_failure();
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		if ( isset( $_GET['ref'], $_GET['apk'], $_GET['val'], $_GET['mtd'], $_GET['req'] ) ) {
			$this->handle_webhook_success();
			exit;
		}

		status_header( 400 );
		exit;
	}

	/**
	 * Matches requests against the query-var marker built by
	 * IfthenpayPayload::build_gateway_urls() (?iftp_wpforms_cb=wpforms on the site
	 * root). A query string on the home URL is used rather than a bare invented path so
	 * this is reachable regardless of the site's permalink structure — see the docblock
	 * on IfthenpayPayload::CALLBACK_QUERY_VAR for why a bare path isn't safe to rely on.
	 */
	private function is_ifthenpay_callback_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$marker = isset( $_GET[ IfthenpayPayload::CALLBACK_QUERY_VAR ] )
			? sanitize_text_field( wp_unslash( (string) $_GET[ IfthenpayPayload::CALLBACK_QUERY_VAR ] ) )
			: '';

		return $marker !== '' && $marker === IfthenpayPayload::callback_path_segment();
	}

	private function handle_webhook_failure(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$pbl_ref = sanitize_text_field( wp_unslash( (string) ( $_GET['ref'] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$status = strtolower( sanitize_text_field( wp_unslash( (string) ( $_GET['status'] ?? '' ) ) ) );

		if ( $pbl_ref === '' || ! in_array( $status, [ 'cancelled', 'error' ], true ) ) {
			status_header( 404 );
			return;
		}

		// A stored context proves this ref was actually issued by this plugin for an ifthenpay
		// Pay By Link — without it, an unauthenticated ref guess could flip the status of any
		// WPForms payment on the site.
		$context = $this->webhookContextStore->get( $pbl_ref );
		if ( empty( $context['gateway_key'] ) ) {
			status_header( 404 );
			return;
		}

		$new_status = $status === 'cancelled' ? 'cancelled' : 'failed';
		$payment_id = isset( $context['payment_id'] ) ? (int) $context['payment_id'] : 0;

		if ( $this->paymentRecordStore->can_update_wpforms_payment( $payment_id ) ) {
			$this->paymentRecordStore->mark_wpforms_payments_status( (string) $payment_id, $new_status );
		}

		$this->webhookContextStore->update_status( $pbl_ref, $context, $new_status );

		status_header( 200 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal acknowledgement, not user input.
		echo 'OK';
	}

	private function handle_webhook_success(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$pbl_ref = sanitize_text_field( wp_unslash( (string) ( $_GET['ref'] ?? '' ) ) );
		$context = $this->webhookContextStore->get( $pbl_ref );

		if ( $pbl_ref === '' || empty( $context['gateway_key'] ) ) {
			status_header( 404 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		// A raw, un-percent-encoded "+" in a query string is decoded to a space by PHP before
		// $_GET is even populated — restoring it here protects a legitimate base64 apk (whose
		// alphabet includes "+") from being silently corrupted if it wasn't percent-encoded.
		$raw_apk             = strtr( sanitize_text_field( wp_unslash( (string) $_GET['apk'] ) ), ' ', '+' );
		$decoded_gateway_key = trim( (string) base64_decode( $raw_apk, true ) );
		if ( ! hash_equals( (string) $context['gateway_key'], $decoded_gateway_key ) ) {
			status_header( 403 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$amount          = (float) sanitize_text_field( wp_unslash( (string) $_GET['val'] ) );
		$expected_amount = isset( $context['amount'] ) ? (float) $context['amount'] : 0.0;
		if ( $expected_amount > 0 && abs( $amount - $expected_amount ) > 0.01 ) {
			status_header( 409 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$payment_method = sanitize_text_field( wp_unslash( (string) $_GET['mtd'] ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$request_id = sanitize_text_field( wp_unslash( (string) $_GET['req'] ) );

		$payment_id = isset( $context['payment_id'] ) ? (int) $context['payment_id'] : 0;

		if ( $this->paymentRecordStore->can_update_wpforms_payment( $payment_id, [ 'pending', 'cancelled', 'failed' ] ) ) {
			$this->complete_confirmed_payment( $payment_id, $payment_method, $request_id, $amount );
		}

		// The payment row was created up front (see Process::handle_process()), so it always
		// exists by the time this webhook can arrive. EntryManager::create_entry_for_payment() is
		// idempotent, so it's safe to attempt regardless of whether the browser already confirmed
		// this payment itself — this is what lets a Multibanco/Payshop reference paid days after
		// the customer left still produce a normal WPForms entry. In the ordinary case the entry
		// (and its deferred notifications, released above) already exist by the time this runs;
		// this call only still does real work for a submission that somehow never got its
		// up-front entry (e.g. an older payment created before this plugin version), where
		// notifications fire inline.
		if ( $payment_id > 0 ) {
			$this->entryManager->create_entry_for_payment( $payment_id );
		}

		$context['payment_method'] = $payment_method;
		$this->webhookContextStore->update_status( $pbl_ref, $context, 'completed' );

		status_header( 200 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal acknowledgement, not user input.
		echo 'OK';
	}

	/**
	 * Confirm a payment synchronously via ifthenpay's own transaction-status endpoint, using the
	 * transaction id ifthenpay hands back to the customer's browser on redirect (see
	 * IfthenpayPayload::build_gateway_urls()'s success_url [TRANSACTIONID] placeholder). Called
	 * from Process::ajax_verify_payment() the instant the browser lands back on our success URL,
	 * so the outcome popup doesn't have to wait for handle_webhook_success() above (ifthenpay's
	 * webhook can lag a few seconds behind the redirect) or the frontend's own 3s poll tick.
	 *
	 * Trust model: exactly like the webhook, a client can send any transaction_id it likes — what
	 * makes this safe is that the id only means anything if ifthenpay's own API, queried
	 * server-to-server, reports a transaction whose OrderId and Amount independently match this
	 * specific pending payment (the same pbl_ref/amount pair stored by WebhookContextStore::store()
	 * when the payment was created). A forged/unrelated id simply fails that match and this
	 * returns false, leaving the payment "pending" for the webhook to resolve as usual.
	 */
	public function confirm_via_transaction_status( int $payment_id, string $transaction_id ): bool {
		$transaction_id = trim( $transaction_id );
		if ( $payment_id <= 0 || $transaction_id === '' ) {
			return false;
		}

		if ( $this->paymentRecordStore->get_wpforms_payment_status( $payment_id ) === 'completed' ) {
			return true;
		}

		if ( ! $this->paymentRecordStore->can_update_wpforms_payment( $payment_id, [ 'pending', 'cancelled', 'failed' ] ) ) {
			return false;
		}

		$pbl_ref = $this->webhookContextStore->generate_pbl_ref( $payment_id );
		$context = $this->webhookContextStore->get( $pbl_ref );
		if ( empty( $context['gateway_key'] ) ) {
			return false;
		}

		try {
			$status = IfthenpayClient::get_transaction_status( $transaction_id );
		} catch ( \RuntimeException ) {
			// ifthenpay's API is unreachable or errored — fall back to the webhook/poll, same as
			// any other transient failure of this best-effort speed-up.
			return false;
		}

		$order_id = isset( $status['OrderId'] ) ? sanitize_text_field( (string) $status['OrderId'] ) : '';
		if ( $order_id === '' || $order_id !== $pbl_ref ) {
			return false;
		}

		$amount          = isset( $status['Amount'] ) ? (float) $status['Amount'] : 0.0;
		$expected_amount = isset( $context['amount'] ) ? (float) $context['amount'] : 0.0;
		if ( $expected_amount > 0 && abs( $amount - $expected_amount ) > 0.01 ) {
			return false;
		}

		$payment_method = isset( $status['PaymentMethod'] ) ? sanitize_text_field( (string) $status['PaymentMethod'] ) : '';
		if ( $payment_method === '' ) {
			return false;
		}

		$this->complete_confirmed_payment( $payment_id, $payment_method, $transaction_id, $amount );

		$context['payment_method'] = $payment_method;
		$this->webhookContextStore->update_status( $pbl_ref, $context, 'completed' );

		return true;
	}

	/**
	 * Shared completion side effects for a payment independently confirmed paid, whether that
	 * confirmation came from handle_webhook_success() or confirm_via_transaction_status() — both
	 * call this only after their own server-to-server verification against ifthenpay has passed.
	 */
	private function complete_confirmed_payment( int $payment_id, string $payment_method, string $request_id, float $amount ): void {
		$this->paymentRecordStore->update_payment_method( $payment_id, $payment_method );
		$this->paymentRecordStore->mark_wpforms_payments_status( (string) $payment_id, 'completed' );

		$this->paymentRecordStore->update_stored_payment_summary(
			$payment_id,
			[
				'verified_payment_method' => $payment_method,
				'verified_successful'     => true,
				'verified_at'             => time(),
			]
		);

		// The entry itself already exists (created up front as "pending" — see
		// Process::ajax_create_pay_button_payment()) with its notifications held back; now
		// that the payment has actually resolved, send them.
		$this->entryManager->release_deferred_notifications( $payment_id );

		// The Pay by Link note was already added when the entry was created; add ifthenpay's
		// own request/transaction id now, since it only exists once the payment has actually
		// resolved.
		$this->entryManager->add_ifthenpay_request_id_note( $payment_id, $request_id, $amount );

		// Idempotent — safe to call regardless of which confirmation path reached here first,
		// and regardless of whether the entry (and its deferred notifications, released above)
		// already exist.
		if ( $payment_id > 0 ) {
			$this->entryManager->create_entry_for_payment( $payment_id );
		}
	}
}
