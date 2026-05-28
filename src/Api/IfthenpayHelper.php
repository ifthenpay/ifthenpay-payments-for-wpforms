<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Utility helpers for ifthenpay WPForms integration.
 */
final class IfthenpayHelper {
	private const PAYMENT_METHOD_KEYS = ['payment_method', 'paymentMethod', 'PaymentMethod', 'method', 'method_type', 'Method'];
	private const SUCCESS_RETURN_STATUSES = ['success', 'completed', 'paid', 'ok'];

	/**
	 * Build pay-by-link payload.
	 */
	public static function build_pay_by_link_payload(array $args): array {

		$id          = (string) ($args['id'] ?? '');
		$description = sanitize_text_field($args['description'] ?? '');

		$payload = [
			'id'          => $id,
			'amount'      => self::formatAmount($args['amount'] ?? 0),
			'description' => self::build_description($id, $description),
			'accounts'    => (string) ($args['accounts'] ?? ''),
			'success_url' => $args['success_url'] ?? '',
			'error_url'   => $args['error_url'] ?? '',
			'cancel_url'  => $args['cancel_url'] ?? '',
			'otp'         => 'true',
			'lang'        => self::map_locale_to_lang(
				(string) ($args['locale'] ?? get_locale())
			),
		];

		foreach (['selected_method', 'email', 'name', 'fields'] as $field) {

			if (empty($args[$field])) {
				continue;
			}

			$payload[$field] = $args[$field];
		}

		return $payload;
	}

	/**
	 * Map WP locale to ifthenpay language code.
	 */
	public static function map_locale_to_lang(string $locale): string {

		return match (substr(strtolower($locale), 0, 2)) {
			'pt', 'es', 'fr' => substr(strtolower($locale), 0, 2),
			default => 'en',
		};
	}

	/**
	 * Format amount.
	 */
	public static function format_amount(
		int|float|string $amount,
		int $decimals = 2
	): string {
		return self::formatAmount($amount, $decimals, ' ');
	}

	/**
	 * Format amount with custom thousands separator.
	 */
	public static function formatAmount(
		float|int|string $amount,
			int $decimals = 2,
			string $thousands_separator = ''
		): string {
		if ( ! is_numeric( $amount ) ) {
			return (string) $amount;
		}

		return number_format(
			(float) $amount,
			max( 0, $decimals ),
			'.',
			$thousands_separator
		);
	}

	/**
	 * Build gateway return URLs for a reserved WPForms payment ID.
	 *
	 * @return array<string, string>
	 */
	public static function build_gateway_urls(int $payment_id, string $base_url): array {
		return array(
			'success_url'  => add_query_arg( array( 'wpforms_pay' => 'success', 'id' => $payment_id, 'transaction_id' => '[TRANSACTIONID]', 'iftp_gateway' => 1 ), $base_url ),
			'error_url'    => add_query_arg( array( 'wpforms_pay' => 'error', 'id' => $payment_id, 'transaction_id' => '[TRANSACTIONID]', 'iftp_gateway' => 1 ), $base_url ),
			'cancel_url'   => add_query_arg( array( 'wpforms_pay' => 'cancel', 'id' => $payment_id, 'transaction_id' => '[TRANSACTIONID]', 'iftp_gateway' => 1 ), $base_url ),
			'callback_url' => add_query_arg( array( 'iftp_pbl_callback' => 1 ), home_url( '/' ) ),
		);
	}

	/**
	 * Build the frontend session data returned after Pay By Link creation.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_pay_by_link_session(
		int $payment_id,
		string $redirect_url,
		bool $use_popup,
		string $token,
		string $return_url
	): array {
		return [
			'payment_id'            => $payment_id,
			'iframe_url'            => $redirect_url,
			'local_iframe_url'      => add_query_arg(['iftp_pbl_pay' => 1, 'token' => $token], home_url('/')),
			'redirect_url'          => $redirect_url,
			'use_popup'             => $use_popup,
			'modal_token'           => $token,
			'return_url'            => $return_url,
			'hide_wpforms_pay_button' => true,
			'payment_session_token' => $token,
		];
	}

	/**
	 * Build a compact status response for frontend verification.
	 *
	 * @return array<string, string>
	 */
	public static function build_payment_status_response(
		string $status,
		string $transaction_id = '',
		string $payment_method = ''
	): array {
		$response = ['status' => $status];

		if ($transaction_id !== '') {
			$response['transaction_id'] = $transaction_id;
		}

		if ($payment_method !== '') {
			$response['payment_method'] = $payment_method;
		}

		return $response;
	}

	/**
	 * Resolve total amount from processed WPForms fields.
	 *
	 * @param array<string, mixed> $fields
	 */
	public static function resolve_amount(array $fields): float {
		$total = 0.0;
		$payment_total_field = null;

		foreach ($fields as $field) {
			if (!is_array($field)) {
				continue;
			}

			if (($field['type'] ?? '') === 'payment-total') {
				$payment_total_field = $field;
				continue;
			}

			if (isset($field['amount_raw']) && is_numeric($field['amount_raw'])) {
				$total += (float) $field['amount_raw'];
			}
		}

		if (is_array($payment_total_field) && isset($payment_total_field['amount_raw']) && is_numeric($payment_total_field['amount_raw'])) {
			$payment_total_amount = (float) $payment_total_field['amount_raw'];
			if ($payment_total_amount < $total || $total === 0.0) {
				$total = $payment_total_amount;
			}
		} elseif ($total <= 0 && function_exists('wpforms_get_total_payment')) {
			$wpforms_total = wpforms_get_total_payment($fields);
			if (is_numeric($wpforms_total)) {
				$total = (float) $wpforms_total;
			}
		}

		return max(0.0, $total);
	}

	/**
	 * Build WPForms field data from serialized frontend form payload.
	 *
	 * @param array<string, mixed> $form_data
	 * @return array<string, mixed>
	 */
	public static function build_fields_from_request(array $form_data, string $form_payload): array {
		$submitted = [];
		if ($form_payload !== '') {
			wp_parse_str($form_payload, $submitted);
		}

		$wpforms_data = [];
		if (isset($submitted['wpforms']) && is_array($submitted['wpforms'])) {
			$wpforms_data = map_deep($submitted['wpforms'], 'sanitize_text_field');
		}

		$submitted_fields = isset($wpforms_data['fields']) && is_array($wpforms_data['fields']) ? $wpforms_data['fields'] : [];
		$submitted_quantities = isset($wpforms_data['quantities']) && is_array($wpforms_data['quantities']) ? $wpforms_data['quantities'] : [];
		$fields = [];
		$coupon_fields = [];
		$total_amount = 0.0;
		$currency = function_exists('wpforms_get_currency') ? wpforms_get_currency() : 'EUR';

		foreach ((array) ($form_data['fields'] ?? []) as $field_id => $field) {
			if (!is_array($field)) {
				continue;
			}

			$type = isset($field['type']) ? (string) $field['type'] : '';
			if (!in_array($type, ['email', 'name', 'payment-single', 'payment-multiple', 'payment-checkbox', 'payment-select', 'payment-coupon', 'coupon', 'payment-total'], true)) {
				continue;
			}

			$submitted_value = $submitted_fields[$field_id] ?? '';
			$name = isset($field['label']) ? sanitize_text_field((string) $field['label']) : '';
			$field_data = [
				'name'     => $name !== '' ? $name : sprintf('Field #%d', (int) $field_id),
				'id'       => absint((int) $field_id),
				'type'     => sanitize_key($type),
				'currency' => $currency,
			];

			if ($type === 'email') {
				$email = sanitize_email(is_array($submitted_value) ? '' : (string) $submitted_value);
				if ($email !== '') {
					$field_data['value'] = $email;
					$fields[$field_id] = $field_data;
				}
				continue;
			}

			if ($type === 'name') {
				if (is_array($submitted_value)) {
					$field_data['value'] = [
						'first' => sanitize_text_field($submitted_value['first'] ?? ''),
						'last'  => sanitize_text_field($submitted_value['last'] ?? ''),
					];
				} else {
					$field_data['value'] = sanitize_text_field((string) $submitted_value);
				}
				$fields[$field_id] = $field_data;
				continue;
			}

			if ($type === 'payment-total') {
				continue;
			}

			if (in_array($type, ['payment-coupon', 'coupon'], true)) {
				$coupon_code = is_array($submitted_value) ? '' : trim(sanitize_text_field((string) $submitted_value));
				$field_data['value'] = $coupon_code;
				$field_data['amount'] = function_exists('wpforms_format_amount') ? wpforms_format_amount(0) : '0.00';
				$field_data['amount_raw'] = 0;
				$fields[$field_id] = $field_data;
				$coupon_fields[$field_id] = [
					'code'  => $coupon_code,
					'field' => $field_data,
				];
				continue;
			}

			$quantity = self::get_submitted_quantity($field, $submitted_quantities, $field_id, $form_data);
			$amount = 0.0;
			$value = '';

			if ($type === 'payment-single') {
				$is_user_defined = empty($field['price']) || (isset($field['format']) && (string) $field['format'] === 'user');
				if ($is_user_defined) {
					$amount = function_exists('wpforms_sanitize_amount') ? (float) wpforms_sanitize_amount(is_array($submitted_value) ? '' : (string) $submitted_value) : (float) $submitted_value;
				} else {
					$amount = function_exists('wpforms_sanitize_amount') ? (float) wpforms_sanitize_amount((string) ($field['price'] ?? '0')) : (float) ($field['price'] ?? 0);
				}

				$value = function_exists('wpforms_format_amount') ? wpforms_format_amount($amount, true) : (string) $amount;
				if ($quantity > 1) {
					$field_data['quantity'] = $quantity;
				}
			} elseif ($type === 'payment-select' || $type === 'payment-multiple') {
				$choice_key = is_array($submitted_value) ? '' : sanitize_key((string) $submitted_value);
				if (isset($field['choices'][$choice_key]['value'])) {
					$amount = function_exists('wpforms_sanitize_amount') ? (float) wpforms_sanitize_amount((string) $field['choices'][$choice_key]['value']) : (float) ($field['choices'][$choice_key]['value'] ?? 0);
					$choice_label = isset($field['choices'][$choice_key]['label']) ? sanitize_text_field((string) $field['choices'][$choice_key]['label']) : '';
					$value = $choice_label !== '' && function_exists('wpforms_format_amount')
						? $choice_label . ' - ' . wpforms_format_amount($amount, true)
						: ($choice_label !== '' ? $choice_label . ' - ' . $amount : (string) $amount);
				}

				if ($quantity > 1) {
					$field_data['quantity'] = $quantity;
				}
			} elseif ($type === 'payment-checkbox') {
				$selected_choices = is_array($submitted_value) ? $submitted_value : [$submitted_value];
				$choice_values = [];

				foreach ($selected_choices as $choice_key) {
					$choice_key = sanitize_key((string) $choice_key);
					if ($choice_key === '' || empty($field['choices'][$choice_key])) {
						continue;
					}

					$choice_amount = function_exists('wpforms_sanitize_amount') ? (float) wpforms_sanitize_amount((string) ($field['choices'][$choice_key]['value'] ?? '0')) : (float) ($field['choices'][$choice_key]['value'] ?? 0);
					$amount += $choice_amount;
					$choice_values[] = function_exists('wpforms_format_amount') ? wpforms_format_amount($choice_amount, true) : (string) $choice_amount;
				}

				$value = !empty($choice_values) ? implode("\r\n", $choice_values) : '';

				if ($quantity > 1) {
					$field_data['quantity'] = $quantity;
				}
			}

			$amount *= max(1, $quantity);
			$total_amount += $amount;

			$field_data['value'] = $value !== '' ? $value : (function_exists('wpforms_format_amount') ? wpforms_format_amount($amount, true) : (string) $amount);
			$field_data['amount'] = function_exists('wpforms_format_amount') ? wpforms_format_amount($amount) : (string) $amount;
			$field_data['amount_raw'] = $amount;

			$fields[$field_id] = $field_data;
		}

		$form_id = isset($form_data['id']) ? (int) $form_data['id'] : 0;

		foreach ($coupon_fields as $field_id => $coupon_data) {
			$coupon_code = (string) $coupon_data['code'];
			if ($coupon_code === '' || !isset($fields[$field_id]) || !is_array($fields[$field_id])) {
				continue;
			}

			$discount_amount = self::resolve_coupon_discount_amount($coupon_code, $form_id, $total_amount);
			$fields[$field_id]['amount'] = function_exists('wpforms_format_amount') ? wpforms_format_amount($discount_amount * -1) : (string) ($discount_amount * -1);
			$fields[$field_id]['amount_raw'] = $discount_amount * -1;
			$total_amount = max(0.0, $total_amount - $discount_amount);
		}

		$fields['payment_total'] = [
			'name'       => 'Total',
			'value'      => function_exists('wpforms_format_amount') ? wpforms_format_amount($total_amount, true) : (string) $total_amount,
			'amount'     => function_exists('wpforms_format_amount') ? wpforms_format_amount($total_amount) : (string) $total_amount,
			'amount_raw' => $total_amount,
			'currency'   => $currency,
			'id'         => 0,
			'type'       => 'payment-total',
		];

		return $fields;
	}

	/**
	 * Load WPForms form data for frontend AJAX requests.
	 *
	 * @return array{form_data: array<string, mixed>, error: string}
	 */
	public static function load_form_data(int $form_id): array {
		$form_data = [];
		$load_error = '';

		if (function_exists('wpforms') && is_object(wpforms()) && method_exists(wpforms(), 'form')) {
			try {
				$form = wpforms()->form->get($form_id);

				if ($form && !empty($form->post_content)) {
					$decoded = wpforms_decode($form->post_content);
					if (is_array($decoded)) {
						$form_data = $decoded;
					} else {
						$load_error = 'wpforms_decode failed';
					}
				} else {
					$load_error = 'form object or post_content empty';
				}
			} catch (\Throwable $e) {
				$load_error = 'wpforms()->form->get() exception: ' . $e->getMessage();
			}
		} else {
			$load_error = 'wpforms() function not available';
		}

		if (!empty($form_data)) {
			return [
				'form_data' => $form_data,
				'error'     => $load_error,
			];
		}

		try {
			$form_post = get_post($form_id);

			if ($form_post && !empty($form_post->post_content)) {
				$decoded = wpforms_decode($form_post->post_content);
				if (is_array($decoded)) {
					$form_data = $decoded;
				} else {
					$load_error .= '; wpforms_decode failed on db query';
				}
			} else {
				$load_error .= '; form_post empty from WordPress post cache';
			}
		} catch (\Throwable $e) {
			$load_error .= '; get_post exception: ' . $e->getMessage();
		}

		return [
			'form_data' => is_array($form_data) ? $form_data : [],
			'error'     => $load_error,
		];
	}

	/**
	 * Resolve enabled payment accounts into the API "A|B;C|D" format.
	 *
	 * @param array<string, mixed> $methods_config
	 */
	public static function build_accounts_string(array $methods_config): string {
		$parts = [];

		foreach ($methods_config as $method) {
			if (empty($method['enabled'])) {
				continue;
			}

			$account = isset($method['account']) ? trim((string) $method['account']) : '';
			if ($account === '') {
				continue;
			}

			$parts[] = preg_replace('/\s*\|\s*/', '|', $account);
		}

		return implode(';', array_values(array_filter($parts, static fn ($value) => is_string($value) && $value !== '')));
	}

	/**
	 * Resolve a selected method entity from config and ifthenpay method metadata.
	 *
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $methods_config
	 */
	public static function get_selected_method_entity(array $config, array $methods_config): string {
		$selected_code = self::get_selected_method_code($config, $methods_config);

		foreach (self::get_available_methods_safe() as $method) {
			if (empty($method['Position']) || empty($method['Entity'])) {
				continue;
			}

			if ((string) $method['Position'] === $selected_code) {
				return strtoupper((string) $method['Entity']);
			}
		}

		return '';
	}

	/**
	 * Resolve selected method position used by the Pay By Link API.
	 *
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $methods_config
	 */
	public static function get_selected_method_code(array $config, array $methods_config): string {
		$map = [];

		foreach (self::get_available_methods_safe() as $method) {
			if (empty($method['Entity']) || !isset($method['Position'])) {
				continue;
			}

			$entity = strtoupper((string) $method['Entity']);
			$map[$entity] = (string) $method['Position'];
		}

		if (empty($map)) {
			return '';
		}

		$entity = '';

		if (!empty($config['default_method'])) {
			$entity = strtoupper((string) $config['default_method']);
		}

		if (!empty($config['gateway_key'])) {
			$gateway_key = (string) $config['gateway_key'];

			if (
				!empty($config['gateway_methods'][$gateway_key]['default_method']) &&
				is_string($config['gateway_methods'][$gateway_key]['default_method'])
			) {
				$entity = strtoupper((string) $config['gateway_methods'][$gateway_key]['default_method']);
			}
		}

		if ($entity !== '' && isset($methods_config[$entity]) && !empty($methods_config[$entity]['enabled'])) {
			return $map[$entity] ?? (string) reset($map);
		}

		$enabled = [];
		foreach ($methods_config as $ent => $data) {
			if (!empty($data['enabled'])) {
				$enabled[] = strtoupper((string) $ent);
			}
		}

		if (empty($enabled)) {
			return (string) reset($map);
		}

		$best = null;
		$best_pos = PHP_INT_MAX;

		foreach ($enabled as $ent) {
			if (isset($map[$ent])) {
				$pos = (int) $map[$ent];
				if ($pos < $best_pos) {
					$best_pos = $pos;
					$best = $ent;
				}
			}
		}

		if ($best !== null) {
			return $map[$best];
		}

		foreach ($map as $ent => $pos) {
			if (in_array($ent, $enabled, true)) {
				return $pos;
			}
		}

		return (string) reset($map);
	}

	/**
	 * Resolve display mode from field settings first, then gateway settings.
	 *
	 * @param array<string, mixed> $form_data
	 * @param array<string, mixed> $config
	 */
	public static function resolve_display_mode(array $form_data, array $config): string {
		foreach ((array) ($form_data['fields'] ?? []) as $field) {
			if (!is_array($field) || (string) ($field['type'] ?? '') !== 'iftp_pbl_field') {
				continue;
			}

			$display_mode = isset($field['display_mode']) ? strtolower(trim((string) $field['display_mode'])) : 'modal';
			return in_array($display_mode, ['modal', 'popup'], true) ? $display_mode : 'modal';
		}

		$display_mode = isset($config['display_mode']) ? strtolower(trim((string) $config['display_mode'])) : 'modal';
		return in_array($display_mode, ['modal', 'popup'], true) ? $display_mode : 'modal';
	}

	/**
	 * Read configured methods for a selected gateway.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public static function get_gateway_methods_config(array $config, string $gateway_key): array {
		if (
			$gateway_key !== '' &&
			!empty($config['gateway_methods'][$gateway_key]['methods']) &&
			is_array($config['gateway_methods'][$gateway_key]['methods'])
		) {
			return $config['gateway_methods'][$gateway_key]['methods'];
		}

		if (!empty($config['methods']) && is_array($config['methods'])) {
			return $config['methods'];
		}

		return [];
	}

	/**
	 * Generate a customer identifier based on login state.
	 */
	public static function generate_customer_id(): string {
		return is_user_logged_in() ? (string) get_current_user_id() : 'guest';
	}

	/**
	 * Find first field matching a specific WPForms field type.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public static function find_field_by_type(array $fields, string $field_type): array {
		foreach ($fields as $field) {
			if (is_array($field) && (string) ($field['type'] ?? '') === $field_type) {
				return $field;
			}
		}

		return [];
	}

	/**
	 * Extract and format a payment summary for temporary WPForms payment storage.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $form_data
	 * @return array<string, mixed>
	 */
	public static function extract_payment_summary(array $fields, array $entry, array $form_data, string $gateway, string $method): array {
		$coupon_fields = self::get_coupon_fields($fields);
		$subtotal_fields = array_diff_key($fields, $coupon_fields);
		unset($subtotal_fields['payment_total']);

		$subtotal_amount = function_exists('wpforms_get_total_payment') ? wpforms_get_total_payment($subtotal_fields) : 0.0;
		$subtotal_amount = false === $subtotal_amount ? 0.0 : (float) $subtotal_amount;
		$total_amount = self::resolve_amount($fields);
		$discount_amount = max(0.0, $subtotal_amount - $total_amount);

		$line_items = [];
		foreach ($fields as $field) {
			if (!is_array($field)) {
				continue;
			}

			$type = isset($field['type']) ? (string) $field['type'] : '';
			$name = isset($field['name']) ? trim((string) $field['name']) : '';
			$raw_value = $field['value'] ?? '';

			if (is_array($raw_value)) {
				$raw_value = implode(', ', $raw_value);
			}

			$value = trim(wp_strip_all_tags((string) $raw_value));
			if ($value === '') {
				continue;
			}

			if (in_array($type, ['payment-single', 'payment-checkbox', 'payment-multiple', 'payment-select'], true)) {
				$line_items[] = $name !== '' ? $name . ': ' . $value : $value;
			}
		}

		$form_id = isset($form_data['id']) ? (int) $form_data['id'] : 0;

		return [
			'total'              => self::formatAmount($total_amount),
			'subtotal'           => self::formatAmount($subtotal_amount),
			'discount'           => self::formatAmount($discount_amount),
			'type'               => 'one-time',
			'payment_method'     => $method,
			'coupon'             => '',
			'items'              => array_values(array_unique(array_filter($line_items))),
			'gateway'            => $gateway,
			'date_of_submission' => current_time('mysql'),
			'mode'               => 'live',
			'form_id'            => $form_id,
			'entry_id'           => isset($entry['id']) ? (int) $entry['id'] : 0,
			'form_title'         => isset($form_data['settings']['form_title']) ? (string) $form_data['settings']['form_title'] : '',
		];
	}

	/**
	 * Build order description.
	 */
	private static function build_description(
		string $id,
		string $description
	): string {

		if ($id === '') {
			return $description;
		}

		return $description !== ''
			? sprintf('Order #%s - %s', $id, $description)
			: sprintf('Order #%s', $id);
	}

	/**
	 * @param array<string, mixed> $field
	 * @param array<string, mixed> $quantities
	 * @param int|string $field_id
	 * @param array<string, mixed> $form_data
	 */
	private static function get_submitted_quantity(array $field, array $quantities, $field_id, array $form_data): int {
		$field_id = (string) $field_id;
		$submitted_quantity = isset($quantities[$field_id]) ? (int) $quantities[$field_id] : 0;

		if ($submitted_quantity <= 0 && isset($form_data['quantities'][$field_id])) {
			$submitted_quantity = (int) $form_data['quantities'][$field_id];
		}

		if ($submitted_quantity <= 0 && isset($field['min_quantity'])) {
			$submitted_quantity = (int) $field['min_quantity'];
		}

		return max(1, $submitted_quantity);
	}

	private static function resolve_coupon_discount_amount(string $coupon_code, int $form_id, float $subtotal): float {
		$coupon_code = trim($coupon_code);
		if ($coupon_code === '' || $subtotal <= 0) {
			return 0.0;
		}

		$repository = self::get_coupon_repository();
		if ($repository === null || !method_exists($repository, 'get_coupon_by_code')) {
			return 0.0;
		}

		$coupon = $repository->get_coupon_by_code($coupon_code);
		if (!is_object($coupon) || !method_exists($coupon, 'is_valid') || !$coupon->is_valid($form_id)) {
			return 0.0;
		}

		$discount_type = (string) $coupon->get_discount_type();
		$amount = (float) $coupon->get_discount_amount();
		if ($amount <= 0) {
			return 0.0;
		}

		$discount_amount = $discount_type === 'percentage' ? $subtotal * ($amount / 100) : $amount;

		if (function_exists('wpforms_sanitize_amount')) {
			$discount_amount = (float) wpforms_sanitize_amount($discount_amount);
		}

		return max(0.0, min($subtotal, $discount_amount));
	}

	private static function get_coupon_repository(): ?object {
		if (!function_exists('wpforms_coupons')) {
			return null;
		}

		$plugin = wpforms_coupons();
		if (!is_object($plugin) || !method_exists($plugin, 'get')) {
			return null;
		}

		$repository = $plugin->get('repository');
		return is_object($repository) ? $repository : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_available_methods_safe(): array {
		try {
			$methods = IfthenpayClient::get_available_methods();
		} catch (\Throwable) {
			return [];
		}

		return is_array($methods) ? $methods : [];
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private static function get_coupon_fields(array $fields): array {
		$coupon_fields = [];

		foreach ($fields as $field_id => $field) {
			if (!is_array($field)) {
				continue;
			}

			$field_type = isset($field['type']) ? (string) $field['type'] : '';
			if (!in_array($field_type, ['coupon', 'payment-coupon'], true)) {
				continue;
			}

			$coupon_fields[$field_id] = $field;
		}

		return $coupon_fields;
	}

	private function __construct() {}
}
