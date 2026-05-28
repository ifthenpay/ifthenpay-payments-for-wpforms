<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Builds API payloads and gateway request structures for ifthenpay Pay By Link.
 */
final class IfthenpayPayload {

	/**
	 * Build pay-by-link payload.
	 */
	public static function build_pay_by_link_payload( array $args ): array {
		$id          = (string) ( $args['id'] ?? '' );
		$description = sanitize_text_field( $args['description'] ?? '' );

		$payload = [
			'id'          => $id,
			'amount'      => self::format_amount( $args['amount'] ?? 0 ),
			'description' => self::build_description( $id, $description ),
			'accounts'    => (string) ( $args['accounts'] ?? '' ),
			'success_url' => $args['success_url'] ?? '',
			'error_url'   => $args['error_url'] ?? '',
			'cancel_url'  => $args['cancel_url'] ?? '',
			'otp'         => 'true',
			'lang'        => self::map_locale_to_lang(
				(string) ( $args['locale'] ?? get_locale() )
			),
		];

		foreach ( [ 'selected_method', 'email', 'name', 'fields' ] as $field ) {
			if ( empty( $args[ $field ] ) ) {
				continue;
			}

			$payload[ $field ] = $args[ $field ];
		}

		return $payload;
	}

	/**
	 * Map WP locale to ifthenpay language code.
	 */
	public static function map_locale_to_lang( string $locale ): string {
		return match ( substr( strtolower( $locale ), 0, 2 ) ) {
			'pt', 'es', 'fr' => substr( strtolower( $locale ), 0, 2 ),
			default => 'en',
		};
	}

	/**
	 * Format amount with custom thousands separator.
	 */
	public static function format_amount(
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
	public static function build_gateway_urls( int $payment_id, string $base_url ): array {
		return [
			'success_url'  => add_query_arg( [ 'wpforms_pay' => 'success', 'iftp_payment_id' => $payment_id, 'transaction_id' => '[TRANSACTIONID]', 'iftp_gateway' => 1 ], $base_url ),
			'error_url'    => add_query_arg( [ 'wpforms_pay' => 'error',   'iftp_payment_id' => $payment_id, 'transaction_id' => '[TRANSACTIONID]', 'iftp_gateway' => 1 ], $base_url ),
			'cancel_url'   => add_query_arg( [ 'wpforms_pay' => 'cancel',  'iftp_payment_id' => $payment_id, 'transaction_id' => '[TRANSACTIONID]', 'iftp_gateway' => 1 ], $base_url ),
			'callback_url' => add_query_arg( [ 'iftp_pbl_callback' => 1 ], home_url( '/' ) ),
		];
	}

	/**
	 * Build the frontend session data returned after Pay By Link creation.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_pay_by_link_session(
		int $payment_id,
		string $redirect_url,
		string $token,
		string $return_url
	): array {
		return [
			'payment_id'            => $payment_id,
			'iframe_url'            => $redirect_url,
			'modal_token'           => $token,
			'return_url'            => $return_url,
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
		$response = [ 'status' => $status ];

		if ( $transaction_id !== '' ) {
			$response['transaction_id'] = $transaction_id;
		}

		if ( $payment_method !== '' ) {
			$response['payment_method'] = $payment_method;
		}

		return $response;
	}

	/**
	 * Resolve enabled payment accounts into the API "A|B;C|D" format.
	 *
	 * @param array<string, mixed> $methods_config
	 */
	public static function build_accounts_string( array $methods_config ): string {
		$parts = [];

		foreach ( $methods_config as $method ) {
			if ( empty( $method['enabled'] ) ) {
				continue;
			}

			$account = isset( $method['account'] ) ? trim( (string) $method['account'] ) : '';
			if ( $account === '' ) {
				continue;
			}

			$parts[] = preg_replace( '/\s*\|\s*/', '|', $account );
		}

		return implode( ';', array_values( array_filter( $parts, static fn( $value ) => is_string( $value ) && $value !== '' ) ) );
	}

	/**
	 * Resolve a selected method entity from config and ifthenpay method metadata.
	 *
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $methods_config
	 */
	public static function get_selected_method_entity( array $config, array $methods_config ): string {
		$selected_code = self::get_selected_method_code( $config, $methods_config );

		foreach ( self::get_available_methods_from_database() as $method ) {
			if ( empty( $method['Position'] ) || empty( $method['Entity'] ) ) {
				continue;
			}

			if ( (string) $method['Position'] === $selected_code ) {
				return strtoupper( (string) $method['Entity'] );
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
	public static function get_selected_method_code( array $config, array $methods_config ): string {
		$map = [];

		foreach ( self::get_available_methods_from_database() as $method ) {
			if ( empty( $method['Entity'] ) || ! isset( $method['Position'] ) ) {
				continue;
			}

			$entity        = strtoupper( (string) $method['Entity'] );
			$map[ $entity ] = (string) $method['Position'];
		}

		if ( empty( $map ) ) {
			return '';
		}

		$entity = '';

		if ( ! empty( $config['default_method'] ) ) {
			$entity = strtoupper( (string) $config['default_method'] );
		}

		if ( ! empty( $config['gateway_key'] ) ) {
			$gateway_key = (string) $config['gateway_key'];

			if (
				! empty( $config['gateway_methods'][ $gateway_key ]['default_method'] ) &&
				is_string( $config['gateway_methods'][ $gateway_key ]['default_method'] )
			) {
				$entity = strtoupper( (string) $config['gateway_methods'][ $gateway_key ]['default_method'] );
			}
		}

		if ( $entity !== '' && isset( $methods_config[ $entity ] ) && ! empty( $methods_config[ $entity ]['enabled'] ) ) {
			return $map[ $entity ] ?? (string) reset( $map );
		}

		$enabled = [];
		foreach ( $methods_config as $ent => $data ) {
			if ( ! empty( $data['enabled'] ) ) {
				$enabled[] = strtoupper( (string) $ent );
			}
		}

		if ( empty( $enabled ) ) {
			return (string) reset( $map );
		}

		$best     = null;
		$best_pos = PHP_INT_MAX;

		foreach ( $enabled as $ent ) {
			if ( isset( $map[ $ent ] ) ) {
				$pos = (int) $map[ $ent ];
				if ( $pos < $best_pos ) {
					$best_pos = $pos;
					$best     = $ent;
				}
			}
		}

		if ( $best !== null ) {
			return $map[ $best ];
		}

		foreach ( $map as $ent => $pos ) {
			if ( in_array( $ent, $enabled, true ) ) {
				return $pos;
			}
		}

		return (string) reset( $map );
	}

	/**
	 * Read configured methods for a selected gateway.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public static function get_gateway_methods_config( array $config, string $gateway_key ): array {
		if (
			$gateway_key !== '' &&
			! empty( $config['gateway_methods'][ $gateway_key ]['methods'] ) &&
			is_array( $config['gateway_methods'][ $gateway_key ]['methods'] )
		) {
			return $config['gateway_methods'][ $gateway_key ]['methods'];
		}

		if ( ! empty( $config['methods'] ) && is_array( $config['methods'] ) ) {
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

	private static function build_description( string $id, string $description ): string {
		if ( $id === '' ) {
			return $description;
		}

		return $description !== ''
			? sprintf( 'Order #%s - %s', $id, $description )
			: sprintf( 'Order #%s', $id );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_available_methods_from_database(): array {
		$catalog = get_option( 'iftp_pbl_method_catalog', [] );

		if ( ! is_array( $catalog ) ) {
			return [];
		}

		$methods = [];
		foreach ( $catalog as $method ) {
			if ( ! is_array( $method ) || empty( $method['entity'] ) ) {
				continue;
			}
			$methods[] = [
				'Entity'   => strtoupper( (string) $method['entity'] ),
				'Position' => (string) ( $method['position'] ?? 0 ),
			];
		}

		return $methods;
	}

	private function __construct() {}
}
