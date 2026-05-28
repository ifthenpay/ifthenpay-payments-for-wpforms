<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api;

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
	 * @param array<string, mixed> $return_data
	 * @param array<string, mixed> $fields
	 * @return array{transaction_id: string, payment_method: string, successful: bool}
	 */
	public static function resolve_return_context( array $return_data, array $fields ): array {
		$transaction_id = self::resolve_transaction_id( $return_data, $fields );

		$payment_method = sanitize_text_field( $return_data['PaymentMethod'] ?? '' );

		if ( $payment_method === '' && $transaction_id !== '' ) {
			$payment_method = self::payment_method_from_transaction_status( $transaction_id );
		}

		return [
			'transaction_id' => $transaction_id,
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

			$val = self::sanitize_transaction_id( self::get_query_string( 'transactionId' ) );
			if ( $val !== '' ) {
				$return_data['transaction_id'] = $val;
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
	 * Determine whether a Pay By Link return is verified/successful.
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
	 * @param array{transaction_id?: string, payment_method?: string, successful?: bool} $context
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
			return empty( $context['successful'] ) || empty( $context['transaction_id'] ) || empty( $context['payment_method'] );
		}

		return empty( $context['transaction_id'] ) || empty( $context['payment_method'] );
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

	/**
	 * Verify and read payment method for a transaction ID. FODACI
	 */
	public static function payment_method_from_transaction_status( string $transaction_id ): string {
		$transaction_id = sanitize_text_field( trim( $transaction_id ) );
		if ( $transaction_id === '' ) {
			return '';
		}

		try {
			$response = IfthenpayClient::get_payment_method_by_transaction_id( $transaction_id );
			return is_array( $response ) ? self::extract_payment_method_from_result( $response ) : '';
		} catch ( \RuntimeException ) {
			return '';
		}
	}

	/**
	 * @param array<string, mixed> $return_data
	 * @param array<string, mixed> $fields
	 */
	private static function resolve_transaction_id( array $return_data, array $fields ): string {
		$transaction_id = self::get_return_transaction_id_from_payload( $return_data );
		if ( $transaction_id !== '' ) {
			return $transaction_id;
		}

		$transaction_id = self::extract_transaction_id( $fields );
		if ( $transaction_id !== '' ) {
			return $transaction_id;
		}

		return self::extract_transaction_id_from_request();
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private static function extract_transaction_id( array $fields ): string {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$transaction_id = self::sanitize_transaction_id( sanitize_text_field( $field['transactionId'] ?? '' ) );
			if ( $transaction_id !== '' ) {
				return $transaction_id;
			}
		}

		return '';
	}

	private static function extract_transaction_id_from_request(): string {
		$value = self::sanitize_transaction_id( self::get_query_string( 'transactionId' ) );
		if ( $value !== '' ) {
			return $value;
		}

		$value = self::sanitize_transaction_id( self::get_post_string( 'transactionId' ) );
		if ( $value !== '' ) {
			return $value;
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function get_return_transaction_id_from_payload( array $payload ): string {
		return self::sanitize_transaction_id( sanitize_text_field( $payload['transactionId'] ?? '' ) );
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

	private static function get_post_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WPForms verifies its submission nonce upstream before this method is called.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	private static function sanitize_transaction_id( string $transaction_id ): string {
		$transaction_id = sanitize_text_field( trim( $transaction_id ) );

		if ( $transaction_id === '' || str_contains( $transaction_id, '[' ) ) {
			return '';
		}

		return $transaction_id;
	}

	public static function extract_payment_method_from_result( array $result ): string {
		$method = self::first_string_value( $result, self::PAYMENT_METHOD_KEYS );
		if ( $method !== '' ) {
			return $method;
		}
		// API may return an indexed list ([{...}]) — check the first element
		if ( isset( $result[0] ) && is_array( $result[0] ) ) {
			return self::first_string_value( $result[0], self::PAYMENT_METHOD_KEYS );
		}
		return '';
	}

	private static function first_string_value( array $data, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && $data[ $key ] !== '' ) {
				return $data[ $key ];
			}
		}
		return '';
	}

	private function __construct() {}
}
