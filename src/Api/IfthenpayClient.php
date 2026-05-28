<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use RuntimeException;

final class IfthenpayClient {

	private const API_BASE    = 'https://api.ifthenpay.com';
	private const MOBILE_BASE = 'https://ifthenpay.com/IfmbWS/ifthenpaymobile.asmx';

	private string $backoffice_key;

	public function __construct(string $backoffice_key) {
		$this->backoffice_key = sanitize_text_field($backoffice_key);
	}

	/**
	 * Validate backoffice key.
	 */
	public static function validate_backoffice_key(string $backoffice_key): bool {
		$backoffice_key = sanitize_text_field($backoffice_key);

		if ($backoffice_key === '') {
			return false;
		}

		$url = add_query_arg(
			[
				'boKey' => $backoffice_key,
			],
			self::API_BASE . '/gateway/get'
		);

		try {
			$data = self::request('GET', $url);

			return !empty($data);
		} catch (RuntimeException) {
			return false;
		}
	}

	/**
	 * Get available payment methods (raw API response).
	 */
	public static function get_available_methods(): array {
		return self::request(
			'GET',
			self::API_BASE . '/gateway/methods/available'
		);
	}

	/**
	 * Build a normalized method catalog from a raw API response.
	 * Use this instead of get_method_catalog() when you already have the raw data.
	 *
	 * @param array<int, array<string, mixed>> $rawMethods
	 * @return array<int, array<string, string>>
	 */
	public static function build_method_catalog_from_raw( array $rawMethods ): array {
		$catalog = [];
		foreach ( $rawMethods as $method ) {
			if ( ! is_array( $method ) || empty( $method['Entity'] ) || empty( $method['IsVisible'] ) ) {
				continue;
			}
			$catalog[] = [
				'entity'    => strtoupper( (string) $method['Entity'] ),
				'label'     => isset( $method['Method'] ) ? (string) $method['Method'] : (string) $method['Entity'],
				'logo'      => isset( $method['SmallImageUrl'] )     ? (string) $method['SmallImageUrl']     : '',
				'logo_dark' => isset( $method['SmallImageUrlDark'] ) ? (string) $method['SmallImageUrlDark'] : '',
				'position'  => (int) ( $method['Position'] ?? 0 ),
			];
		}
		return $catalog;
	}

	/**
	 * Get visible payment methods normalized into a consistent indexed catalog.
	 * Makes one API call. Prefer build_method_catalog_from_raw() when you already hold the raw methods.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_method_catalog(): array {
		try {
			return self::build_method_catalog_from_raw( self::get_available_methods() );
		} catch ( \Throwable ) {
			return [];
		}
	}

	/**
	 * Extract gateway method accounts from an API response row.
	 * Accepts pre-fetched $available_methods to avoid a redundant API call per row.
	 *
	 * @param array<string, mixed> $row
	 * @param array<int, array<string, mixed>> $available_methods
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_gateway_method_accounts( array $row, array $available_methods ): array {
		$methods = [];

		foreach ( $available_methods as $method ) {
			if ( empty( $method['IsVisible'] ) || empty( $method['Method'] ) ) {
				continue;
			}

			$key = sanitize_text_field( $method['Entity'] ?? '' );
			if ( $key === '' ) {
				continue;
			}

			$value = self::get_gateway_method_account_value( $row, $method );
			if ( $value === '' ) {
				continue;
			}

			$methods[ $key ] = [
				'method'     => $method['Method'],
				'entity'     => $key,
				'account'    => $value,
				'is_visible' => (bool) $method['IsVisible'],
			];
		}

		return $methods;
	}

	/**
	 * Read the raw account value from the WPForms gateway row.
	 *
	 * The API can return modern values like "MBWAY | ARR-489851" or legacy
	 * values like "5674-726720"; both are valid. Empty strings mean inactive.
	 */
	private static function get_gateway_method_account_value(array $row, array $method): string {
		$candidates = array_filter(
			array_unique(
				array_map(
					'strval',
					[
						$method['Entity'] ?? '',
						$method['Method'] ?? '',
						strtoupper((string) ($method['Entity'] ?? '')),
						strtoupper((string) ($method['Method'] ?? '')),
						strtolower((string) ($method['Entity'] ?? '')),
						strtolower((string) ($method['Method'] ?? '')),
					]
				)
			),
			static fn(string $key): bool => trim($key) !== ''
		);

		$entity = strtoupper((string) ($method['Entity'] ?? ''));
		$label = strtoupper((string) ($method['Method'] ?? ''));

		if ($entity === 'MB' || $label === 'MULTIBANCO') {
			$candidates[] = 'Multibanco';
			$candidates[] = 'MULTIBANCO';
			$candidates[] = 'MB';
		}

		foreach ($candidates as $candidate) {
			if (!array_key_exists($candidate, $row)) {
				continue;
			}

			$value = sanitize_text_field((string) $row[$candidate]);
			if (trim($value) !== '') {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Get gateway list.
	 */
	public function get_gateway_keys(string $type = ''): array {
		$args = [
			'boKey' => $this->backoffice_key,
		];

		$type = sanitize_text_field($type);
		if ($type !== '') {
			$args['Type'] = $type;
		}

		$url = add_query_arg($args, self::API_BASE . '/gateway/get');

		return self::request('GET', $url);
	}

	/**
	 * Fetch the gateway catalog for a backoffice key.
	 * Pass $rawMethods when you already have the available-methods API response to avoid an extra call.
	 *
	 * @param array<int, array<string, mixed>> $rawMethods Pre-fetched available methods (optional).
	 */
	public static function fetch_gateway_catalog( string $backofficeKey, array $rawMethods = [] ): array {
		$backofficeKey = trim( sanitize_text_field( $backofficeKey ) );
		if ( $backofficeKey === '' ) {
			return [];
		}

		try {
			$catalog = ( new self( $backofficeKey ) )->get_gateway_catalog( $rawMethods );
		} catch ( \Throwable ) {
			return [];
		}

		return is_array( $catalog ) ? $catalog : [];
	}

	/**
	 * Get gateway catalog.
	 * Fetches available methods once internally when $rawMethods is not provided.
	 *
	 * @param array<int, array<string, mixed>> $rawMethods Pre-fetched available methods (optional).
	 */
	public function get_gateway_catalog( array $rawMethods = [] ): array {
		$rows = $this->get_gateway_keys( 'WPForms' );

		if ( empty( $rawMethods ) ) {
			try {
				$rawMethods = self::get_available_methods();
			} catch ( RuntimeException ) {
				$rawMethods = [];
			}
		}

		$catalog = [];

		foreach ( $rows as $row ) {
			if ( empty( $row['GatewayKey'] ) ) {
				continue;
			}

			$key = sanitize_text_field( $row['GatewayKey'] );
			if ( $key === '' ) {
				continue;
			}

			$alias         = sanitize_text_field( $row['Alias'] ?? '' );
			$catalog[$key] = [
				'gateway_key' => $key,
				'alias'       => $alias,
				'label'       => $alias !== '' ? $alias : $key,
				'methods'     => self::build_gateway_method_accounts( $row, $rawMethods ),
			];
		}

		return $catalog;
	}

	/**
	 * Get gateway accounts.
	 */
	public function get_gateway_accounts(string $gateway_key): array {
		$url = add_query_arg(
			[
				'backofficekey' => $this->backoffice_key,
				'gatewayKey'    => sanitize_text_field($gateway_key),
			],
			self::MOBILE_BASE . '/GetAccountsByGatewayKey'
		);

		return self::request('GET', $url);
	}

	/**
	 * Get payment method by transaction ID.
	 */
	public static function get_payment_method_by_transaction_id(string $transaction_id): array {
		//$transaction_id = 'HWG9lQsKJeLhjYzoCa8U';

		$url = add_query_arg(
			[
				'transactionId' => $transaction_id,
			],
			self::API_BASE . '/gateway/transaction/status/get'
		);

		return self::request('GET', $url);
	}

	/**
	 * Create Pay By Link.
	 */
	public static function create_payment_link(string $gateway_key, array $payload): array {
		$url = rtrim( self::API_BASE, '/' ) . '/gateway/pinpay/' . rawurlencode( $gateway_key );

		return self::request(
			'POST',
			$url,
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body' => wp_json_encode($payload),
			]
		);
	}

	/**
	 * Centralized request handler.
	 *
	 * @throws RuntimeException
	 */
	private static function request(
		string $method,
		string $url,
		array $args = [],
		int $timeout = 20
	): array {

		$args = wp_parse_args(
			$args,
			[
				'timeout'   => $timeout,
				'sslverify' => true,
			]
		);

		$response = strtoupper($method) === 'POST'
			? wp_remote_post($url, $args)
			: wp_remote_get($url, $args);

		if (is_wp_error($response)) {
			throw new RuntimeException(
				esc_html($response->get_error_message())
			);
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);

		if ($code < 200 || $code >= 300) {
			throw new RuntimeException(
				sprintf(
					'Ifthenpay API error (%s): %s',
					esc_html((string) $code),
					esc_html(mb_substr($body, 0, 300))
				),
				(int) $code
			);
		}

		return self::decode($body);
	}

	/**
	 * Decode API JSON response.
	 *
	 * @throws RuntimeException
	 */
	private static function decode(string $body): array {
		$data = json_decode($body, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException(
				'Invalid JSON response from Ifthenpay API.'
			);
		}

		if (isset($data['d'])) {

			if (is_string($data['d'])) {
				$data = json_decode($data['d'], true);
			} else {
				$data = $data['d'];
			}
		}

		if (!is_array($data)) {
			return [
				'data' => $data,
			];
		}

		return $data;
	}
}
