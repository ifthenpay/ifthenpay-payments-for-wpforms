<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Admin;

use Ifthenpay\WPForms\Api\IfthenpayClient;

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Are you sure?' );
}

class Settings
{
    public const OPTION_KEY           = 'wpforms_settings';
    public const FIELD_BACKOFFICE_KEY = 'ifthenpay-backoffice-key';
    private const OPTION_BACKOFFICE_KEY = 'iftp_pbl_backofficekey';
    private const SIGNUP_URL           = 'https://ifthenpay.com';

    public function __construct()
    {
        add_filter('wpforms_settings_defaults', [$this, 'register_fields'], 13);
        add_action('wpforms_settings_enqueue', [$this, 'enqueue_assets']);
        add_action('wp_ajax_iftp_pbl_connect_backoffice', [$this, 'ajax_connect_backoffice']);
        add_action('wp_ajax_iftp_pbl_disconnect_backoffice', [$this, 'ajax_disconnect_backoffice']);
    }

    public static function get_backoffice_key(): string
    {
        return trim((string) get_option(self::OPTION_BACKOFFICE_KEY, ''));
    }

    public static function get_form_payment_settings(array $formData): array
    {
        $raw = isset($formData['payments'][IFTP_PBL_SLUG]) && is_array($formData['payments'][IFTP_PBL_SLUG])
            ? $formData['payments'][IFTP_PBL_SLUG]
            : [];

        $gatewayKey     = isset($raw['gateway_key']) ? trim((string) $raw['gateway_key']) : '';
        $gatewayMethods = (
            $gatewayKey !== ''
            && isset($raw['gateway_methods'][$gatewayKey])
            && is_array($raw['gateway_methods'][$gatewayKey])
        ) ? $raw['gateway_methods'][$gatewayKey] : [];

        return [
            'enabled'        => !empty($raw['enable']),
            'gateway_key'    => $gatewayKey,
            'default_method' => isset($gatewayMethods['default_method']) ? trim((string) $gatewayMethods['default_method']) : '',
            'description'    => isset($raw['description']) ? trim((string) $raw['description']) : '',
            'methods'        => isset($gatewayMethods['methods']) && is_array($gatewayMethods['methods']) ? $gatewayMethods['methods'] : [],
        ];
    }

    public static function gateway_exists(string $gatewayKey, array $gateways): bool
    {
        $gatewayKey = trim($gatewayKey);
        if ($gatewayKey === '') {
            return false;
        }

        foreach ($gateways as $key => $gateway) {
            if (strcasecmp((string) $key, $gatewayKey) === 0) {
                return true;
            }

            if (isset($gateway['gateway_key']) && strcasecmp((string) $gateway['gateway_key'], $gatewayKey) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function get_unusable_reason(array $formData): string
    {
        if (empty(self::get_form_payment_settings($formData)['enabled'])) {
            return __('Enable ifthenpay payments in the Payments tab first.', 'ifthenpay-payments-for-wpforms');
        }

        if (self::get_backoffice_key() === '') {
            return __('Connect your ifthenpay Backoffice Key in WPForms Settings > Payments first.', 'ifthenpay-payments-for-wpforms');
        }

        if (self::get_form_payment_settings($formData)['gateway_key'] === '') {
            return __('Select an ifthenpay gateway key in the Payments tab first.', 'ifthenpay-payments-for-wpforms');
        }

        return '';
    }

    public function register_fields(array $settings): array
    {
        if (!isset($settings['payments']) || !is_array($settings['payments'])) {
            $settings['payments'] = [];
        }

        $settings['payments']['ifthenpay-heading'] = [
            'id'       => 'ifthenpay-heading',
            'content'  => $this->heading_content(),
            'type'     => 'content',
            'no_label' => true,
            'class'    => ['section-heading'],
        ];

        $settings['payments'][self::FIELD_BACKOFFICE_KEY] = [
            'id'      => self::FIELD_BACKOFFICE_KEY,
            'name'    => __('Backoffice Key', 'ifthenpay-payments-for-wpforms'),
            'type'    => 'text',
            'value'   => $this->masked_backoffice_key(),
            'filter'  => [self::class, 'sanitize_backoffice_key'],
            'desc'    => __('Connect your ifthenpay account to load gateway keys and payment methods.', 'ifthenpay-payments-for-wpforms'),
            'default' => '',
            'class'   => self::get_backoffice_key() !== ''
                ? ['iftp-pbl-backoffice-key-field', 'iftp-pbl-backoffice-key-field-hidden']
                : ['iftp-pbl-backoffice-key-field'],
        ];

        $settings['payments']['ifthenpay-connection-status'] = [
            'id'      => 'ifthenpay-connection-status',
            'name'    => __('Connection', 'ifthenpay-payments-for-wpforms'),
            'content' => $this->connection_status_content(),
            'type'    => 'content',
        ];

        return $settings;
    }

    public function enqueue_assets(): void
    {
        wp_register_style('ifthenpay-wpforms-admin', false, [], defined('IFTP_PBL_VERSION') ? IFTP_PBL_VERSION : '1.0.0');
        wp_enqueue_style('ifthenpay-wpforms-admin');
        wp_add_inline_style('ifthenpay-wpforms-admin', $this->inline_css());

        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'iftpPblSettings', $this->inline_js_data());
        wp_add_inline_script('jquery', $this->inline_js_behavior(), 'after');
    }

    public function ajax_connect_backoffice(): void
    {
        check_ajax_referer('iftp_pbl_settings_connection', 'nonce');
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'ifthenpay-payments-for-wpforms' ), 403 );
		}

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to connect ifthenpay.', 'ifthenpay-payments-for-wpforms')], 403);
        }

        $backofficeKey = isset($_POST['backoffice_key'])
            ? sanitize_text_field(wp_unslash((string) $_POST['backoffice_key']))
            : '';

        if ($backofficeKey === '' || preg_match('/^\*+$/', $backofficeKey)) {
            wp_send_json_error(['message' => __('Enter a valid Backoffice Key before connecting.', 'ifthenpay-payments-for-wpforms')], 400);
        }

        if (!preg_match('/^\d{4}-\d{4}-\d{4}-\d{4}$/', $backofficeKey)) {
            wp_send_json_error(['message' => __('The Backoffice is invalid.', 'ifthenpay-payments-for-wpforms')], 400);
        }

        try {
            $gateways = (new IfthenpayClient($backofficeKey))->get_gateway_keys('WPForms');
        } catch (\Throwable) {
            $gateways = [];
        }

        if (empty($gateways)) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: 1: support email link, 2: sign-up link */
                    __('No WPForms gateway found for this Backoffice Key. If you are an ifthenpay client, %1$s to request a WPForms context gateway. If you are not yet a client, %2$s.', 'ifthenpay-payments-for-wpforms'),
                    '<a href="mailto:suporte@ifthenpay.com">' . esc_html__('contact ifthenpay support', 'ifthenpay-payments-for-wpforms') . '</a>',
                    '<a href="' . esc_url(self::SIGNUP_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('become one here', 'ifthenpay-payments-for-wpforms') . '</a>'
                ),
            ], 400);
        }

        self::update_backoffice_key($backofficeKey);

        wp_send_json_success([
            'message'     => __('Backoffice Key connected successfully.', 'ifthenpay-payments-for-wpforms'),
            'masked_key'  => $this->masked_backoffice_key(),
            'status_html' => $this->connection_status_content(),
        ]);
    }

    public function ajax_disconnect_backoffice(): void
    {
        check_ajax_referer('iftp_pbl_settings_connection', 'nonce');
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'ifthenpay-payments-for-wpforms' ), 403 );
		}

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to disconnect ifthenpay.', 'ifthenpay-payments-for-wpforms')], 403);
        }

        self::update_backoffice_key('');
        delete_option('iftp_pbl_gateway_catalog');
        delete_option('iftp_pbl_method_catalog');

        wp_send_json_success([
            'message'     => __('ifthenpay Backoffice Key disconnected.', 'ifthenpay-payments-for-wpforms'),
            'masked_key'  => '',
            'status_html' => $this->connection_status_content(),
        ]);
    }

    private static function update_backoffice_key(string $backofficeKey): void
    {
        if ($backofficeKey === '') {
            delete_option(self::OPTION_BACKOFFICE_KEY);
        } else {
            update_option(self::OPTION_BACKOFFICE_KEY, $backofficeKey, false);
        }
    }

    public static function sanitize_backoffice_key(mixed $value, string $id = '', array $field = [], mixed $previous_value = ''): string
    {
        unset($value, $id, $field, $previous_value);

        return '';
    }

    private function heading_content(): string
    {
        return '<h4>' . esc_html__('ifthenpay | Payment Gateway', 'ifthenpay-payments-for-wpforms') . '</h4><p>' .
            esc_html__('Connect your Backoffice Key here. Per-form gateway selection and method settings are configured inside the form builder.', 'ifthenpay-payments-for-wpforms') .
            '</p>';
    }

    private function connection_status_content(): string
    {
        if (self::get_backoffice_key() !== '') {
            return $this->render_connection_status_card(
                'connected',
                __('Connected', 'ifthenpay-payments-for-wpforms'),
                __('Backoffice Key connected. Gateway keys and methods are configured inside the form builder.', 'ifthenpay-payments-for-wpforms'),
                true
            );
        }

        return $this->render_connection_status_card(
            'disconnected',
            __('Disconnected', 'ifthenpay-payments-for-wpforms'),
            __('Enter your Backoffice Key and click Connect to load your WPForms gateways.', 'ifthenpay-payments-for-wpforms'),
            false
        );
    }

    private function render_connection_status_card(string $status, string $title, string $message, bool $showDisconnect): string
    {
        $html  = '<div id="iftp-pbl-connection-status-card" class="iftp-settings-card is-' . esc_attr($status) . '">';
        $html .= '<div class="iftp-settings-status-row">';
        $html .= '<span class="iftp-status-pill is-' . esc_attr($status) . '">' . esc_html($title) . '</span>';
        $html .= '</div>';
        $html .= '<p class="iftp-settings-status-message">' . wp_kses($message, [
            'a'      => ['href' => [], 'target' => [], 'rel' => []],
            'strong' => [],
            'br'     => [],
        ]) . '</p>';
        $html .= '<div class="iftp-settings-actions">';
        $html .= $showDisconnect
            ? '<button type="button" id="iftp-pbl-disconnect-backoffice" class="wpforms-btn wpforms-btn-md wpforms-btn-light-grey">' . esc_html__('Disconnect', 'ifthenpay-payments-for-wpforms') . '</button>'
            : '<button type="button" id="iftp-pbl-connect-backoffice" class="wpforms-btn wpforms-btn-md wpforms-btn-orange">' . esc_html__('Connect', 'ifthenpay-payments-for-wpforms') . '</button>';
        $html .= '<span class="iftp-settings-message" aria-live="polite"></span>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function masked_backoffice_key(): string
    {
        return self::get_backoffice_key() !== '' ? str_repeat('*', 18) : '';
    }

    private function inline_css(): string
    {
        return '
            .iftp-pbl-backoffice-key-field-hidden{display:none}
            .iftp-settings-card.is-warning{background:#fffdf0;border-color:#f1d38b}
            .iftp-settings-card.is-error{background:#fff5f4;border-color:#f0b8b4}
            .iftp-settings-card p{margin:8px 0 0;color:#50575e}
            .iftp-settings-card .iftp-settings-status-message{margin-bottom:22px}
            .iftp-settings-status-row{display:flex;align-items:center;gap:10px;margin-bottom:4px}
            .iftp-status-pill{display:inline-flex;align-items:center;border-radius:999px;font-size:12px;font-weight:700;line-height:1;padding:6px 11px}
            .iftp-status-pill.is-connected{background:#edf7ed;color:#0f6b2f}
            .iftp-status-pill.is-disconnected{background:#fff4e5;color:#8a4b00}
            .iftp-status-pill.is-warning{background:#fff8db;color:#7a5a00}
            .iftp-status-pill.is-error{background:#fdecea;color:#b42318}
            .iftp-settings-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding-top:16px;border-top:1px solid #f0f0f1}
            .iftp-settings-message{font-size:12px;color:#646970;margin-left:2px}
            .iftp-settings-message.is-success{color:#0f6b2f}
            .iftp-settings-message.is-error{color:#b42318}
        ';
    }

    /**
     * @return array<string, string>
     */
    private function inline_js_data(): array
    {
        return [
            'fieldName'         => self::OPTION_KEY . '[' . self::FIELD_BACKOFFICE_KEY . ']',
            'fieldKey'          => self::FIELD_BACKOFFICE_KEY,
            'mask'              => str_repeat('*', 18),
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('iftp_pbl_settings_connection'),
            'connectLabel'      => __('Connect', 'ifthenpay-payments-for-wpforms'),
            'connectingLabel'   => __('Connecting...', 'ifthenpay-payments-for-wpforms'),
            'disconnectLabel'   => __('Disconnect', 'ifthenpay-payments-for-wpforms'),
            'disconnectingLabel' => __('Disconnecting...', 'ifthenpay-payments-for-wpforms'),
            'genericError'      => __('Connection request failed. Please try again.', 'ifthenpay-payments-for-wpforms'),
            'enterValidKey'     => __('Enter a valid Backoffice Key before connecting.', 'ifthenpay-payments-for-wpforms'),
        ];
    }

    private function inline_js_behavior(): string
    {
        return '
            (function ($) {
                "use strict";
                var cfg        = window.iftpPblSettings || {};
                var fieldName  = cfg.fieldName  || "";
                var fieldKey   = cfg.fieldKey   || "";
                var mask       = cfg.mask       || "";
                var ajaxUrl    = cfg.ajaxUrl    || "";
                var nonce      = cfg.nonce      || "";

                function getField() {
                    return $("input").filter(function () {
                        var $input = $(this);
                        var name   = String($input.attr("name") || "");
                        var id     = String($input.attr("id")   || "");
                        return name === fieldName ||
                            name.indexOf("[" + fieldKey + "]") !== -1 ||
                            id === fieldKey ||
                            id.indexOf(fieldKey) !== -1;
                    }).first();
                }

                function getFieldRow() {
                    var $field = getField();
                    if (!$field.length) { return $(); }
                    var $explicitRow = $field.closest(".iftp-pbl-backoffice-key-field, tr, .wpforms-settings-field, .wpforms-setting-row, .wpforms-field-row, .wpforms-panel-field-row, .wpforms-admin-settings-field").first();
                    if ($explicitRow.length && $explicitRow.get(0) !== $field.get(0)) { return $explicitRow; }
                    var $structuralRow = $field.closest("tr, .wpforms-settings-field, .wpforms-setting-row, .wpforms-field-row, .wpforms-panel-field-row, .wpforms-admin-settings-field").first();
                    if ($structuralRow.length) { return $structuralRow; }
                    return $field.parent();
                }

                function syncBackofficeFieldVisibility() {
                    var $field    = getField();
                    var $row      = getFieldRow();
                    var shouldShow = !$("#iftp-pbl-disconnect-backoffice").length;
                    $field.toggleClass("iftp-pbl-backoffice-key-field-hidden", !shouldShow);
                    $row.toggleClass("iftp-pbl-backoffice-key-field-hidden", !shouldShow);
                }

                function setMessage(message, status) {
                    var $messageEl = $(".iftp-settings-message").first();
                    $messageEl.removeClass("is-success is-error").html(String(message || ""));
                    if (status) { $messageEl.addClass("is-" + status); }
                }

                function replaceStatus(html) {
                    if (!html) { return; }
                    $("#iftp-pbl-connection-status-card").replaceWith(html);
                    syncBackofficeFieldVisibility();
                }

                $(document).on("focus", "input", function () {
                    var $input = $(this);
                    if (!$input.is(getField())) { return; }
                    if (/^\*+$/.test(String($input.val() || "").trim())) {
                        $input.val("");
                    }
                });

                $(document).on("click", "#iftp-pbl-connect-backoffice", function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    var $button      = $(this);
                    var $input       = getField();
                    var backofficeKey = String($input.val() || "").trim();
                    if (!backofficeKey || /^\*+$/.test(backofficeKey)) {
                        setMessage(cfg.enterValidKey || "", "error");
                        $input.trigger("focus");
                        return;
                    }
                    $button.prop("disabled", true).text(cfg.connectingLabel || "");
                    setMessage("", "");
                    $.post(ajaxUrl, { action: "iftp_pbl_connect_backoffice", nonce: nonce, backoffice_key: backofficeKey }, null, "json")
                        .done(function (response) {
                            if (response && response.success) {
                                replaceStatus(response.data && response.data.status_html);
                                getField().val((response.data && response.data.masked_key) || mask);
                                setMessage((response.data && response.data.message) || "", "success");
                                syncBackofficeFieldVisibility();
                                return;
                            }
                            setMessage((response && response.data && response.data.message) || cfg.genericError || "", "error");
                            $button.prop("disabled", false).text(cfg.connectLabel || "");
                        })
                        .fail(function (xhr) {
                            setMessage((xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || cfg.genericError || "", "error");
                            $button.prop("disabled", false).text(cfg.connectLabel || "");
                        });
                });

                $(document).on("click", "#iftp-pbl-disconnect-backoffice", function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    var $button = $(this);
                    $button.prop("disabled", true).text(cfg.disconnectingLabel || "");
                    setMessage("", "");
                    $.post(ajaxUrl, { action: "iftp_pbl_disconnect_backoffice", nonce: nonce }, null, "json")
                        .done(function (response) {
                            if (response && response.success) {
                                replaceStatus(response.data && response.data.status_html);
                                getField().val("");
                                setMessage((response.data && response.data.message) || "", "success");
                                syncBackofficeFieldVisibility();
                                return;
                            }
                            setMessage((response && response.data && response.data.message) || cfg.genericError || "", "error");
                            $button.prop("disabled", false).text(cfg.disconnectLabel || "");
                        })
                        .fail(function (xhr) {
                            setMessage((xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || cfg.genericError || "", "error");
                            $button.prop("disabled", false).text(cfg.disconnectLabel || "");
                        });
                });

                $(function () {
                    var $field = getField();
                    if ($field.length) {
                        var current = String($field.val() || "").trim();
                        if (current && !/^\*+$/.test(current)) {
                            $field.val(mask);
                        }
                        $field.attr("autocomplete", "off");
                    }
                    syncBackofficeFieldVisibility();
                });
            })(jQuery);
        ';
    }
}
