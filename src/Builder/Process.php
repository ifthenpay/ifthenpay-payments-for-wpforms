<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Builder;

use Ifthenpay\WPForms\Admin\Settings;
use Ifthenpay\WPForms\Api\IfthenpayClient;
use Ifthenpay\WPForms\Api\IfthenpayFormData;
use Ifthenpay\WPForms\Api\IfthenpayPayload;
use Ifthenpay\WPForms\Api\IfthenpayReturn;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

class Process
{
	/** @var array<string, mixed>|null */
	private ?array $resolved_context = null;

    public function __construct(
        private string $slug,
    ) {
        add_filter('wpforms_forms_submission_prepare_payment_data', [$this, 'prepare_payment_data'], 10, 3);
        add_filter('wpforms_forms_submission_prepare_payment_meta', [$this, 'prepare_payment_meta'], 10, 3);
        add_filter('wpforms_process_initial_errors', [$this, 'payment_check_process'], 10, 2);
        add_action('wpforms_process_payment_saved', [$this, 'payment_saved_process'], 10, 3);
        add_action('wpforms_process_entry_saved', [$this, 'entry_saved_process'], 10, 5);
        add_action('wpforms_process_complete', [$this, 'payment_confirmation_process'], 10, 4);
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

        $context = $this->get_context($fields);
        $transactionId = $context['transaction_id'];

        $amount = IfthenpayFormData::resolve_amount($fields);
        $paymentMethod = (string) ($context['payment_method'] ?? '');
        $isSuccessful = !empty($context['successful']);

		if ($amount <= 0) {
			$paymentData['gateway'] = 'none';
			$this->stamp_payment_data($paymentData, $fields);
		} elseif ($transactionId !== '' && $isSuccessful && $paymentMethod !== '') {
			$paymentData['gateway'] = isset($paymentData['gateway']) ? sanitize_text_field($paymentData['gateway']) : $this->slug;
			$this->stamp_payment_data($paymentData, $fields);
			$paymentData['transaction_id'] = $transactionId;

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WPForms verifies its submission nonce before this filter fires.
			$post_wpforms_pay  = isset( $_POST['wpforms_pay'] )  ? sanitize_text_field( wp_unslash( (string) $_POST['wpforms_pay'] ) )  : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WPForms verifies its submission nonce before this filter fires.
			$post_iftp_gateway = isset( $_POST['iftp_gateway'] ) ? absint( wp_unslash( $_POST['iftp_gateway'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WPForms verifies its submission nonce before this filter fires.
			$post_id           = isset( $_POST['id'] )           ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) )           : '';

			if ( $post_wpforms_pay !== '' && $post_iftp_gateway === 1 ) {
				$paymentData['gateway'] = '';
				if ( $post_id !== '' && ctype_digit( $post_id ) ) {
					$paymentData['payment_id'] = (int) $post_id;
				}
			}
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
        $name                       = $this->extract_name($fields);
        $paymentData['title']       = $name !== '' ? $name : 'UserID: ' . IfthenpayPayload::generate_customer_id() . ' Purchase';
        $paymentData['customer_id'] = IfthenpayPayload::generate_customer_id();
    }

	 /**
     * @param array<string, mixed> $fields
     * @return string
     */
    private function extract_name(array $fields): string
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
        $transactionId = (string) $context['transaction_id'];

        $paymentMethod = (string) $context['payment_method'];
        $customerName = $this->extract_name($fields);

        if ($paymentMethod !== '') {
            $paymentMeta['method_type'] = $paymentMethod;
		}

        if ($customerName !== '') {
            $paymentMeta['customer_name'] = $customerName;
        }

        if ($transactionId !== '') {
            $paymentMeta['transaction_id'] = $transactionId;
        }

        $paymentMeta['log'] = wp_json_encode([
            'value' => IfthenpayReturn::is_successful_pay_now_return($returnData)
                ? __('ifthenpay pay-by-link completed.', 'ifthenpay-payments-for-wpforms')
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
        if ($paymentId <= 0 || false === wpforms_has_field_type(IFTP_PBL_FIELD_TYPE, $formData, false)) {
            return;
        }

        $context = $this->get_context($fields);
        $transactionId = $this->get_payment_transaction_id($paymentId);
        $paymentMethod = (string) $context['payment_method'];

        if ($transactionId === '') {
			$transactionId = (string) $context['transaction_id'];
        }

        $this->apply_confirmed_payment($paymentId, $transactionId, $paymentMethod);

        $reservedPaymentId = $this->get_posted_payment_id();
		if ($reservedPaymentId > 0 && $reservedPaymentId !== $paymentId) {
            $summary = get_transient( 'iftp_pbl_payment_summary_' . $reservedPaymentId );
            if (is_array($summary) && !empty($summary)) {
                $summary['payment_id'] = $paymentId;
                $summary['status'] = $transactionId !== '' ? 'completed' : 'pending';
                set_transient( 'iftp_pbl_payment_summary_' . $paymentId, $summary, HOUR_IN_SECONDS );
            }
            delete_transient( 'iftp_pbl_payment_summary_' . $reservedPaymentId );
        }
    }

	/**
	 * Read the stored transaction ID for a WPForms payment.
	 * @return string
	 */
	private function get_payment_transaction_id( int $paymentID ): string {
		if ( $paymentID <= 0 || ! function_exists( 'wpforms' ) ) {
			return '';
		}

		$payment = wpforms()->obj( 'payment' )->get( $paymentID, array( 'cap' => false ) );

		if ( ! empty( $payment->transaction_id ) ) {
			return sanitize_text_field( (string) $payment->transaction_id );
		}

		return '';
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
        unset($entry);

        if ($entryId <= 0 || $paymentId <= 0 || false === wpforms_has_field_type(IFTP_PBL_FIELD_TYPE, $formData, false)) {
            return;
        }

        $returnData = IfthenpayReturn::get_pay_now_return_data_from_request();
        $context = $this->get_context($fields);
        $transactionId = (string) $context['transaction_id'];
        $paymentMethod = (string) $context['payment_method'];

        $this->update_payment_row($paymentId, ['entry_id' => $entryId]);

        $this->apply_confirmed_payment($paymentId, $transactionId, $paymentMethod);

        $this->update_stored_payment_summary(
            $paymentId,
            [
                'entry_id' => $entryId,
                'form_id' => isset($formData['id']) ? (int) $formData['id'] : 0,
                'status' => $transactionId !== '' || IfthenpayReturn::is_successful_pay_now_return($returnData) ? 'completed' : 'pending',
            ]
        );

        delete_transient( 'iftp_pbl_payment_summary_' . $paymentId );
    }

    private function apply_confirmed_payment(int $paymentId, string $transactionId, string $paymentMethod): void
    {
        if ($transactionId !== '') {
            $this->update_payment_transaction_id($paymentId, $transactionId);
            $this->mark_wpforms_payments_status((string) $paymentId, 'completed');
        }

        if ($paymentMethod === '' && $transactionId !== '') {
            $paymentMethod = IfthenpayReturn::payment_method_from_transaction_status($transactionId);
        }
        if ($paymentMethod !== '') {
            $this->update_payment_method($paymentId, $paymentMethod);
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

        $paymentId = $this->get_posted_payment_id();
        $context = $this->get_context($fields);
        $transactionId = (string) $context['transaction_id'];

        if ($paymentId > 0 && function_exists('wpforms')) {
            $paymentMeta = wpforms()->get('payment_meta');
            if ($paymentMeta) {
                if ($transactionId !== '') {
                    $paymentMeta->update_or_add($paymentId, 'transaction_id', $transactionId);
                }
                $paymentMethod = (string) $context['payment_method'];
                if ($paymentMethod === '' && $transactionId !== '') {
                    $paymentMethod = IfthenpayReturn::payment_method_from_transaction_status($transactionId);
                }
                if ($paymentMethod !== '') {
                    $paymentMeta->update_or_add($paymentId, 'method_type', $paymentMethod);
                } else {
					return;
				}
            }

            $payment = wpforms()->get('payment');
            if ($payment && $entryId > 0) {
                $payment->update($paymentId, ['entry_id' => $entryId]);
            }
        }
    }

	private function store_modal_payment_token( string $token, int $payment_id ): void {
		set_transient( 'iftp_modal_payment_' . $token, $payment_id, DAY_IN_SECONDS );
	}

	private function get_payment_from_modal_token( string $token ): int {
		return absint( (int) get_transient( 'iftp_modal_payment_' . $token ) );
	}

    /**
	 * Handle the payment process from form submission and create a payment session.
	 * @param array<string, mixed> $fields The submitted form fields
	 * @param array<string, mixed> $entry The entry data
	 * @param array<string, mixed> $form_data The form configuration data
	 * @return array<string, mixed> Returns success state, message, and payment data if created
	 */
	public function handle_process( array $fields, array $entry, array $form_data, bool $is_ajax_request = false ): array {
		try {
			if ( ! $this->should_handle( $form_data, $is_ajax_request ) ) {
				return array(
					'success' => true,
					'handled' => false,
				);
			}

			$payment_id = absint( filter_input( INPUT_POST, 'iftp_pbl_payment_id', FILTER_SANITIZE_NUMBER_INT ) );

			if ( $payment_id > 0 ) {
				return array(
					'success' => true,
					'handled' => false,
				);
			}

			$backoffice_key = Settings::get_backoffice_key();
			if ( $backoffice_key === '' ) {
				return array(
					'success' => false,
					'message' => 'Gateway not configured',
				);
			}

			$config = isset( $form_data['payments'][ $this->slug ] ) && is_array( $form_data['payments'][ $this->slug ] )
				? $form_data['payments'][ $this->slug ]
				: [];

			$gateway_key = isset( $config['gateway_key'] ) ? trim( (string) $config['gateway_key'] ) : '';
			if ( $gateway_key === '' ) {
				return array(
					'success' => false,
					'message' => 'Gateway key not configured',
				);
			}

			$amount = IfthenpayFormData::resolve_amount( $fields );
			if ( ! ( $amount >= 0 && $amount ) ) {
				return array(
					'success' => false,
					'message' => 'Amount cannot be lower than 0',
				);
			}

			if ( $amount <= 0 ) {
				return array(
					'success'    => true,
					'skip_payment' => true,
				);
			}

			$methods_config         = IfthenpayPayload::get_gateway_methods_config( $config, $gateway_key );
			$selected_method_entity = IfthenpayPayload::get_selected_method_entity( $config, $methods_config );
			$selected_method_code   = IfthenpayPayload::get_selected_method_code( $config, $methods_config );
			$description            = 'Payment Gateway';
			$expire_days            = isset( $config['expire_days'] ) ? max( 1, absint( $config['expire_days'] ) ) : 1;
			$accounts               = IfthenpayPayload::build_accounts_string( $methods_config );

			if ( $accounts === '' ) {
				return array(
					'success' => false,
					'message' => 'No payment methods configured',
				);
			}

			$payment_id = $this->get_next_payment_id();
			if ( $payment_id <= 0 ) {
				return array(
					'success' => false,
					'message' => 'Unable to reserve payment id',
				);
			}

			$summary = IfthenpayFormData::extract_payment_summary( $fields, $entry, $form_data, $this->slug, $selected_method_entity );
			$summary['status']     = 'pending';
			$summary['payment_id'] = $payment_id;
			$name = $this->extract_name($fields);
			$summary['title'] = $name !== '' ? $name : 'UserID: ' . IfthenpayPayload::generate_customer_id() . ' Purchase';

			$this->update_stored_payment_summary( $payment_id, $summary );

			$id = substr((string) $payment_id, 0, 15);

			$base_url = wp_get_referer() ?: home_url( '/' );
			$gateway_urls = IfthenpayPayload::build_gateway_urls( $payment_id, $base_url );

			$payload = IfthenpayPayload::build_pay_by_link_payload(
				array(
					'id'              => $id,
					'amount'          => $amount,
					'description'     => $description,
					'accounts'        => $accounts,
					'expire_days'     => $expire_days,
					'success_url'     => $gateway_urls['success_url'],
					'error_url'       => $gateway_urls['error_url'],
					'cancel_url'      => $gateway_urls['cancel_url'],
					'locale'          => get_locale(),
					'callback_url'    => $gateway_urls['callback_url'],
					'selected_method' => $selected_method_code,
				)
			);

			$data = IfthenpayClient::create_payment_link( $gateway_key, $payload );

			if ( ! is_array( $data ) || empty( $data['RedirectUrl'] ) ) {
				$this->mark_wpforms_payments_status( (string) $payment_id, 'failed' );

				return array(
					'success' => false,
					'message' => 'Failed to create payment link',
				);
			}

			$remote_redirect_url = esc_url_raw( (string) $data['RedirectUrl'] );
			$token 				 = wp_generate_password( 48, false, false ) ?: '';

			if ( $token === '' || $remote_redirect_url === '' ) {
				$this->mark_wpforms_payments_status( (string) $payment_id, 'failed' );

				return array(
					'success' => false,
					'message' => 'Failed to generate payment session',
				);
			}

			$this->mark_wpforms_payments_status( (string) $payment_id, 'pending' );
			$this->store_modal_payment_token( $token, $payment_id );

			$payload_data = IfthenpayPayload::build_pay_by_link_session(
				$payment_id,
				$remote_redirect_url,
				$token,
				$base_url
			);

			return array(
				'success' => true,
				'data'    => $payload_data,
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => 'An error occurred',
			);
		}
	}

	/**
	 * Handle AJAX request to create a Pay By Link payment session.
	 * Validates form, gateway configuration, and returns a payment redirect URL.
	 * @return void
	 */
	public function ajax_create_pay_button_payment(): void {
		try {
			check_ajax_referer( 'iftp_pbl_frontend', 'nonce' );

			$form_id      = isset( $_POST['form_id'] )      ? absint( $_POST['form_id'] )                                                  : 0;
			$gateway_key  = isset( $_POST['gateway_key'] )  ? sanitize_text_field( wp_unslash( (string) $_POST['gateway_key'] ) )          : '';
			$form_payload = '';
			if ( isset( $_POST['form_payload'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Serialized query-string payload is intentionally preserved for wp_parse_str(); values are sanitized after parsing in build_fields_from_request().
				$form_payload = wp_unslash( (string) $_POST['form_payload'] );
			}

			if ( $form_id <= 0 || $gateway_key === '' || $form_payload === '' ) {
				$error_msg = 'Invalid form data';
				if ( $form_id <= 0 ) {
					$error_msg = 'Invalid form ID: ' . $form_id;
				} elseif ( $gateway_key === '' ) {
					$error_msg = 'Gateway key is missing';
				} elseif ( $form_payload === '' ) {
					$error_msg = 'Form payload is missing';
				}
				wp_send_json_error( array( 'message' => $error_msg ) );
			}

			$loaded_form = IfthenpayFormData::load_form_data( $form_id );
			$form_data   = $loaded_form['form_data'];

			if ( empty( $form_data ) ) {
				wp_send_json_error( array( 'message' => 'Form not found or not accessible' ) );
			}

			$submitted_fields = IfthenpayFormData::build_fields_from_request( $form_data, $form_payload );

			$entry            = array(
				'fields' => $submitted_fields,
			);

			$result = $this->handle_process( $submitted_fields, $entry, $form_data, true );

			if ( empty( $result['success'] ) ) {
				wp_send_json_error(
					array(
						'message' => isset( $result['message'] ) ? (string) $result['message'] : 'An error occurred',
					)
				);
			}

			if ( isset( $result['handled'] ) && ! $result['handled'] ) {
				wp_send_json_error( array( 'message' => 'Form not configured for payments' ) );
			}

			if ( ! empty( $result['skip_payment'] ) ) {
				wp_send_json_success(
					array(
						'skip_payment' => true,
					)
				);
			}

			if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) || empty( $result['data'] ) ) {
				wp_send_json_error(
					array(
						'message' => 'Failed to create payment session',
					)
				);
			}

			wp_send_json_success( $result['data'] );
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => 'Unable to create the payment link. Please review the ifthenpay configuration and try again.',
				)
			);
		}
	}

	/**
	 * Verify ifthenpay payment before submitting the WPForms entry.
	 */
	public function ajax_verify_payment(): void {

		check_ajax_referer('iftp_pbl_frontend', 'nonce');

		$transaction_id  = '';
		foreach ( IfthenpayReturn::TRANSACTION_ID_KEYS as $key ) {
			$val = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
			if ( $val !== '' ) { $transaction_id = $val; break; }
		}

		$request_id = '';
		foreach ( IfthenpayReturn::REQUEST_ID_KEYS as $key ) {
			$val = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
			if ( $val !== '' ) { $request_id = $val; break; }
		}

		$payment_id    = isset( $_POST['payment_id'] )    ? absint( $_POST['payment_id'] )                                              : 0;
		$return_action = isset( $_POST['return_action'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['return_action'] ) )       : '';
		$verification_id = $transaction_id !== '' ? $transaction_id : $request_id;

		if ($payment_id <= 0) {
			wp_send_json_error([
				'message' => __('Missing required parameters.', 'ifthenpay-payments-for-wpforms'),
			]);
		}

		if ($return_action === 'cancel') {
			$this->respond_with_status(
				$payment_id,
				'cancelled',
				$verification_id,
				''
			);
		}

		if ($return_action === 'error') {
			$this->respond_with_status(
				$payment_id,
				'failed',
				$verification_id,
				''
			);
		}

		if ($return_action !== 'success' || $verification_id === '') {
			$this->respond_with_status(
				$payment_id,
				'pending',
				$verification_id,
				''
			);
		}

		$payment_method = self::wait_and_get_payment_method($transaction_id);

		if ($payment_method !== []) {

			$this->update_payment_transaction_id(
				$payment_id,
				$transaction_id
			);

			$method_string = IfthenpayReturn::extract_payment_method_from_result($payment_method);

			$this->update_stored_payment_summary($payment_id, [
				'verified_transaction_id' => $transaction_id,
				'verified_payment_method' => $method_string,
				'verified_successful'     => true,
				'verified_at'             => time(),
			]);

			$this->respond_with_status(
				$payment_id,
				'completed',
				$transaction_id,
				$method_string
			);
		}

		$this->respond_with_status(
			$payment_id,
			'failed',
			$verification_id,
			''
		);
	}

	private function get_context(array $fields): array
	{
		if ($this->resolved_context !== null) {
			return $this->resolved_context;
		}

		$payment_id = $this->get_posted_payment_id();

		// Fallback: resolve via modal token if payment_id wasn't posted
		if ($payment_id <= 0) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WPForms verifies its submission nonce.
			$token = isset($_POST['iftp_modal_token'])
				? sanitize_text_field(wp_unslash((string) $_POST['iftp_modal_token']))
				: '';
			if ($token !== '') {
				$payment_id = $this->get_payment_from_modal_token($token);
			}
		}

		if ($payment_id > 0) {
			$summary = get_transient( 'iftp_pbl_payment_summary_' . $payment_id );
			if (is_array($summary) && isset($summary['verified_transaction_id'])) {
				return $this->resolved_context = [
					'transaction_id' => (string) $summary['verified_transaction_id'],
					'payment_method' => (string) ($summary['verified_payment_method'] ?? ''),
					'successful'     => !empty($summary['verified_successful']),
				];
			}
		}

		// Last resort: legacy return-URL path
		$returnData = IfthenpayReturn::get_pay_now_return_data_from_request();
		return $this->resolved_context = IfthenpayReturn::resolve_return_context($returnData, $fields);
	}

	/**
	 * Wait 5 seconds, then get the payment method by transaction ID.
	 */
	private static function wait_and_get_payment_method(string $transaction_id, int $timeout = 10, int $interval = 3): array
	{
		$deadline = time() + $timeout;
		do {
			try {
				$payment_method = IfthenpayClient::get_payment_method_by_transaction_id($transaction_id);
			} catch (\Throwable $e) {
				$payment_method = [];
			}

			if ($payment_method !== []) {
				return $payment_method;
			}
			sleep($interval);

		} while (time() < $deadline);

		return [];
	}

	/**
	 * Persist and respond with payment status.
	 */
	private function respond_with_status(
		int $payment_id,
		string $status,
		string $transaction_id,
		string $payment_method
	): void {

		$this->mark_wpforms_payments_status(
			(string) $payment_id,
			$status
		);

		wp_send_json_success(
			IfthenpayPayload::build_payment_status_response(
				$status,
				$transaction_id,
				$payment_method
			)
		);
	}

	/**
	 * Cancel a pending payment session from frontend/modal interaction.
	 */
	public function ajax_cancel_payment(): void {
		check_ajax_referer( 'iftp_pbl_frontend', 'nonce' );

		$modal_token = isset( $_POST['modal_token'] ) ? sanitize_text_field( wp_unslash( $_POST['modal_token'] ) ) : '';
		if ( $modal_token === '' ) {
			wp_send_json_error( array( 'message' => 'Missing payment token' ) );
		}

		$payment_id = $this->get_payment_from_modal_token( $modal_token );
		if ( $payment_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Payment not found' ) );
		}

		$this->mark_wpforms_payments_status( (string) $payment_id, 'cancelled' );
		wp_send_json_success( IfthenpayPayload::build_payment_status_response( 'cancelled' ) );
	}

	/**
	 * Read the posted payment ID.
	 */
	private function get_posted_payment_id(): int {
		return absint(
			filter_input(
				INPUT_POST,
				'iftp_pbl_payment_id',
				FILTER_SANITIZE_NUMBER_INT
			)
		);
	}

	/**
	 * Determine if this gateway should handle the form submission.
	 * Checks if payment is enabled and form has required fields.
	 * @param array<string, mixed> $form_data The form configuration data.
	 * @return bool True if gateway should handle form.
	 */
	public function should_handle( array $form_data, bool $is_ajax_request = false ): bool {
		if ( ! isset( $form_data['payments'][ $this->slug ] ) ) {
			return false;
		}

		$config  = $form_data['payments'][ $this->slug ];
		$enabled = isset( $config['enable'] ) && (string) $config['enable'] === '1';

		if ( ! $enabled ) {
			return false;
		}

		if ( $is_ajax_request ) {
			return true;
		}

		if ( empty( $form_data['fields'] ) || ! is_array( $form_data['fields'] ) ) {
			return false;
		}

		if (
			! IfthenpayFormData::find_field_by_type( $form_data['fields'], 'iftp_pbl_field' )
		) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function update_payment_row( int $payment_id, array $data ): bool {
		if ( $payment_id <= 0 || empty( $data ) || ! function_exists( 'wpforms' ) ) {
			return false;
		}

		$data['date_updated_gmt'] = current_time( 'mysql', true );
		$payment                  = wpforms()->obj( 'payment' ) ?: wpforms()->get( 'payment' );

		if ( ! $payment || ! method_exists( $payment, 'update' ) ) {
			return false;
		}

		return (bool) $payment->update( $payment_id, $data );
	}

	private function update_payment_transaction_id( int $payment_id, string $transaction_id ): void {
		$transaction_id = trim( $transaction_id );
		if ( $payment_id <= 0 || $transaction_id === '' ) {
			return;
		}

		$this->update_stored_payment_summary(
			$payment_id,
			array(
				'transaction_id' => $transaction_id,
			)
		);

		$this->update_payment_row(
			$payment_id,
			array(
				'transaction_id' => $transaction_id,
			)
		);

		if ( function_exists( 'wpforms' ) ) {
			$payment_meta = wpforms()->get( 'payment_meta' );
			if ( $payment_meta && method_exists( $payment_meta, 'update_or_add' ) ) {
				$payment_meta->update_or_add( $payment_id, 'transaction_id', $transaction_id );
			}
		}
	}

	private function update_payment_method( int $payment_id, string $payment_method ): void {
		$payment_method = trim( $payment_method );
		if ( $payment_id <= 0 || $payment_method === '' ) {
			return;
		}

		$this->update_stored_payment_summary(
			$payment_id,
			array(
				'payment_method' => $payment_method,
			)
		);

		if ( function_exists( 'wpforms' ) ) {
			$payment_meta = wpforms()->get( 'payment_meta' );
			if ( $payment_meta && method_exists( $payment_meta, 'update_or_add' ) ) {
				$payment_meta->update_or_add( $payment_id, 'method_type', $payment_method );
			}
		}
	}

	/**
	 * Updates the payment status for a given payment ID in WPForms.
	 * @param string $payment_id The ID of the payment to update.
	 * @param string $status     The new status to set (e.g., 'completed', 'cancelled', 'failed').
	 */
	public function mark_wpforms_payments_status( string $payment_id, string $status ): void {
		if ( $payment_id === '' ) {
			return;
		}

		$payment_id = (int) $payment_id;
		$updated    = false;

		if ( function_exists( 'wpforms' ) ) {
			$payment_handler = wpforms()->obj( 'payment' );
			if ( $payment_handler && method_exists( $payment_handler, 'update' ) ) {
				$updated = (bool) $payment_handler->update(
					$payment_id,
					array(
						'status'           => $status,
						'date_updated_gmt' => current_time( 'mysql', true ),
					)
				);
			}
		}

		if ( ! $updated ) {
			return;
		}

		do_action( 'iftp_pbl_payment_status_changed', $payment_id, $status );
	}

	/**
	 * Update stored payment summary data for a payment record.
	 * @param int $payment_id Payment ID.
	 * @param array<string, mixed> $changes Key-value pairs of summary data to update.
	 */
	private function update_stored_payment_summary( int $payment_id, array $changes ): void {
		if ( $payment_id <= 0 ) {
			return;
		}

		$key     = 'iftp_pbl_payment_summary_' . $payment_id;
		$summary = get_transient( $key );
		$summary = is_array( $summary ) ? $summary : [];
		$summary = array_merge( $summary, $changes );

		set_transient( $key, $summary, HOUR_IN_SECONDS );
	}

	/**
	 * Generate a temporary payment reservation ID before WPForms creates the real payment row.
	 * @return int
	 */
	private function get_next_payment_id(): int {
		global $wpdb;

		$max_id = $wpdb->get_var( "SELECT MAX(id) FROM " . $wpdb->prefix . "wpforms_payments" );
		$next_id = (int) $max_id + 1;return $next_id > 0 ? $next_id : 1;

	}
}
