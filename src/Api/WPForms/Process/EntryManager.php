<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\WPForms\Process;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Creates the real WPForms entry for a Pay By Link payment (replaying the raw submission
 * captured up front through WPForms' own process() pipeline), and manages the notification
 * emails deferred until that payment actually resolves.
 */
class EntryManager
{
	/**
	 * Set for the duration of a create_entry_for_payment_unlocked() call made with
	 * $deferNotifications = true, so PaymentDataPreparer::entry_saved_process() (fired
	 * synchronously from inside that call via WPForms' own process()) knows to stash the
	 * notification payload instead of letting it send immediately. See
	 * release_deferred_notifications().
	 */
	private ?int $suppressedNotificationsPaymentId = null;

	public function __construct(
		private WebhookContextStore $webhookContextStore,
	) {
	}

	public function is_notification_suppressed_for( int $paymentId ): bool {
		return $this->suppressedNotificationsPaymentId === $paymentId;
	}

	/**
	 * Persist the complete raw submission (every field, not just the payment-relevant ones
	 * IfthenpayFormData::build_fields_from_request() extracts) for a Pay By Link attempt, so
	 * create_entry_for_payment() can later replay it through WPForms' real submission pipeline
	 * — this is what lets the real entry (name, email, every answer) get created even when the
	 * confirmation that triggers it is an asynchronous webhook with no browser present at all.
	 */
	public function store_pending_entry_payload( int $payment_id, int $form_id, string $form_payload ): void {
		if ( $payment_id <= 0 || $form_payload === '' ) {
			return;
		}

		update_option(
			$this->pending_entry_payload_option_key( $payment_id ),
			[
				'form_id'      => $form_id,
				'form_payload' => $form_payload,
			],
			false
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_pending_entry_payload( int $payment_id ): array {
		if ( $payment_id <= 0 ) {
			return [];
		}

		$payload = get_option( $this->pending_entry_payload_option_key( $payment_id ), [] );

		return is_array( $payload ) ? $payload : [];
	}

	private function clear_pending_entry_payload( int $payment_id ): void {
		if ( $payment_id <= 0 ) {
			return;
		}

		delete_option( $this->pending_entry_payload_option_key( $payment_id ) );
	}

	private function pending_entry_payload_option_key( int $payment_id ): string {
		return 'iftp_pbl_entry_payload_' . $payment_id;
	}

	/**
	 * Create the real WPForms entry for a Pay By Link payment, using the raw submission captured
	 * when the link was generated (see store_pending_entry_payload()). Idempotent — a no-op if
	 * this payment already has an entry, which is the common case for every call except the very
	 * first: Process::ajax_create_pay_button_payment() now calls this immediately (with
	 * $deferNotifications = true) so the entry exists and shows up in the entries list right away
	 * as "pending", rather than waiting for payment confirmation. The later calls from
	 * Process::ajax_verify_payment() / WebhookHandler::handle_webhook_success() therefore just
	 * confirm the entry is there (or create it, for an older payment made before this behavior
	 * existed) — the notification emails deferred by that first call are what
	 * release_deferred_notifications() sends once the payment actually resolves.
	 *
	 * This drives WPForms' own wpforms()->obj('process')->process() — the exact same pipeline a
	 * live submission goes through (field validation/formatting for every field type, honeypot,
	 * spam filtering, notifications, confirmations) — rather than hand-building an entry, so
	 * behavior for any field type (including third-party ones) matches a normal submission.
	 * WPForms' own nonce check inside that pipeline only applies to logged-in users, so this
	 * works for a guest checkout confirmed days later without a valid nonce — the overwhelmingly
	 * common case for a public payment form.
	 *
	 * Known limitation: a file upload field cannot survive this — $form.serialize() (used to
	 * capture the original submission) never includes <input type="file">, so a required file
	 * field will fail validation on replay (no entry gets created; the payload option is left in
	 * place rather than silently dropped) and an optional one is simply missing from the entry.
	 * There is no fix for this short of capturing and durably storing the uploaded file itself at
	 * link-creation time.
	 */
	public function create_entry_for_payment( int $payment_id, bool $deferNotifications = false ): int {
		if ( $payment_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return 0;
		}

		// Process::ajax_create_pay_button_payment() (up front, deferred notifications) and
		// WebhookHandler::handle_webhook_success() (async, for older payments made before entries
		// were created up front) can both reach this method for the same payment_id — claim the
		// lock first so a race can't have both pass the entry_id-empty check below and create a
		// duplicate entry with duplicate notifications.
		if ( ! $this->acquire_entry_creation_lock( $payment_id ) ) {
			return 0;
		}

		try {
			return $this->create_entry_for_payment_unlocked( $payment_id, $deferNotifications );
		} finally {
			delete_option( $this->entry_creation_lock_key( $payment_id ) );
		}
	}

	/**
	 * Acquire the lock guarding create_entry_for_payment_unlocked() as a single INSERT into a
	 * UNIQUE-keyed option, not a transient's separate get-then-set. A transient's check and write
	 * are two round trips — two requests (the browser's ajax_verify_payment() poll and ifthenpay's
	 * handle_webhook_success() webhook) can both observe "unlocked" before either one writes,
	 * letting both replay the same submission and each create their own entry. add_option()
	 * fails atomically when the row already exists — enforced by the database's unique index, not
	 * by this code's own timing — so only one caller can ever win it.
	 */
	private function acquire_entry_creation_lock( int $payment_id ): bool {
		$lock_key = $this->entry_creation_lock_key( $payment_id );

		if ( add_option( $lock_key, time(), '', 'no' ) ) {
			return true;
		}

		// A lock this old can only be left over from a request that crashed or timed out before
		// reaching the finally{} cleanup above — a real in-progress replay finishes in well under
		// a second — so it's safe to reclaim rather than leave this payment permanently stuck.
		$existing = get_option( $lock_key );
		if ( is_numeric( $existing ) && (int) $existing < time() - 5 * MINUTE_IN_SECONDS ) {
			delete_option( $lock_key );
			return add_option( $lock_key, time(), '', 'no' );
		}

		return false;
	}

	private function entry_creation_lock_key( int $payment_id ): string {
		return 'iftp_pbl_entry_lock_' . $payment_id;
	}

	private function create_entry_for_payment_unlocked( int $payment_id, bool $deferNotifications = false ): int {
		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		if ( ! $payment ) {
			return 0;
		}

		// Already created by an earlier call (e.g. the browser confirmed it first and the webhook
		// is only now arriving) — idempotent, so simply hand back the existing id.
		if ( ! empty( $payment->entry_id ) ) {
			return (int) $payment->entry_id;
		}

		$payload = $this->get_pending_entry_payload( $payment_id );
		if ( empty( $payload['form_payload'] ) ) {
			// This payment never got a raw submission captured to replay.
			return 0;
		}

		$submitted = [];
		wp_parse_str( (string) $payload['form_payload'], $submitted );

		$entry_post = isset( $submitted['wpforms'] ) && is_array( $submitted['wpforms'] ) ? $submitted['wpforms'] : [];
		if ( empty( $entry_post ) ) {
			return 0;
		}

		$process = wpforms()->obj( 'process' );
		if ( ! $process || ! method_exists( $process, 'process' ) ) {
			return 0;
		}

		// Two behaviors of process() only make sense for a live HTTP request and must be
		// suspended for the duration of this replay:
		// - it treats a real, out-of-band POST (which technically this is, from admin-ajax.php
		//   or the fully-server-side webhook) to an ajax_submit-enabled form as spam, when Modern
		//   Anti-Spam is also on — legitimate here, since the payment webhook already vouches for
		//   this submission.
		// - a "Redirect"/"Page" confirmation type ends the request with wp_redirect()+exit — fatal
		//   inside an AJAX response or the webhook's own 200 OK — so the redirect URL is forced
		//   empty, which routes process() through its normal no-redirect (message) branch instead.
		$bypass_direct_post_check = static fn () => true;
		$suppress_confirmation_redirect = static fn () => '';
		// Only used when $deferNotifications is true — see the property's docblock and
		// release_deferred_notifications() for why a payment created as "pending" doesn't get
		// its notification emails yet.
		$suppress_entry_email = static fn () => false;

		add_filter( 'wpforms_process_anti_spam_direct_post_bypass', $bypass_direct_post_check );
		add_filter( 'wpforms_process_redirect_url', $suppress_confirmation_redirect );

		if ( $deferNotifications ) {
			add_filter( 'wpforms_entry_email', $suppress_entry_email );
			$this->suppressedNotificationsPaymentId = $payment_id;
		}

		// Reinstate, for the duration of this call only, the POST context a live submission
		// would have had — WPForms' process() reads $_POST throughout, requires action=wpforms_submit
		// when the form has AJAX submission enabled, and this plugin's own hooks
		// (PaymentDataPreparer::prepare_payment_data(), entry_saved_process()) resolve which
		// payment row to confirm via the same iftp_pbl_payment_id field a real browser submission
		// would post.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- snapshot/restore of superglobal state, not read as input.
		$original_post                = $_POST;
		$_POST['action']              = 'wpforms_submit';
		$_POST['wpforms']             = $entry_post;
		$_POST['iftp_pbl_payment_id'] = (string) $payment_id;

		try {
			$process->process( $entry_post );
		} finally {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- restoring the snapshot taken above.
			$_POST = $original_post;
			remove_filter( 'wpforms_process_anti_spam_direct_post_bypass', $bypass_direct_post_check );
			remove_filter( 'wpforms_process_redirect_url', $suppress_confirmation_redirect );

			if ( $deferNotifications ) {
				remove_filter( 'wpforms_entry_email', $suppress_entry_email );
				$this->suppressedNotificationsPaymentId = null;
			}
		}

		// entry_saved_process() (hooked on wpforms_process_entry_saved, fired inside process()
		// above) already wrote the freshly-created entry's id onto this payment row.
		$updated_payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		$entry_id        = $updated_payment && ! empty( $updated_payment->entry_id ) ? (int) $updated_payment->entry_id : 0;

		if ( $entry_id <= 0 ) {
			return 0;
		}

		$this->clear_pending_entry_payload( $payment_id );

		// Added as soon as the entry exists, regardless of whether its notification emails are
		// deferred — the Pay by Link URL is already known at this point, unlike ifthenpay's
		// request id (see add_ifthenpay_request_id_note()), which only exists once paid.
		$this->add_payment_reference_note( $payment_id, $entry_id );

		return $entry_id;
	}

	/**
	 * Stash the exact args WPForms' own entry_email() would have been called with (see
	 * PaymentDataPreparer::entry_saved_process(), which captures these from the
	 * wpforms_process_entry_saved action — the same values process() itself passes straight into
	 * entry_email() right after), so release_deferred_notifications() can send them later once
	 * the payment actually resolves.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $formData
	 */
	public function store_deferred_notification_payload( int $paymentId, array $fields, array $entry, array $formData, int $entryId ): void {
		update_option(
			$this->deferred_notification_option_key( $paymentId ),
			[
				'fields'    => $fields,
				'entry'     => $entry,
				'form_data' => $formData,
				'entry_id'  => $entryId,
			],
			false
		);
	}

	private function deferred_notification_option_key( int $paymentId ): string {
		return 'iftp_pbl_deferred_notify_' . $paymentId;
	}

	/**
	 * Send the notification emails that were held back when the entry was created up front at
	 * "pending" time (see create_entry_for_payment_unlocked()'s $deferNotifications). Called once
	 * the payment actually resolves to completed — only WebhookHandler::handle_webhook_success()
	 * ever calls this, since it's the only place a payment is ever marked "completed".
	 * A no-op if there's nothing stored (e.g. the entry wasn't created with notifications
	 * deferred in the first place, or this payment already had its notifications released).
	 */
	public function release_deferred_notifications( int $paymentId ): void {
		$optionKey = $this->deferred_notification_option_key( $paymentId );
		$payload   = get_option( $optionKey, null );

		if ( ! is_array( $payload ) || empty( $payload['entry_id'] ) || ! function_exists( 'wpforms' ) ) {
			return;
		}

		// Clear first — a duplicate/replay webhook landing while this is mid-flight must not be
		// able to fire the same notifications twice.
		delete_option( $optionKey );

		$process = wpforms()->obj( 'process' );
		if ( $process && method_exists( $process, 'entry_email' ) ) {
			$process->entry_email(
				(array) ( $payload['fields'] ?? [] ),
				(array) ( $payload['entry'] ?? [] ),
				(array) ( $payload['form_data'] ?? [] ),
				(int) $payload['entry_id'],
				'entry'
			);
		}
	}

	/**
	 * Leave a note on the entry with the Pay By Link URL that was generated for it
	 * (IfthenpayClient::create_payment_link()'s RedirectUrl, captured in
	 * WebhookContextStore::store()), so it can be looked up in ifthenpay's backoffice. Called as
	 * soon as the entry exists (see create_entry_for_payment_unlocked()) — the URL is already
	 * known at that point, unlike ifthenpay's request id (see add_ifthenpay_request_id_note()),
	 * which only exists once the payment actually resolves.
	 */
	private function add_payment_reference_note( int $payment_id, int $entry_id ): void {
		if ( $entry_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return;
		}

		$entry_meta = wpforms()->obj( 'entry_meta' );
		if ( ! $entry_meta || ! method_exists( $entry_meta, 'add' ) ) {
			return;
		}

		$context      = $this->webhookContextStore->get( $this->webhookContextStore->generate_pbl_ref( $payment_id ) );
		$redirect_url = is_array( $context ) ? (string) ( $context['redirect_url'] ?? '' ) : '';

		if ( $redirect_url === '' ) {
			return;
		}

		$note = sprintf(
			/* translators: %s: Pay By Link URL. */
			__( 'Pay by Link: %s', 'ifthenpay-payments-for-wpforms' ),
			esc_url_raw( $redirect_url )
		);

		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );

		$entry_meta->add(
			[
				'entry_id' => $entry_id,
				'form_id'  => $payment && isset( $payment->form_id ) ? (int) $payment->form_id : 0,
				'user_id'  => 0,
				'type'     => 'note',
				'data'     => wpautop( esc_html( $note ) ),
			],
			'entry_meta'
		);
	}

	/**
	 * Leave a note on the entry as soon as a transaction id is received from the browser on
	 * return from ifthenpay (see Process::record_received_transaction_id()) — before it's known
	 * whether WebhookHandler::confirm_via_transaction_status() can actually confirm the payment
	 * with it. Deliberately doesn't claim any outcome ("Paid" or otherwise): that's still added
	 * separately, only once confirmed, by add_ifthenpay_request_id_note() below. This note exists
	 * so the id is visible on the entry even when confirmation fails or only lands later via the
	 * webhook — otherwise a transaction id that never confirmed left no trace at all.
	 */
	public function add_ifthenpay_transaction_id_received_note( int $payment_id, string $transaction_id ): void {
		if ( $transaction_id === '' || ! function_exists( 'wpforms' ) ) {
			return;
		}

		$entry_meta = wpforms()->obj( 'entry_meta' );
		if ( ! $entry_meta || ! method_exists( $entry_meta, 'add' ) ) {
			return;
		}

		$payment  = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		$entry_id = $payment && ! empty( $payment->entry_id ) ? (int) $payment->entry_id : 0;

		if ( $entry_id <= 0 ) {
			return;
		}

		$note = sprintf(
			/* translators: %s: ifthenpay transaction id. */
			__( 'ifthenpay transaction ID received: %s (verifying payment status)', 'ifthenpay-payments-for-wpforms' ),
			$transaction_id
		);

		$entry_meta->add(
			[
				'entry_id' => $entry_id,
				'form_id'  => isset( $payment->form_id ) ? (int) $payment->form_id : 0,
				'user_id'  => 0,
				'type'     => 'note',
				'data'     => wpautop( esc_html( $note ) ),
			],
			'entry_meta'
		);
	}

	/**
	 * Leave a note on the entry with ifthenpay's own request id for this payment, once the
	 * webhook has confirmed it (see WebhookHandler::handle_webhook_success()) — a reference for
	 * looking the payment up in ifthenpay's backoffice, distinct from the Pay by Link note added
	 * at entry-creation time. Also leaves a second note stating the paid amount, so the entry
	 * notes make it obvious at a glance that ifthenpay confirmed payment, and for how much.
	 */
	public function add_ifthenpay_request_id_note( int $payment_id, string $request_id, float $amount ): void {
		if ( $request_id === '' || ! function_exists( 'wpforms' ) ) {
			return;
		}

		$entry_meta = wpforms()->obj( 'entry_meta' );
		if ( ! $entry_meta || ! method_exists( $entry_meta, 'add' ) ) {
			return;
		}

		$payment  = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		$entry_id = $payment && ! empty( $payment->entry_id ) ? (int) $payment->entry_id : 0;

		if ( $entry_id <= 0 ) {
			return;
		}

		$form_id = isset( $payment->form_id ) ? (int) $payment->form_id : 0;

		$request_id_note = sprintf(
			/* translators: %s: ifthenpay request id. */
			__( 'ifthenpay request ID: %s', 'ifthenpay-payments-for-wpforms' ),
			$request_id
		);

		$entry_meta->add(
			[
				'entry_id' => $entry_id,
				'form_id'  => $form_id,
				'user_id'  => 0,
				'type'     => 'note',
				'data'     => wpautop( esc_html( $request_id_note ) ),
			],
			'entry_meta'
		);

		$formatted_amount = function_exists( 'wpforms_format_amount' ) ? wpforms_format_amount( $amount, true ) : number_format( $amount, 2 );

		$paid_note = sprintf(
			/* translators: 1: ifthenpay gateway label, 2: formatted paid amount. */
			__( '%1$s - %2$s - Paid', 'ifthenpay-payments-for-wpforms' ),
			IFTP_PBL_GATEWAY_LABEL,
			$formatted_amount
		);

		$entry_meta->add(
			[
				'entry_id' => $entry_id,
				'form_id'  => $form_id,
				'user_id'  => 0,
				'type'     => 'note',
				'data'     => wpautop( esc_html( $paid_note ) ),
			],
			'entry_meta'
		);
	}
}
