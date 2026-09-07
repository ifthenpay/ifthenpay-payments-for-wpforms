<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\WPForms\Process;

use Ifthenpay\WPForms\Api\Ifthenpay\IfthenpayPayload;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Receives ifthenpay's asynchronous payment-notification webhook and resolves it against the
 * WPForms payment row it belongs to. Confirms payments server-to-server, independent of the
 * customer's browser — this is what actually resolves Multibanco/Payshop references that get
 * paid after the customer has left the site.
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
			// own request id now, since it only exists once the payment has actually resolved.
			$this->entryManager->add_ifthenpay_request_id_note( $payment_id, $request_id, $amount );
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
}
