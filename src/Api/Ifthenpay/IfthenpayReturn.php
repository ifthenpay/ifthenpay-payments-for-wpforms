<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\Ifthenpay;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Handles gateway return/callback processing for ifthenpay Pay By Link.
 */
final class IfthenpayReturn {

	/**
	 * Resolve the normalized payment return context.
	 *
	 * IMPORTANT: this context is derived entirely from client-supplied $_GET/$_POST return
	 * params (see get_pay_now_return_data_from_request()) — its 'successful' flag is never
	 * proof of payment. See is_successful_pay_now_return()'s docblock.
	 *
	 * @param array<string, mixed> $return_data
	 * @return array{payment_method: string, successful: bool}
	 */
	public static function resolve_return_context( array $return_data ): array {
		$payment_method = sanitize_text_field( $return_data['PaymentMethod'] ?? '' );

		return [
			'payment_method' => $payment_method,
			'successful'     => self::is_successful_pay_now_return( $return_data ),
		];
	}

	/**
	 * Read the hidden pay-now return payload written by the frontend before final submit.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_pay_now_return_data_from_request(): array {
		$raw = self::get_posted_value( 'iftp_pbl_paid_now_return' );

		if ( $raw === '' ) {
			$wpforms_pay = self::get_query_string( 'wpforms_pay' );
			if ( $wpforms_pay === '' ) {
				return [];
			}

			$return_data = [ 'wpforms_pay' => $wpforms_pay ];

			foreach ( [ 'iftp_payment_id', 'payment_id' ] as $key ) {
				$val = self::get_query_string( $key );
				if ( $val !== '' ) {
					$return_data['payment_id'] = $val;
					break;
				}
			}

			$val = self::get_query_string( 'requestId' );
			if ( $val !== '' ) {
				$return_data['request_id'] = $val;
			}

			$sk = self::get_query_string( 'sk' );
			if ( $sk !== '' ) {
				$return_data['request_signature'] = $sk;
			}

			$val = sanitize_text_field( self::get_query_string( 'PaymentMethod' ) );
			if ( $val !== '' ) {
				$return_data['payment_method'] = $val;
			}

			return $return_data;
		}

		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Determine whether a Pay By Link return *reports itself* as successful.
	 *
	 * IMPORTANT: this reads $_GET/$_POST params from the customer's own browser return —
	 * IfthenpayPayload::build_gateway_urls() builds success/error/cancel_url as a plain,
	 * unsigned query string, so anyone can forge an identical return/POST. A TRUE result here
	 * is therefore never proof that a payment actually happened; no caller may treat it as
	 * authoritative for marking a payment "completed" or granting anything of value. The only
	 * trustworthy confirmation is Process::handle_webhook_success(), which independently
	 * verifies the payment server-to-server with ifthenpay's anti-phishing key and amount.
	 *
	 * @param array<string, mixed> $return_data
	 */
	public static function is_successful_pay_now_return( array $return_data ): bool {
		if ( empty( $return_data ) ) {
			return false;
		}

		if ( isset( $return_data['verified'] ) ) {
			return filter_var( $return_data['verified'], FILTER_VALIDATE_BOOLEAN );
		}

		$status_values = array_map( 'strtolower', array_intersect_key( $return_data, array_flip( [ 'status', 'Status', 'payment_status' ] ) ) );
		if ( in_array( 'cancelled', $status_values, true ) || in_array( 'failed', $status_values, true ) ) {
			return false;
		}

		if ( ! empty( $return_data[ 'wpforms_pay' ] ) ) {
			$status = strtolower( sanitize_text_field( (string) $return_data[ 'wpforms_pay' ] ) );
			if ( $status === 'success' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decide whether WPForms should block submission for this Pay By Link return.
	 *
	 * @param array<string, mixed> $return_data
	 * @param array{payment_method?: string, successful?: bool} $context
	 */
	public static function should_block_pay_now_return( array $return_data, array $context ): bool {
		if ( empty( $return_data ) ) {
			return true;
		}

		$status = self::get_return_status( $return_data );

		if ( in_array( $status, [ 'cancel', 'cancelled', 'canceled', 'error', 'failed' ], true ) ) {
			return true;
		}

		if ( in_array( $status, [ 'success', 'completed', 'paid', 'ok' ], true ) ) {
			return empty( $context['successful'] ) || empty( $context['payment_method'] );
		}

		return empty( $context['payment_method'] );
	}

	/**
	 * Read and normalize the gateway return status.
	 *
	 * @param array<string, mixed> $return_data
	 */
	public static function get_return_status( array $return_data ): string {
		foreach ( [ 'wpforms_pay', 'status', 'Status', 'payment_status' ] as $key ) {
			if ( empty( $return_data[ $key ] ) ) {
				continue;
			}

			return strtolower( sanitize_text_field( (string) $return_data[ $key ] ) );
		}

		return '';
	}

	private static function get_posted_value( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WPForms verifies its submission nonce upstream before this method is called.
		if ( ! isset( $_POST[ $key ] ) || $_POST[ $key ] === '' ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WPForms verifies its submission nonce upstream before this method is called.
		return sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) );
	}

	private static function get_query_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- payment gateway return URL; external service redirects cannot carry WP nonces.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private function __construct() {}
}
