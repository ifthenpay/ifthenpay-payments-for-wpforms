<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\WPForms\Process;

use Ifthenpay\WPForms\Admin\Settings;
use Ifthenpay\WPForms\Api\Ifthenpay\IfthenpayFormData;
use Ifthenpay\WPForms\Api\Ifthenpay\IfthenpayPayload;
use Ifthenpay\WPForms\Api\Ifthenpay\IfthenpayReturn;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Hooks into WPForms' own submission/entry lifecycle (wpforms_forms_submission_prepare_*,
 * wpforms_process_*) to attach and confirm this gateway's payment data on a WPForms payment
 * row and entry, without ever marking a payment "completed" from client-supplied data — only
 * WebhookHandler::handle_webhook_success() may do that.
 */
class PaymentDataPreparer
{
	/** @var array<string, mixed>|null */
	private ?array $resolved_context = null;

	public function __construct(
		private PaymentRecordStore $paymentRecordStore,
		private EntryManager $entryManager,
	) {
	}

	/**
	 * @param array<string, mixed> $paymentData
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $formData
	 * @return array<string, mixed>
	 */
	public function prepare_payment_data($paymentData, $fields, $formData): array
	{
		if (empty(Settings::get_form_payment_settings($formData)['enabled'])) {
			return $paymentData;
		}

		$amount = IfthenpayFormData::resolve_amount($fields);

		if ($amount <= 0) {
			$paymentData['gateway'] = 'none';
			$this->stamp_payment_data($paymentData, $fields);
			return $paymentData;
		}

		// A real, pending WPForms payment row was already created up front when the Pay By
		// Link was generated (see Process::handle_process()) — WPForms must not insert a second
		// row for the same attempt, so gateway always stays blank here regardless of outcome. That
		// existing row is confirmed below, and independently by the webhook.
		$paymentData['gateway'] = '';

		$context = $this->get_context($fields);
		$paymentMethod = (string) ($context['payment_method'] ?? '');
		$isSuccessful = !empty($context['successful']);

		$paymentId = $this->paymentRecordStore->get_posted_payment_id();
		if ($paymentId > 0 && $isSuccessful && $paymentMethod !== '') {
			$this->sync_confirmed_payment_method($paymentId, $paymentMethod);
		}

		return $paymentData;
	}

	/**
	 * @param array<string, mixed> $paymentData
	 * @param array<string, mixed> $fields
	 */
	private function stamp_payment_data(array &$paymentData, array $fields): void
	{
		$paymentData['type']        = 'one-time';
		$paymentData['mode']        = isset($paymentData['mode']) && $paymentData['mode'] !== '' ? sanitize_text_field($paymentData['mode']) : 'live';
		$paymentData['status']      = 'completed';
		$name                       = self::extract_name($fields);
		$paymentData['title']       = $name !== '' ? $name : 'UserID: ' . IfthenpayPayload::generate_customer_id() . ' Purchase';
		$paymentData['customer_id'] = IfthenpayPayload::generate_customer_id();
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return string
	 */
	public static function extract_name(array $fields): string
	{
		foreach ($fields as $field) {
			if (!is_array($field) || ($field['type'] ?? '') !== 'name') {
				continue;
			}

			$value = $field['value'] ?? '';
			if (is_array($value)) {
				$first = trim((string) ($value['first'] ?? ''));
				$last = trim((string) ($value['last'] ?? ''));
				$name = trim($first . ' ' . $last);

				if ($name !== '') {
					return $name;
				}
			}

			$name = trim(wp_strip_all_tags((string) $value));
			if ($name !== '') {
				return $name;
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $paymentMeta
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $formData
	 * @return array<string, mixed>
	 */
	public function prepare_payment_meta($paymentMeta, $fields, $formData): array
	{
		$paymentMeta = is_array($paymentMeta) ? $paymentMeta : [];
		$fields = is_array($fields) ? $fields : [];
		$formData = is_array($formData) ? $formData : [];

		if (empty(Settings::get_form_payment_settings($formData)['enabled'])) {
			return $paymentMeta;
		}

		$returnData = IfthenpayReturn::get_pay_now_return_data_from_request();
		$context = $this->get_context($fields);

		$paymentMethod = (string) $context['payment_method'];
		$customerName = self::extract_name($fields);

		if ($paymentMethod !== '') {
			$paymentMeta['method_type'] = $paymentMethod;
		}

		if ($customerName !== '') {
			$paymentMeta['customer_name'] = $customerName;
		}

		// A client-reported "success" return is not proof of payment (see
		// IfthenpayReturn::is_successful_pay_now_return()'s docblock) — this log entry is
		// purely descriptive of what the browser reported, never a claim that the payment has
		// actually been confirmed. Only WebhookHandler::handle_webhook_success() may ever mark a
		// payment "completed".
		$paymentMeta['log'] = wp_json_encode([
			'value' => IfthenpayReturn::is_successful_pay_now_return($returnData)
				? __('ifthenpay pay-by-link return received (pending confirmation).', 'ifthenpay-payments-for-wpforms')
				: __('ifthenpay pay-by-link initialized.', 'ifthenpay-payments-for-wpforms'),
			'date' => gmdate('Y-m-d H:i:s'),
		]);

		return $paymentMeta;
	}

	public function payment_check_process(array $errors, array $formData): array
	{
		$formData = is_array($formData) ? $formData : [];

		if (false === wpforms_has_field_type(IFTP_PBL_FIELD_TYPE, $formData, false)) {
			return $errors;
		}

		$reason = Settings::get_unusable_reason($formData);
		$formId = isset($formData['id']) ? (int) $formData['id'] : 0;

		if ($reason === '') {
			$returnData = IfthenpayReturn::get_pay_now_return_data_from_request();
			$fields = isset($formData['fields']) && is_array($formData['fields']) ? $formData['fields'] : [];
			$context = $this->get_context($fields);
			$shouldBlock = !empty($returnData) && IfthenpayReturn::should_block_pay_now_return($returnData, $context);

			if ($shouldBlock) {
				$errors[$formId] = [
					'header' => __('Payment Failed', 'ifthenpay-payments-for-wpforms'),
				];
			}
			return $errors;
		}

		$errors[$formId] = [
			'header' => $reason,
		];

		return $errors;
	}

	/**
	 * Persist final payment details after WPForms creates the payment row.
	 * @param int $paymentId
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $formData
	 * @return void
	 */
	public function payment_saved_process(int $paymentId, array $fields, array $formData): void
	{
		// With the payment row now always created up front (see Process::handle_process()),
		// WPForms' own insert — and this action — is skipped entirely for real ifthenpay Pay By
		// Link attempts (prepare_payment_data() always blanks "gateway" for them). This remains
		// registered only as a safety net for any submission path that still reaches WPForms'
		// own payment insert with this gateway attached.
		if ($paymentId <= 0 || false === wpforms_has_field_type(IFTP_PBL_FIELD_TYPE, $formData, false)) {
			return;
		}

		$context = $this->get_context($fields);
		$paymentMethod = (string) $context['payment_method'];

		$this->sync_confirmed_payment_method($paymentId, $paymentMethod);
	}

	/**
	 * Link WPForms Pro entries with the payment row created during submission.
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $formData
	 * @param int $entryId
	 * @param int $paymentId
	 * @return void
	 */
	public function entry_saved_process(array $fields, array $entry, array $formData, int $entryId, int $paymentId): void
	{
		if ($entryId <= 0 || false === wpforms_has_field_type(IFTP_PBL_FIELD_TYPE, $formData, false)) {
			return;
		}

		// WPForms only creates its own payment row (and passes a non-zero $paymentId here) when
		// our own row wasn't already in play. Every real ifthenpay Pay By Link attempt already
		// has its row created up front (see Process::handle_process()), so resolve it the same way
		// the rest of this class does rather than trusting WPForms' own $paymentId.
		$resolvedFromPostedId = $paymentId <= 0;
		if ($resolvedFromPostedId) {
			$paymentId = $this->paymentRecordStore->get_posted_payment_id();
		}

		if ($paymentId <= 0) {
			return;
		}

		// The posted payment_id is a plain client-editable hidden field — unlike a fresh id
		// WPForms itself just inserted moments ago in this same request, it can't be trusted on
		// its own to target a row for mutation without first confirming it's actually ours.
		if ($resolvedFromPostedId && ! $this->paymentRecordStore->owns_wpforms_payment($paymentId)) {
			return;
		}

		$context = $this->get_context($fields);
		$paymentMethod = (string) $context['payment_method'];

		$this->paymentRecordStore->update_payment_row($paymentId, ['entry_id' => $entryId]);

		if ($this->entryManager->is_notification_suppressed_for($paymentId)) {
			$this->entryManager->store_deferred_notification_payload($paymentId, $fields, $entry, $formData, $entryId);
		}

		$this->sync_confirmed_payment_method($paymentId, $paymentMethod);

		$this->paymentRecordStore->update_stored_payment_summary(
			$paymentId,
			[
				'entry_id' => $entryId,
				'form_id' => isset($formData['id']) ? (int) $formData['id'] : 0,
			]
		);

		$this->paymentRecordStore->clear_stored_payment_summary( $paymentId );
	}

	/**
	 * Sync the payment method label onto a WPForms payment row / entry meta from a
	 * client-observed return signal. This never marks a payment "completed" — only
	 * WebhookHandler::handle_webhook_success() may do that, since it's the only source that
	 * independently verifies the payment with ifthenpay (anti-phishing key + amount match). A
	 * client-supplied "successful" signal proves nothing on its own; see
	 * IfthenpayReturn::is_successful_pay_now_return()'s docblock.
	 */
	private function sync_confirmed_payment_method(int $paymentId, string $paymentMethod): void
	{
		if ($paymentMethod !== '') {
			$this->paymentRecordStore->update_payment_method($paymentId, $paymentMethod);
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $formData
	 * @param int $entryId
	 * @return void
	 */
	public function payment_confirmation_process(array $fields, array $entry, array $formData, int $entryId): void
	{
		unset($entry);

		if (false === wpforms_has_field_type(IFTP_PBL_FIELD_TYPE, $formData, false)) {
			return;
		}

		$paymentId = $this->paymentRecordStore->get_posted_payment_id();
		$context = $this->get_context($fields);

		// Same reasoning as entry_saved_process(): a posted payment_id is client-editable, so
		// confirm it actually names a row this gateway owns before writing to it.
		if ($paymentId > 0 && ! $this->paymentRecordStore->owns_wpforms_payment($paymentId)) {
			return;
		}

		if ($paymentId > 0 && function_exists('wpforms')) {
			$paymentMeta = wpforms()->get('payment_meta');
			if ($paymentMeta) {
				$paymentMethod = (string) $context['payment_method'];
				if ($paymentMethod !== '') {
					$paymentMeta->update_or_add($paymentId, 'method_type', $paymentMethod);
				} else {
					return;
				}
			}

			if ($entryId > 0) {
				$this->paymentRecordStore->update_payment_row($paymentId, ['entry_id' => $entryId]);
			}
		}
	}

	private function get_context(array $fields): array
	{
		if ($this->resolved_context !== null) {
			return $this->resolved_context;
		}

		$payment_id = $this->paymentRecordStore->get_posted_payment_id();

		// 'verified_successful' is only ever written by WebhookHandler::handle_webhook_success()
		// — the only place that independently confirms a payment with ifthenpay (anti-phishing
		// key + amount match) — so this fast path is trustworthy. See
		// get_pay_now_return_data_from_request() below for the untrusted, client-reported
		// fallback used before the webhook has fired.
		if ($payment_id > 0) {
			$summary = $this->paymentRecordStore->get_stored_payment_summary( $payment_id );
			if (!empty($summary['verified_successful'])) {
				return $this->resolved_context = [
					'payment_method' => (string) ($summary['verified_payment_method'] ?? ''),
					'successful'     => true,
				];
			}
		}

		// Last resort: legacy return-URL path. This context is derived entirely from
		// client-supplied $_GET/$_POST return params — see
		// IfthenpayReturn::is_successful_pay_now_return()'s docblock — and must never be
		// treated as proof of payment by any caller of get_context().
		$returnData = IfthenpayReturn::get_pay_now_return_data_from_request();
		return $this->resolved_context = IfthenpayReturn::resolve_return_context($returnData);
	}
}
