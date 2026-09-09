<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\Ifthenpay;

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
		// $id doubles as both ifthenpay's tracking reference (see Process::generate_pbl_ref())
		// and, since it's the only "id" ifthenpay exposes back to the customer, the human-visible
		// order number shown both in the description below and on ifthenpay's own hosted page.
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
			$normalized = self::normalize_api_amount_string( (string) $amount );
			if ( ! is_numeric( $normalized ) ) {
				return (string) $amount;
			}
			$amount = $normalized;
		}

		$amount = self::normalize_api_amount_value( $amount );

		return number_format(
			(float) $amount,
			max( 0, $decimals ),
			'.',
			$thousands_separator
		);
	}

	/**
	 * Normalize a string amount so locale-formatted values are turned into a valid decimal number.
	 */
	private static function normalize_api_amount_string( string $amount ): string {
		$trimmed = trim( $amount );
		if ( $trimmed === '' ) {
			return '0';
		}

		$trimmed = preg_replace( '/[\x{00A0}\s]+/u', '', $trimmed );

		if ( str_contains( $trimmed, '.' ) && str_contains( $trimmed, ',' ) ) {
			if ( strrpos( $trimmed, ',' ) > strrpos( $trimmed, '.' ) ) {
				$trimmed = str_replace( '.', '', $trimmed );
				$trimmed = str_replace( ',', '.', $trimmed );
			} else {
				$trimmed = str_replace( ',', '', $trimmed );
			}
		} elseif ( str_contains( $trimmed, ',' ) ) {
			$trimmed = str_replace( ',', '.', $trimmed );
		}

		$trimmed = preg_replace( '/[^0-9.+-]/', '', $trimmed );

		if ( $trimmed === '' || ! is_numeric( $trimmed ) ) {
			return '0';
		}

		return $trimmed;
	}

	/**
	 * Normalize a numeric amount before formatting it for the gateway.
	 */
	private static function normalize_api_amount_value( float|int|string $amount ): float {
		if ( is_string( $amount ) ) {
			return (float) self::normalize_api_amount_string( $amount );
		}

		return (float) $amount;
	}

	/**
	 * Query params a gateway return URL can carry, from either round: our own tracking
	 * params (added below) or the ones ifthenpay's hosted page appends itself on the way
	 * back (id, amount, requestId, sk, brand, pan, lang) — see IfthenpayReturn.php and
	 * frontend.js's RETURN_PARAM_KEYS. When build_gateway_urls() is called with a $base_url
	 * derived from wp_get_referer() (see Process::ajax_create_pay_button_payment()), the
	 * browser may still be sitting on a *previous* payment's unstripped return URL — without
	 * removing these first, add_query_arg() would carry that stale amount/requestId/etc.
	 * straight into the *new* payment's success/error/cancel URLs.
	 */
	private const RETURN_PARAM_KEYS = [
		'wpforms_pay',
		'iftp_payment_id',
		'iftp_gateway',
		'id',
		'amount',
		'requestId',
		'sk',
		'brand',
		'pan',
		'lang',
	];

	/**
	 * Build gateway return URLs for a reserved WPForms payment ID.
	 *
	 * @return array<string, string>
	 */
	public static function build_gateway_urls( int $payment_id, string $base_url ): array {
		$base_url = remove_query_arg( self::RETURN_PARAM_KEYS, $base_url );

		return [
			'success_url'  => add_query_arg( [ 'wpforms_pay' => 'success', 'iftp_payment_id' => $payment_id, 'iftp_gateway' => 1 ], $base_url ),
			'error_url'    => add_query_arg( [ 'wpforms_pay' => 'error',   'iftp_payment_id' => $payment_id, 'iftp_gateway' => 1 ], $base_url ),
			'cancel_url'   => add_query_arg( [ 'wpforms_pay' => 'cancel',  'iftp_payment_id' => $payment_id, 'iftp_gateway' => 1 ], $base_url ),
			'callback_url' => add_query_arg( [ self::CALLBACK_QUERY_VAR => self::callback_path_segment() ], home_url( '/' ) ),
		];
	}

	/**
	 * Query var that marks a request as the ifthenpay merchant-notification webhook.
	 * A query string on the site root (rather than a bare invented path) is used
	 * deliberately: it reaches index.php on every WordPress install regardless of the
	 * active permalink structure, whereas a bare path like "/wpforms_1_0_0" 404s at the
	 * webserver before WordPress ever boots unless a catch-all rewrite is in place
	 * (only written by WP when "pretty" permalinks are active).
	 */
	public const CALLBACK_QUERY_VAR = 'iftp_wpforms_cb';

	/**
	 * Value of CALLBACK_QUERY_VAR that identifies this webhook: "wpforms". Kept
	 * version-free deliberately — an earlier "wpforms_x_y_z" scheme would have needed
	 * updating (and re-activating with ifthenpay) on every plugin release, which is
	 * exactly the kind of drift that causes a callback to silently stop matching.
	 */
	public static function callback_path_segment(): string {

		return 'wpforms';
	}

	/**
	 * Build the webhook activation URL template used to register ifthenpay merchant
	 * notifications via IfthenpayClient::activate_callback(). ifthenpay substitutes
	 * the bracketed placeholders when it pushes the callback request.
	 */
	public static function build_callback_activation_url( string $callback_url ): string {
		$separator = strpos( $callback_url, '?' ) !== false ? '&' : '?';

		return $callback_url . $separator . 'ref=[ORDER_ID]&apk=[ANTI_PHISHING_KEY]&val=[AMOUNT]&mtd=[PAYMENT_METHOD]&req=[REQUEST_ID]';
	}

	/**
	 * Build the frontend session data returned after Pay By Link creation.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_pay_by_link_session(
		int $payment_id,
		string $redirect_url,
		string $return_url
	): array {
		return [
			'payment_id'            => $payment_id,
			'iframe_url'            => $redirect_url,
			'return_url'            => $return_url,
		];
	}

	/**
	 * Build a compact status response for frontend verification.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_payment_status_response(
		string $status,
		string $payment_method = '',
		string $entry_preview_html = '',
		bool $returned = false
	): array {
		$response = [ 'status' => $status ];

		if ( $payment_method !== '' ) {
			$response['payment_method'] = $payment_method;
		}

		if ( $entry_preview_html !== '' ) {
			$response['entry_preview_html'] = $entry_preview_html;
		}

		if ( $returned ) {
			$response['returned'] = true;
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
