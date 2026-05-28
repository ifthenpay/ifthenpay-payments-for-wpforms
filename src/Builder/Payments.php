<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Builder;

use Ifthenpay\WPForms\Admin\Settings;
use Ifthenpay\WPForms\Api\IfthenpayClient;
use Ifthenpay\WPForms\Api\IfthenpayEmailHelper;
use Ifthenpay\WPForms\Api\IfthenpayPayload;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

class Payments
{
    private const METHOD_ACTIVATION_COOLDOWN = DAY_IN_SECONDS;
    private const GATEWAY_CHANGE_COOLDOWN    = 2; // seconds between gateway changes per form
    private const ALLOWED_PAYMENT_TYPES = [
        'payment-single', 'payment-checkbox', 'payment-multiple',
        'payment-select', 'payment-coupon', 'payment-total',
    ];

    /** @var array<string, mixed> */
    private array $formData;

    /** @var array<string, array<string, string>> */
    private array $gateways;

    /** @var array<int, array<string, mixed>> */
    private array $availableMethods;

    private int $formId;

    public function __construct(
        private string $name,
        private string $slug
    ) {
        $this->formData         = $this->get_form_data();
        $this->formId           = (int) ($this->formData['id'] ?? 0);
        $this->gateways         = [];
        $this->availableMethods = [];

        add_filter('wpforms_payments_available', [$this, 'register_payment']);
        add_action('wpforms_payments_panel_content', [$this, 'builder_output'], 20);
        add_action('wpforms_payments_panel_sidebar', [$this, 'builder_sidebar'], 0);
        add_action('wpforms_builder_enqueues', [$this, 'enqueue_builder_assets']);
        add_filter('wpforms_save_form_args', [$this, 'sanitize_saved_payment_settings'], 10, 3);
        add_filter('wpforms_admin_education_addons_item_base_display_single_addon_hide', [$this, 'should_hide_educational_menuItem'], 10, 2);
    }

    /**
     * Retrieves the current WPForms form data being edited.
     * @return array<string, mixed>
     */
    private function get_form_data(): array
    {
        if (!function_exists('wpforms')) {
            return [];
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        $formId = isset($_GET['form_id']) ? absint(wp_unslash($_GET['form_id'])) : 0;
        if ($formId <= 0 && isset($_POST['form_id'])) {
            $formId = absint(wp_unslash($_POST['form_id']));
        }
        // phpcs:enable

        if ($formId <= 0) {
            return [];
        }

        $formData = wpforms()->get('form')->get($formId, ['content_only' => true]);

        return is_array($formData) ? $formData : [];
    }

    /**
     * @param array<string, mixed> $paymentsAvailable
     * @return array<string, mixed>
     */
    public function register_payment(array $paymentsAvailable): array
    {
        $paymentsAvailable[$this->slug] = $this->name;
        return $paymentsAvailable;
    }

	/**
	 * Builds ouput function
	 */
    public function builder_output(): void
	{
		echo '<div class="wpforms-panel-content-section wpforms-panel-content-section-' . esc_attr($this->slug) . '" id="' . esc_attr($this->slug) . '-provider" data-provider="' . esc_attr($this->slug) . '">';
		echo '<div class="wpforms-panel-content-section-title">' . esc_html($this->name) . '</div>';
		echo '<div class="wpforms-payment-settings wpforms-clear">';
		$this->builder_content();
		echo '</div></div>';
	}

    /**
     * Builds the payment settings panel UI for the selected gateway.
     */
    private function builder_content(): void
    {
        if (Settings::get_backoffice_key() === '') {
            echo wp_kses_post($this->render_connection_required_notice());
            return;
        }

        $config            = $this->get_payment_config($this->formData);
        $gatewayKey        = $this->resolve_selected_gateway_key((string) ($config['gateway_key'] ?? ''), $this->gateways);
        $details           = $gatewayKey !== '' ? $this->get_gateway_methods_for_key($gatewayKey) : [];
        $methodsConfig     = $this->sanitize_gateway_methods_config(IfthenpayPayload::get_gateway_methods_config($config, $gatewayKey), $details);
        $defaultMethod     = $this->sanitize_default_method($this->get_gateway_default_method($config, $gatewayKey), $methodsConfig);
        $description       = (string) ($config['description'] ?? '');
        $hasField          = $this->has_ifthenpay_field();
        $conflictingFields = $this->get_conflicting_payment_fields();

        echo '<div id="wpforms-' . esc_attr($this->slug) . '-field-alert" class="' . ($hasField ? ' wpforms-hidden' : '') . '">';
        echo '<img src="' . esc_url($this->icon()) . '" class="wpforms-builder-payment-settings-alert-icon" alt="' . esc_attr__('Connect WPForms to ifthenpay.', 'ifthenpay-payments-for-wpforms') . '">';
        echo '<div class="wpforms-builder-payment-settings-default-content">';
        echo '<p>' . sprintf(
            /* translators: %s - payment gateway name */
            esc_html__('To use %s, first add the ifthenpay payment field to your form.', 'ifthenpay-payments-for-wpforms'),
            esc_html($this->name)
        ) . '</p>';
        echo '<p class="wpforms-builder-payment-settings-learn-more"><a href="' . esc_url('https://helpdesk.ifthenpay.com/pt-PT/support/home') . '" target="_blank" rel="noopener noreferrer" class="secondary-text">' . esc_html__('Learn more about ifthenpay | Payment Gateway.', 'ifthenpay-payments-for-wpforms') . '</a></p>';
        echo '</div></div>';

        echo '<div id="wpforms-panel-content-section-payment-' . esc_attr($this->slug) . '"' . ($hasField ? '' : ' class="wpforms-hidden"') . '>';
        echo '<div class="wpforms-panel-content-section-payment">';
        echo '<h2 class="wpforms-panel-content-section-payment-subtitle">' . esc_html($this->name) . '</h2>';

        wpforms_panel_field(
            'toggle',
            $this->slug,
            'enable',
            $this->formData,
            /* translators: %s - payment gateway name */
            sprintf(esc_html__('Enable %s', 'ifthenpay-payments-for-wpforms'), $this->name),
            [
                'parent'  => 'payments',
                'default' => '0',
                'tooltip' => esc_html__('Allow customers to pay through ifthenpay using this form.', 'ifthenpay-payments-for-wpforms'),
                'class'   => 'wpforms-panel-content-section-payment-toggle wpforms-panel-content-section-payment-toggle-' . esc_attr($this->slug),
            ]
        );

        /* translators: %s - payment provider name */
        $headsUpTitle = sprintf(__('Heads up! %s cannot be enabled yet.', 'ifthenpay-payments-for-wpforms'), $this->name);

        if (!empty($conflictingFields)) {
            echo '<div class="iftp-pbl-builder-requirements-warning" data-warning-type="conflicting-fields">';
            echo wp_kses_post($this->render_heads_up_screen($headsUpTitle, [
                sprintf(
                    /* translators: %s - payment field that is causing a conflict with ifthenpay field */
                    __('This form contains another payment field or gateway field that conflicts with ifthenpay: %s.', 'ifthenpay-payments-for-wpforms'),
                    esc_html(implode(', ', $conflictingFields))
                ),
            ]));
            echo '</div>';
        }

        echo '<div id="iftp-pbl-config-wrapper" style="margin-top:16px;">';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $this->render_gateway_selector($gatewayKey);
        echo $this->render_methods_table($details, $methodsConfig, $gatewayKey);
        echo $this->render_default_config($defaultMethod, $description, $details, $methodsConfig, $gatewayKey);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div></div></div>';
    }

    public function builder_sidebar(): void
    {
        echo wp_kses_post(wpforms_render('builder/payment/sidebar', [
            'configured' => 'configured',
            'slug' => $this->slug,
            'icon' => $this->icon(),
            'name' => $this->name,
            'recommended' => true,
        ], true));
    }

    /**
     * Enqueues necessary scripts and styles for the payment settings panel in the WPForms builder.
     */
    public function enqueue_builder_assets(string $view): void
    {
        unset($view);

        if (Settings::get_backoffice_key() !== '' && $this->formId > 0) {
            $this->fetch_and_cache_api_data();
        }

        wp_enqueue_script('ifthenpay-wpforms-builder', IFTP_PBL_URL . 'assets/js/admin.js', ['jquery', 'wpforms-builder'], IFTP_PBL_VERSION, true);
        wp_enqueue_style('ifthenpay-wpforms-builder', IFTP_PBL_URL . 'assets/css/admin.css', [], IFTP_PBL_VERSION);

        wp_localize_script('ifthenpay-wpforms-builder', 'ifthenpayWpformsBuilder', [
            'slug' => $this->slug,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'activationNonce' => wp_create_nonce('iftp_pbl_activate_payment_method'),
            'gatewayMethodsNonce' => wp_create_nonce('iftp_pbl_load_gateway_methods'),
            'fieldType' => IFTP_PBL_FIELD_TYPE,
            'name' => $this->name,
            'hasConnection' => Settings::get_backoffice_key() !== '',
            'unusableReason' => Settings::get_unusable_reason($this->formData),
            'gateways' => $this->gateways,
            'conflictingFields' => $this->get_conflicting_payment_fields(),
            'messages' => [
                'fieldRequired' => __('Add the ifthenpay field to this form before enabling ifthenpay.', 'ifthenpay-payments-for-wpforms'),
                'gatewayRequired' => __('Select an ifthenpay gateway key in the Payments tab first.', 'ifthenpay-payments-for-wpforms'),
                'sendingActivation' => __('Sending activation request...', 'ifthenpay-payments-for-wpforms'),
                'activationSent' => __('Your activation request has been sent to support.', 'ifthenpay-payments-for-wpforms'),
                'activationFailed' => __('Failed to send the activation email. Please try again later.', 'ifthenpay-payments-for-wpforms'),
                'activationServerError' => __('Server error sending activation request.', 'ifthenpay-payments-for-wpforms'),
                'loadingMethods' => __('Loading payment methods...', 'ifthenpay-payments-for-wpforms'),
                'loadMethodsError' => __('Unable to load the payment methods for this gateway. Please try again.', 'ifthenpay-payments-for-wpforms'),
            ],
        ]);
    }

    public function ajax_load_gateway_methods(): void
    {
        check_ajax_referer('iftp_pbl_load_gateway_methods', 'nonce');
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'ifthenpay-payments-for-wpforms' ), 403 );
		}

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to load ifthenpay gateway methods.', 'ifthenpay-payments-for-wpforms')], 403);
        }

        if (Settings::get_backoffice_key() === '' || $this->formId <= 0 || !$this->has_ifthenpay_field()) {
            wp_send_json_error(['message' => __('Gateway methods can only be loaded for a saved form that contains the ifthenpay field.', 'ifthenpay-payments-for-wpforms')], 400);
        }

        $cooldownKey     = $this->get_gateway_change_cooldown_key($this->formId);
        $cooldownExpires = (int) get_transient($cooldownKey);
        if ($cooldownExpires > time()) {
            $remaining = $cooldownExpires - time();
            wp_send_json_error([
                /* translators: %s - human-readable time remaining */
                'message'          => sprintf(__('Please wait %s before changing the gateway again.', 'ifthenpay-payments-for-wpforms'), human_time_diff(time(), $cooldownExpires)),
                'cooldown_seconds' => $remaining,
            ], 429);
        }

        $this->load_api_data();

        $gatewayKey = $this->resolve_selected_gateway_key(
            isset($_POST['gateway_key']) ? sanitize_text_field(wp_unslash((string) $_POST['gateway_key'])) : '',
            $this->gateways
        );

        if ($gatewayKey === '') {
            wp_send_json_error(['message' => __('Select a valid ifthenpay gateway key.', 'ifthenpay-payments-for-wpforms')], 400);
        }

        $details = $this->get_gateway_methods_for_key($gatewayKey);
        if (empty($details)) {
            wp_send_json_error(['message' => __('Unable to load this gateway configuration.', 'ifthenpay-payments-for-wpforms')], 400);
        }

        $config        = $this->get_payment_config($this->formData);
        $methodsConfig = $this->sanitize_gateway_methods_config(IfthenpayPayload::get_gateway_methods_config($config, $gatewayKey), $details);
        $defaultMethod = $this->sanitize_default_method($this->get_gateway_default_method($config, $gatewayKey), $methodsConfig);

        if ($defaultMethod === '') {
            foreach ($methodsConfig as $entity => $methodConfig) {
                if (!empty($methodConfig['enabled'])) {
                    $defaultMethod = (string) $entity;
                    break;
                }
            }
        }

        $description = isset($_POST['description'])
            ? sanitize_text_field(wp_unslash((string) $_POST['description']))
            : (string) ($config['description'] ?? '');

        $tableConfig                = $config;
        $tableConfig['gateway_key'] = $gatewayKey;
        $table                      = $this->build_form_table($tableConfig, $details);
        if (!empty($table)) {
            update_option($this->form_table_option_key($this->formId), $table, false);
            set_transient($cooldownKey, time() + self::GATEWAY_CHANGE_COOLDOWN, self::GATEWAY_CHANGE_COOLDOWN);
        }

        wp_send_json_success([
            'gateway_key'  => $gatewayKey,
            'methods_html' => $this->render_methods_table($details, $methodsConfig, $gatewayKey),
            'default_html' => $this->render_default_config($defaultMethod, $description, $details, $methodsConfig, $gatewayKey),
        ]);
    }

    public function ajax_activate_payment_method(): void
    {
        check_ajax_referer('iftp_pbl_activate_payment_method', 'nonce');
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'ifthenpay-payments-for-wpforms' ), 403 );
		}

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to activate payment methods.', 'ifthenpay-payments-for-wpforms')], 403);
        }

        if (Settings::get_backoffice_key() === '') {
            wp_send_json_error(['message' => __('Connect a valid Backoffice Key in WPForms Settings > Payments before activating payment methods.', 'ifthenpay-payments-for-wpforms')], 400);
        }

        $gatewayKey = isset($_POST['gateway_key']) ? sanitize_text_field(wp_unslash((string) $_POST['gateway_key'])) : '';
        $entity = isset($_POST['entity']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['entity']))) : '';
        $backofficeKey = Settings::get_backoffice_key();

        if ($gatewayKey === '' || $entity === '' || $backofficeKey === '') {
            wp_send_json_error(['message' => __('Missing gateway, method, or Backoffice Key.', 'ifthenpay-payments-for-wpforms')], 400);
        }

        $cooldown = $this->get_method_activation_cooldown_data($gatewayKey, $entity);
        if (!empty($cooldown['active'])) {
            wp_send_json_error([
                /* translators: %s - cooldown time in human readable format */
                'message' => sprintf(__('Please wait %s before sending another activation request.', 'ifthenpay-payments-for-wpforms'), (string) ($cooldown['human'] ?? '')),
                'cooldown_seconds' => (int) ($cooldown['seconds'] ?? 0),
            ], 429);
        }

        $user = wp_get_current_user();
        $sent = IfthenpayEmailHelper::send_iftp_wpf_activation_email([
            'gateway_key' => $gatewayKey,
            'entity' => $entity,
            'backoffice_key' => $backofficeKey,
            'customer_email' => isset($user->user_email) ? (string) $user->user_email : '',
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'wp_version' => get_bloginfo('version'),
            'wpforms_version' => defined('WPFORMS_VERSION') ? (string) WPFORMS_VERSION : '',
            'plugin_version' => defined('IFTP_PBL_VERSION') ? (string) IFTP_PBL_VERSION : '1.0.0',
        ]);

        if ($sent) {
            $this->record_method_activation_request($gatewayKey, $entity);
            $cooldown = $this->get_method_activation_cooldown_data($gatewayKey, $entity);
            wp_send_json_success([
                'message' => __('Your activation request has been sent to support.', 'ifthenpay-payments-for-wpforms'),
                'cooldown_seconds' => (int) ($cooldown['seconds'] ?? 0),
            ]);
        }

        wp_send_json_error(['message' => __('Failed to send the activation email. Please try again later.', 'ifthenpay-payments-for-wpforms')]);
    }

    /**
     * Sanitizes and persists payment settings when a form is saved.
     * @param array<string, mixed> $postData
     * @param array<string, mixed> $data
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function sanitize_saved_payment_settings(array $postData, array $data = [], array $args = []): array
    {
        unset($data, $args);

        $formData = isset($postData['post_content']) && function_exists('wpforms_decode')
            ? wpforms_decode((string) $postData['post_content'])
            : [];
        $formData = is_array($formData) ? $formData : [];

        if (empty($formData['payments'][$this->slug]) || !is_array($formData['payments'][$this->slug])) {
            return $postData;
        }

        $formId = (int) ($formData['id'] ?? 0);

        $this->load_api_data();

        if (empty($this->availableMethods) || empty($this->gateways)) {
            try {
                $this->fetch_and_cache_api_data();
            } catch (\Throwable) {
                // API unreachable; table update will be skipped below.
            }
        }

        $sanitizedConfig = $this->sanitize_payment_config($formData['payments'][$this->slug]);
        $formData['payments'][$this->slug] = $sanitizedConfig;

        if ($formId > 0) {
            $optionKey     = $this->form_table_option_key($formId);
            $newGatewayKey = (string) ($sanitizedConfig['gateway_key'] ?? '');

            if ($newGatewayKey !== '') {
                $existing = (array) get_option($optionKey, []);
                if ((string) ($existing['gateway_key'] ?? '') !== $newGatewayKey) {
                    delete_option($optionKey);
                }
            }

            if (!empty($this->availableMethods)) {
                $table = $this->build_form_table($sanitizedConfig);
                if (!empty($table)) {
                    update_option($optionKey, $table, false);
                }
            }
        }

        $postData['post_content'] = function_exists('wpforms_encode') ? wpforms_encode($formData) : wp_json_encode($formData);

        return $postData;
    }

    public function should_hide_educational_menuItem($hide, $addon): bool
    {
        return (isset($addon['clear_slug']) && $this->slug === $addon['clear_slug']) ? true : (bool) $hide;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function get_payment_config(array $config): array
    {
        return (isset($config['payments'][$this->slug]) && is_array($config['payments'][$this->slug]))
            ? $config['payments'][$this->slug]
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function get_gateway_methods_for_key(string $gatewayKey): array
    {
        $gatewayKey = trim($gatewayKey);
        if ($gatewayKey === '' || !isset($this->gateways[$gatewayKey])) {
            return [];
        }

        $gateway = $this->gateways[$gatewayKey];
        if (!is_array($gateway) || empty($gateway['methods']) || !is_array($gateway['methods'])) {
            return [];
        }

        return $gateway['methods'];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function get_gateway_default_method(array $config, string $gatewayKey): string
    {
        $saved = '';
        if ($gatewayKey !== '' && isset($config['gateway_methods'][$gatewayKey]['default_method'])) {
            $saved = (string) $config['gateway_methods'][$gatewayKey]['default_method'];
        } elseif (isset($config['default_method'])) {
            $saved = (string) $config['default_method'];
        }

        return ($saved !== '' && !empty($config['gateway_methods'][$gatewayKey]['methods'][$saved]['enabled']))
            ? $saved
            : '';
    }

    /**
     * @param array<string, array<string, string>> $gateways
     */
    private function resolve_selected_gateway_key(string $gatewayKey, array $gateways): string
    {
        $gatewayKey = trim($gatewayKey);
        if ($gatewayKey !== '' && isset($gateways[$gatewayKey])) {
            return $gatewayKey;
        }
        return !empty($gateways) ? (string) array_key_first($gateways) : '';
    }

    /**
     * @param array<int, array<string, mixed>> $methodsConfig
     * @param array<string, mixed> $details
     * @return array<string, array<string, string>>
     */
    private function sanitize_gateway_methods_config(array $methodsConfig, array $details): array
    {
        $sanitized = [];
        foreach ($this->availableMethods as $method) {
            $entity = isset($method['entity']) ? strtoupper((string) $method['entity']) : '';
            if ($entity === '') {
                continue;
            }
            $account = $this->get_account_string_for_method($details, $entity);
            $sanitized[$entity] = [
                'enabled' => ($account !== '' && !empty($methodsConfig[$entity]['enabled'])) ? '1' : '0',
                'account' => $account,
            ];
        }
        return $sanitized;
    }

    /**
     * @param array<string, array<string, string>> $methodsConfig
     */
    private function sanitize_default_method(string $defaultMethod, array $methodsConfig): string
    {
        $defaultMethod = strtoupper(trim($defaultMethod));
        if ($defaultMethod === '' || empty($methodsConfig[$defaultMethod]['enabled'])) {
            return '';
        }
        return $defaultMethod;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function get_account_string_for_method(array $details, string $entity): string
    {
        $entity = strtoupper(trim($entity));
        if ($entity === '' || empty($details)) {
            return '';
        }

        $entities = [$entity];
        if ($entity === 'MB') {
            $entities[] = 'MULTIBANCO';
        } elseif ($entity === 'MULTIBANCO') {
            $entities[] = 'MB';
        }

        foreach ($details as $method) {
            if (!is_array($method)) {
                continue;
            }
            if (!in_array(strtoupper((string) ($method['entity'] ?? '')), $entities, true)) {
                continue;
            }
            $account = trim((string) ($method['account'] ?? ''));
            if ($account !== '') {
                return $account;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function sanitize_payment_config(array $config): array
    {
        $sanitized = $config;
        $sanitized['gateway_key'] = !empty($this->gateways)
            ? $this->resolve_selected_gateway_key((string) ($config['gateway_key'] ?? ''), $this->gateways)
            : sanitize_text_field((string) ($config['gateway_key'] ?? ''));
        $sanitized['description'] = sanitize_text_field((string) ($config['description'] ?? ''));

        unset($sanitized['methods'], $sanitized['default_method']);

        if ($sanitized['gateway_key'] === '') {
            $sanitized['gateway_methods'] = [];
            $sanitized['default_method'] = '';
            return $sanitized;
        }

        $details = $this->get_gateway_methods_for_key($sanitized['gateway_key']);
        if (empty($details)) {
            return $sanitized;
        }

        $methodsConfig = $this->sanitize_gateway_methods_config(
            IfthenpayPayload::get_gateway_methods_config($config, $sanitized['gateway_key']),
            $details
        );

        $savedDefault = isset($config['gateway_methods'][$sanitized['gateway_key']]['default_method'])
            ? (string) $config['gateway_methods'][$sanitized['gateway_key']]['default_method']
            : (string) ($config['default_method'] ?? '');

        $defaultMethod = $this->sanitize_default_method($savedDefault, $methodsConfig);
        if ($defaultMethod === '') {
            $enabledMethods = array_filter($methodsConfig, static fn(array $m): bool => !empty($m['enabled']));
            if (!empty($enabledMethods)) {
                $defaultMethod = (string) array_key_first($enabledMethods);
            }
        }

        $sanitized['gateway_methods'] = [
            $sanitized['gateway_key'] => [
                'methods' => $methodsConfig,
                'default_method' => $defaultMethod,
            ],
        ];
        $sanitized['default_method'] = $defaultMethod;

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $details
     * @param array<string, array<string, string>> $methodsConfig
     */
    private function render_methods_table(array $details, array $methodsConfig, string $gatewayKey): string
    {
        $slug = esc_attr($this->slug);
        $gk = esc_attr($gatewayKey);

        $html = '<div id="wpforms-panel-field-' . $slug . '-payment-methods" class="wpforms-panel-field-row iftp-pbl-methods-panel" style="margin-top:14px;">'
            . '<div style="margin-bottom:8px;font-weight:600;font-size:13px;">' . esc_html__('Payment Methods', 'ifthenpay-payments-for-wpforms') . '</div>'
            . '<table class="iftp-pbl-methods-table" data-gateway-key="' . $gk . '">'
            . '<thead><tr>'
            . '<th style="text-align:center;width:60px;">' . esc_html__('Enable', 'ifthenpay-payments-for-wpforms') . '</th>'
            . '<th>' . esc_html__('Method', 'ifthenpay-payments-for-wpforms') . '</th>'
            . '<th style="text-align:center;width:100px;">' . esc_html__('Logo', 'ifthenpay-payments-for-wpforms') . '</th>'
            . '<th>' . esc_html__('Account', 'ifthenpay-payments-for-wpforms') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($this->availableMethods as $method) {
            $entity = strtoupper((string) ($method['entity'] ?? ''));
            $methodName = (string) ($method['label'] ?? $entity);
            $logoUrl = (string) ($method['logo'] ?? '');
            $accountString = isset($methodsConfig[$entity]['account'])
                ? (string) $methodsConfig[$entity]['account']
                : $this->get_account_string_for_method($details, $entity);
            $hasAccount = trim($accountString) !== '';
            $isActivated = $hasAccount && !empty($methodsConfig[$entity]['enabled']);
            $inputId = 'iftp_pbl_method_' . $gatewayKey . '_' . $entity;
            $entityAttr = esc_attr($entity);
            $inputIdAttr = esc_attr($inputId);

            $html .= '<tr class="iftp-pbl-method-row' . ($hasAccount ? '' : ' is-missing-account') . '" data-entity="' . $entityAttr . '">'
                . '<td class="iftp-method-toggle"><span class="wpforms-toggle-control">'
                . '<input type="checkbox" class="iftp-pbl-method-enabled" id="' . $inputIdAttr . '"'
                . ' name="payments[' . $slug . '][gateway_methods][' . $gk . '][methods][' . $entityAttr . '][enabled]"'
                . ' value="1" data-entity="' . $entityAttr . '" data-label="' . esc_attr($methodName) . '" '
                . checked($isActivated, true, false) . disabled(!$hasAccount, true, false) . '>'
                . '<label class="wpforms-toggle-control-icon" for="' . $inputIdAttr . '"></label>'
                . '</span></td>'
                . '<td><strong>' . esc_html($methodName) . '</strong></td>'
                . '<td class="iftp-method-logo-cell">'
                . ($logoUrl !== '' ? '<img src="' . esc_url($logoUrl) . '" alt="' . esc_attr($methodName) . '" class="iftp-method-logo">' : '&mdash;')
                . '</td>'
                . '<td class="iftp-method-account">'
                . ($hasAccount
                    ? $this->render_readonly_account_input($gatewayKey, $entity, $accountString)
                    : $this->render_method_activation_request($gatewayKey, $entity, $methodName))
                . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private function render_readonly_account_input(string $gatewayKey, string $entity, string $accountString): string
    {
        return '<input type="text" readonly="readonly" aria-readonly="true" tabindex="-1"'
            . ' name="payments[' . esc_attr($this->slug) . '][gateway_methods][' . esc_attr($gatewayKey) . '][methods][' . esc_attr($entity) . '][account]"'
            . ' value="' . esc_attr($accountString) . '" class="iftp-pbl-account-input"'
            . ' title="' . esc_attr__('This account is loaded from your ifthenpay gateway and cannot be edited here.', 'ifthenpay-payments-for-wpforms') . '">';
    }

    private function render_method_activation_request(string $gatewayKey, string $entity, string $methodName): string
    {
        $cooldown = $this->get_method_activation_cooldown_data($gatewayKey, $entity);
        $active = !empty($cooldown['active']);
        $message = $active
            /* translators: %s - cooldown time in human readable format */
            ? sprintf(__('Activation request sent. Try again in %s.', 'ifthenpay-payments-for-wpforms'), (string) ($cooldown['human'] ?? ''))
            : '';

        return '<div class="iftp-pbl-no-accounts">'
            . '<p class="iftp-pbl-no-accounts-label">' . esc_html__('No accounts.', 'ifthenpay-payments-for-wpforms')
            . '<button type="button" class="iftp-pbl-activate-method iftp-pbl-activate-method-button"'
            . ' data-entity="' . esc_attr($entity) . '" data-method-name="' . esc_attr($methodName) . '"'
            . ' data-gateway-key="' . esc_attr($gatewayKey) . '"' . disabled($active, true, false) . '>'
            . esc_html__('Activate', 'ifthenpay-payments-for-wpforms') . '</button></p>'
            . '<p class="iftp-pbl-activation-message' . ($active ? ' is-success' : '') . '" aria-live="polite">'
            . esc_html($message) . '</p></div>';
    }

    /**
     * @return array<string, bool|int|string>
     */
    private function get_method_activation_cooldown_data(string $gatewayKey, string $entity): array
    {
        $expiresAt = (int) get_transient($this->get_method_activation_cooldown_key($gatewayKey, $entity));
        $seconds = max(0, $expiresAt - time());

        return [
            'active' => $seconds > 0,
            'seconds' => $seconds,
            'human' => $seconds > 0 ? human_time_diff(time(), $expiresAt) : '',
        ];
    }

    private function record_method_activation_request(string $gatewayKey, string $entity): void
    {
        set_transient(
            $this->get_method_activation_cooldown_key($gatewayKey, $entity),
            time() + self::METHOD_ACTIVATION_COOLDOWN,
            self::METHOD_ACTIVATION_COOLDOWN
        );
    }

    private function get_method_activation_cooldown_key(string $gatewayKey, string $entity): string
    {
        return 'iftp_pbl_method_activation_' . md5(
            Settings::get_backoffice_key() . '|' . home_url('/') . '|' . strtoupper(trim($gatewayKey)) . '|' . strtoupper(trim($entity))
        );
    }

    /**
     * @param array<string, mixed> $gatewayDetails
     * @param array<string, array<string, string>> $methodsConfig
     */
    private function render_default_config(string $defaultMethod, string $description, array $gatewayDetails, array $methodsConfig, string $gatewayKey): string
    {
        $slug = esc_attr($this->slug);
        $selectName = $gatewayKey !== ''
            ? ' name="payments[' . $slug . '][gateway_methods][' . esc_attr($gatewayKey) . '][default_method]"'
            : '';
        $selectDisabled = $gatewayKey === '' ? ' disabled="disabled"' : '';

        $html = '<div id="iftp-pbl-global-config" style="margin-top:14px;">'
            . '<div class="wpforms-panel-field-row" style="margin-top:10px;">'
            . '<label for="iftp_pbl_default_method" style="display:block;margin-bottom:4px;">' . esc_html__('Default payment method', 'ifthenpay-payments-for-wpforms') . '</label>'
            . '<select id="iftp_pbl_default_method"' . $selectName . $selectDisabled . ' style="min-width:240px;">'
            . '<option value="" ' . selected($defaultMethod, '', false) . '>' . esc_html__('Default (Auto)', 'ifthenpay-payments-for-wpforms') . '</option>';

        foreach ($this->availableMethods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $entity = strtoupper((string) ($method['entity'] ?? ''));
            if ($entity === '' || empty($methodsConfig[$entity]['enabled'])) {
                continue;
            }
            $label = (string) ($method['label'] ?? $entity);
            $html .= '<option value="' . esc_attr($entity) . '" ' . selected($defaultMethod, $entity, false) . '>' . esc_html($label) . '</option>';
        }

        $html .= '</select></div>'
            . '<div class="wpforms-panel-field-row" style="margin-top:10px;">'
            . '<label for="iftp_pbl_description" style="display:block;margin-bottom:4px;">' . esc_html__('Default description', 'ifthenpay-payments-for-wpforms') . '</label>'
            . '<input type="text" id="iftp_pbl_description" name="payments[' . $slug . '][description]" value="' . esc_attr($description) . '" class="regular-text" style="min-width:320px;">'
            . '<p class="description">' . esc_html__('Shown to customers as the payment description.', 'ifthenpay-payments-for-wpforms') . '</p>'
            . '</div></div>';

        return $html;
    }

    private function fetch_and_cache_api_data(): void
    {
        $backofficeKey = Settings::get_backoffice_key();

        $rawMethods = [];
        try {
            $rawMethods = IfthenpayClient::get_available_methods();
        } catch (\Throwable) {
            // Methods unavailable — catalog will be empty.
        }

        $this->gateways = IfthenpayClient::fetch_gateway_catalog($backofficeKey, $rawMethods);

        $this->availableMethods = IfthenpayClient::build_method_catalog_from_raw($rawMethods);

        if (!empty($this->gateways)) {
            update_option('iftp_pbl_gateway_catalog', $this->gateways, false);
        }

        if (!empty($this->availableMethods)) {
            update_option('iftp_pbl_method_catalog', $this->availableMethods, false);
        }
    }

    private function load_api_data(): void
    {
        if (!empty($this->gateways) && !empty($this->availableMethods)) {
            return;
        }

        $cachedGateways = (array) get_option('iftp_pbl_gateway_catalog', []);
        $cachedMethods  = (array) get_option('iftp_pbl_method_catalog', []);

        if (!empty($cachedGateways)) {
            $this->gateways = $cachedGateways;
        }

        if (!empty($cachedMethods)) {
            $this->availableMethods = $cachedMethods;
            return;
        }
    }

    /**
     * @param array<string, mixed> $paymentConfig
     * @param array<string, mixed> $gatewayDetails Gateway account details from API (pass when changing gateways via AJAX).
     * @return array<string, mixed>
     */
    private function build_form_table(array $paymentConfig = [], array $gatewayDetails = []): array
    {
        if (empty($this->availableMethods)) {
            return [];
        }

        if (empty($paymentConfig)) {
            $paymentConfig = $this->get_payment_config($this->formData);
        }

        $rawKey     = (string) ($paymentConfig['gateway_key'] ?? '');
        $gatewayKey = !empty($this->gateways)
            ? $this->resolve_selected_gateway_key($rawKey, $this->gateways)
            : $rawKey;

        if ($gatewayKey === '') {
            return [];
        }

        $description  = trim((string) ($paymentConfig['description'] ?? ''));
        $savedMethods = isset($paymentConfig['gateway_methods'][$gatewayKey]['methods'])
            && is_array($paymentConfig['gateway_methods'][$gatewayKey]['methods'])
            ? $paymentConfig['gateway_methods'][$gatewayKey]['methods']
            : [];
        $defaultEntity = strtoupper(trim(
            isset($paymentConfig['gateway_methods'][$gatewayKey]['default_method'])
                ? (string) $paymentConfig['gateway_methods'][$gatewayKey]['default_method']
                : (string) ($paymentConfig['default_method'] ?? '')
        ));

        $defaultPosition = 0;
        $payMethods      = [];

        foreach ($this->availableMethods as $method) {
            $entity = strtoupper((string) ($method['entity'] ?? ''));
            if ($entity === '') {
                continue;
            }
            $position = (int) ($method['position'] ?? 0);
            if ($entity === $defaultEntity && $position > 0) {
                $defaultPosition = $position;
            }

            $account = isset($savedMethods[$entity]['account']) ? (string) $savedMethods[$entity]['account'] : '';
            if ($account === '' && !empty($gatewayDetails)) {
                $account = $this->get_account_string_for_method($gatewayDetails, $entity);
            }

            $payMethods[] = [
                'entity'       => $entity,
                'label'        => (string) ($method['label']      ?? $entity),
                'position'     => $position,
                'account'      => $account,
                'is_active'    => !empty($savedMethods[$entity]['enabled']),
                'img_url'      => (string) ($method['logo']       ?? ''),
                'img_url_dark' => (string) ($method['logo_dark']  ?? ''),
            ];
        }

        return [
            'gateway_key'        => $gatewayKey,
            'default_pay_method' => $defaultPosition,
            'pay_description'    => $description,
            'pay_methods'        => $payMethods,
        ];
    }

    private function form_table_option_key(int $formId): string
    {
        return 'iftp_pbl_table_form_' . $formId;
    }

    private function render_gateway_selector(string $gatewayKey): string
    {
        $html = '<div class="wpforms-panel-field-row" style="margin-top:10px;">'
            . '<label for="iftp_pbl_gateway_key" style="display:block;margin-bottom:4px;">' . esc_html__('Gateway key', 'ifthenpay-payments-for-wpforms') . '</label>'
            . '<select id="iftp_pbl_gateway_key" name="payments[' . esc_attr($this->slug) . '][gateway_key]" style="min-width:280px;">';

        foreach ($this->gateways as $key => $gw) {
            $label = isset($gw['label']) && $gw['label'] !== '' ? (string) $gw['label'] : (string) $key;
            $html .= '<option value="' . esc_attr($key) . '" ' . selected($gatewayKey, $key, false) . '>' . esc_html($label) . '</option>';
        }

        return $html . '</select>'
            . '<p class="description">' . esc_html__('Choose the gateway key that should be used by this form.', 'ifthenpay-payments-for-wpforms') . '</p>'
            . '</div>';
    }

    private function render_connection_required_notice(): string
    {
        $settingsUrl = admin_url('admin.php?page=wpforms-settings&view=payments');

        return '<img src="' . esc_url($this->icon()) . '" class="wpforms-builder-payment-settings-alert-icon" alt="' . esc_attr__('Connect WPForms to ifthenpay.', 'ifthenpay-payments-for-wpforms') . '">'
            . '<div class="wpforms-builder-payment-settings-default-content">'
            . '<p class="wpforms-builder-payment-settings-error-title">' . esc_html__('Heads up! ifthenpay payments can\'t be enabled yet.', 'ifthenpay-payments-for-wpforms') . '</p>'
            . '<p>' . sprintf(
                /* translators: %s - settings page link */
                __('First, please connect your ifthenpay account on the %s page.', 'ifthenpay-payments-for-wpforms'),
                '<a href="' . esc_url($settingsUrl) . '" class="secondary-text">' . esc_html__('WPForms Settings', 'ifthenpay-payments-for-wpforms') . '</a>'
            ) . '</p>'
            . '<p class="wpforms-builder-payment-settings-learn-more"><a href="' . esc_url('https://helpdesk.ifthenpay.com/pt-PT/support/home') . '" target="_blank" rel="noopener noreferrer" class="secondary-text">' . esc_html__('Learn more about ifthenpay | Payment Gateway.', 'ifthenpay-payments-for-wpforms') . '</a></p>'
            . '</div>';
    }

    private function icon(): string
    {
        return IFTP_PBL_URL . 'assets/images/icon.svg';
    }

    /**
     * @param array<int, string> $messages HTML-safe strings
     */
    private function render_heads_up_screen(string $title, array $messages): string
    {
        $allowedTags = [
            'a' => ['href' => [], 'target' => [], 'rel' => [], 'class' => []],
            'br' => [],
            'strong' => [],
        ];

        $html = '<div id="wpforms-iftp-pbl-alert" class="wpforms-iftp-pbl-alert-headsup">'
            . '<img src="' . esc_url($this->icon()) . '" class="wpforms-builder-payment-settings-alert-icon" alt="' . esc_attr__('Ifthenpay payment settings alert', 'ifthenpay-payments-for-wpforms') . '">'
            . '<div class="wpforms-builder-payment-settings-default-content">';

        if ($title !== '') {
            $html .= '<p class="wpforms-builder-payment-settings-error-title">' . esc_html($title) . '</p>';
        }

        foreach ($messages as $message) {
            if (!is_string($message) || $message === '') {
                continue;
            }
            $html .= '<p>' . wp_kses($message, $allowedTags) . '</p>';
        }

        return $html . '</div></div>';
    }

    private function has_ifthenpay_field(): bool
    {
        if (empty($this->formData['fields']) || !is_array($this->formData['fields'])) {
            return false;
        }

        foreach ($this->formData['fields'] as $field) {
            if ((string) ($field['type'] ?? '') === IFTP_PBL_FIELD_TYPE) {
                return true;
            }
        }

        return false;
    }

    private function get_gateway_change_cooldown_key(int $formId): string
    {
        return 'iftp_pbl_gw_change_' . $formId;
    }

    /**
     * @return array<int, string>
     */
    private function get_conflicting_payment_fields(): array
    {
        if (empty($this->formData['fields']) || !is_array($this->formData['fields'])) {
            return [];
        }

        $allowed = array_merge([IFTP_PBL_FIELD_TYPE], self::ALLOWED_PAYMENT_TYPES);
        $conflicts = [];

        foreach ($this->formData['fields'] as $fieldId => $field) {
            $type = (string) ($field['type'] ?? '');
            $label = (string) ($field['label'] ?? ('Field #' . $fieldId));

            if ($type !== '' && !in_array($type, $allowed, true) && strpos($type, 'payment') === 0) {
                $conflicts[] = $label . ' (' . $type . ')';
            }
        }

        return $conflicts;
    }
}
