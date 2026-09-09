<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\WPForms;

use Ifthenpay\WPForms\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Pretty Sure!' );
}

class Field extends \WPForms_Field
{
    private const PAY_BUTTON_MAX_VISIBLE_LENGTH = 25;

    private const DEFAULT_BOX_SIZE = 'medium';

    /**
     * @var array<string, string>
     */
    private const BOX_SIZE_MAX_WIDTHS = [
        'small'  => '25%',
        'medium' => '60%',
        'large'  => '100%',
    ];

    /**
     * @var array<string, mixed>
     */
    public array $formData = [];

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;

        parent::__construct();
    }

    public function init(): void
    {
        $this->order = 90;
        $this->group = 'payment';
        $this->icon = 'fa fa-credit-card';
        $this->keywords = 'ifthenpay, payment, gateway, pay by link, wpforms';

        add_filter('wpforms_builder_fields_options', [$this, 'pre_fields_options']);
        add_filter('wpforms_field_properties_' . $this->type, [$this, 'field_properties'], 5, 3);
        add_filter('wpforms_field_new_required', [$this, 'default_required'], 10, 2);
        add_action('wpforms_builder_enqueues', [$this, 'builder_enqueues']);
        // enqueue_block_assets (not enqueue_block_editor_assets): the Gutenberg post editor
        // renders block content inside its own <iframe> document, and assets registered on
        // enqueue_block_editor_assets only reliably load in the outer admin document, not
        // that iframe — leaving the live-rendered payment field there with no border and no
        // dark-mode logo swap (frontend.js never even loads). enqueue_block_assets is the
        // hook WordPress documents as iframe-safe, and also fires on the real frontend
        // (redundant with, but harmless alongside, Plugin::enqueue_frontend_assets()'s own
        // wp_enqueue_scripts-based enqueue of the same handles).
        add_action('enqueue_block_assets', [$this, 'block_editor_enqueues']);
        add_filter('wpforms_builder_strings', [$this, 'builder_js_strings'], 10, 2);
        add_filter('wpforms_builder_field_button_attributes', [$this, 'field_button_attributes'], 10, 3);
        add_filter('wpforms_frontend_foot_submit_classes', [$this, 'hide_submit_button_if_field_exists'], 10, 2);
        add_filter('wpforms_field_new_display_duplicate_button', [$this, 'field_display_duplicate_button'], 10, 2);
        add_filter('wpforms_field_preview_display_duplicate_button', [$this, 'field_display_duplicate_button'], 10, 2);
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $field
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    public function field_properties($properties, $field, $formData): array
    {
        $this->formData = is_array($formData) ? $formData : [];

        return is_array($properties) ? $properties : [];
    }

    /**
     * @param object $form
     * @return void
     */
    public function pre_fields_options($form): void
    {
        if (!isset($form->post_content)) {
            $this->formData = [];
            return;
        }

        $decoded = wpforms_decode($form->post_content);
        $this->formData = is_array($decoded) ? $decoded : [];
    }

    public function default_required($required, $field): bool
    {
        return isset($field['type']) && $field['type'] === $this->type ? true : (bool) $required;
    }

    /**
     * Deliberately does NOT enqueue admin.js/admin.css under a second handle here —
     * Payments::enqueue_builder_assets() already enqueues both (as
     * 'ifthenpay-wpforms-builder') on this same wpforms_builder_enqueues hook, and
     * reliably runs first: Payments is instantiated on plugins_loaded (priority 20, see
     * Plugin::init_components()) while Field is only instantiated later, once WPForms
     * fires its own wpforms_loaded (see Plugin::register_field()) — so Payments' add_action()
     * call for this hook is always registered, and therefore always fires, before Field's.
     * Enqueuing the same two files again under a second handle used to make the whole
     * admin.js IIFE execute twice per builder page load (two independent event bindings,
     * two debounce timers) — this just attaches this field's own localized data onto the
     * single already-enqueued script instead.
     */
    public function builder_enqueues($view): void
    {
        unset($view);

        wp_enqueue_style(
            'ifthenpay-wpforms-frontend',
            IFTP_PBL_URL . 'assets/css/frontend.css',
            [],
            $this->asset_version('assets/css/frontend.css')
        );

        wp_localize_script(
            'ifthenpay-wpforms-builder',
            'ifthenpayWpformsField',
            [
                'type' => $this->type,
                'name' => $this->name,
                'hasConnection' => Settings::get_backoffice_key() !== '',
                'unusableReason' => Settings::get_unusable_reason($this->formData),
            ]
        );
    }

    public function block_editor_enqueues(): void
    {
        wp_enqueue_style(
            'ifthenpay-wpforms-frontend',
            IFTP_PBL_URL . 'assets/css/frontend.css',
            [],
            $this->asset_version('assets/css/frontend.css')
        );

        // Same handle Plugin::enqueue_frontend_assets() registers on the real frontend — the
        // dark-mode logo switcher at the bottom of frontend.js only runs where this actually
        // loads, which previously wasn't inside the block editor's iframe at all (see the
        // enqueue_block_assets note above).
        wp_enqueue_script(
            'ifthenpay-wpforms-frontend',
            IFTP_PBL_URL . 'assets/js/frontend.js',
            ['jquery'],
            $this->asset_version('assets/js/frontend.js'),
            true
        );
    }

    /**
     * @param array<string, mixed> $strings
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public function builder_js_strings($strings, $form): array
    {
        unset($form);

        $strings = is_array($strings) ? $strings : [];

        $strings['ifthenpay_connection_required'] = wp_kses(
            __('<p>ifthenpay account connection is required when using the ifthenpay field.</p><p>To proceed, please go to <strong>WPForms Settings » Payments » ifthenpay</strong> and connect your account.</p>', 'ifthenpay-payments-for-wpforms'),
            ['p' => [], 'strong' => []]
        );

        $strings['ifthenpay_payments_enabled_required'] = wp_kses(
            str_replace('{name}', $this->name, __('<p>{name} must be enabled in the Payments settings when using the field.</p><p>To proceed, please go to <strong>Payments Â» {name}</strong> and check <strong>Enable {name}</strong>.</p>', 'ifthenpay-payments-for-wpforms')),
            ['p' => [], 'strong' => []]
        );

        $strings['ifthenpay_gateway_key_required'] = wp_kses(
            str_replace('{name}', $this->name, __('<p>{name} requires a gateway key to be selected.</p><p>To proceed, please go to <strong>Payments Â» {name}</strong> and choose a <strong>Gateway key</strong>.</p>', 'ifthenpay-payments-for-wpforms')),
            ['p' => [], 'strong' => []]
        );

        return $strings;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $field
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    public function field_button_attributes($attributes, $field, $formData): array
    {
        $attributes = is_array($attributes) ? $attributes : [];

        if (!isset($field['type']) || $field['type'] !== $this->type) {
            return $attributes;
        }

        if (Settings::get_backoffice_key() !== '') {
            return $attributes;
        }

        $attributes['class'][] = 'warning-modal';
        $attributes['class'][] = 'iftp-pbl-connection-required';

        return $attributes;
    }

    /**
     * @param array<string, string> $classes
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    public function hide_submit_button_if_field_exists($classes, $formData): array
    {
        if ($this->has_is_field($formData)) {
            $classes[] = 'wpforms-hidden';
        }

        return $classes;
    }

    /**
     * @param array<mixed> $forms
     * @param bool $multiple
     * @return bool
     */
    private function has_is_field($forms, $multiple = false): bool
    {
        return false !== wpforms_has_field_type($this->type, $forms, $multiple);
    }

    /**
     * @param bool $display
     * @param array<string, mixed> $field
     * @return bool
     */
    public function field_display_duplicate_button($display, $field): bool
    {
        return isset($field['type']) && $field['type'] === $this->type ? false : (bool) $display;
    }

    /**
     * @param array<string, mixed> $field
     * @return void
     */
    public function field_options($field): void
    {
        $this->field_option('basic-options', $field, ['markup' => 'open']);

        $this->render_text_option(
            $field,
            'label',
            __('Label', 'ifthenpay-payments-for-wpforms'),
            !empty($field['label']) ? (string) $field['label'] : $this->name,
            __('Enter text for the form field label.', 'ifthenpay-payments-for-wpforms')
        );

        $this->render_text_option(
            $field,
            'pay_now_label',
            __('Pay now button label', 'ifthenpay-payments-for-wpforms'),
            !empty($field['pay_now_label']) ? (string) $field['pay_now_label'] : __('Pay now', 'ifthenpay-payments-for-wpforms'),
            __('Enter the label for the pay now button.', 'ifthenpay-payments-for-wpforms'),
            '',
            'pay_now_button_label_text'
        );

        $this->field_option('basic-options', $field, ['markup' => 'close']);

        $this->field_option('advanced-options', $field, ['markup' => 'open']);

        $this->render_select_option(
            $field,
            'public_box_size',
            __('Box size', 'ifthenpay-payments-for-wpforms'),
            isset($field['public_box_size']) ? (string) $field['public_box_size'] : self::DEFAULT_BOX_SIZE,
            [
                'small'  => __('Small', 'ifthenpay-payments-for-wpforms'),
                'medium' => __('Medium', 'ifthenpay-payments-for-wpforms'),
                'large'  => __('Large', 'ifthenpay-payments-for-wpforms'),
            ],
            __('Choose how wide the ifthenpay payment box appears.', 'ifthenpay-payments-for-wpforms')
        );

        $this->render_text_option(
            $field,
            'public_box_css_classes',
            __('Public box CSS classes', 'ifthenpay-payments-for-wpforms'),
            isset($field['public_box_css_classes']) ? (string) $field['public_box_css_classes'] : '',
            __('Add one or more CSS classes to the public ifthenpay field box, separated by spaces.', 'ifthenpay-payments-for-wpforms'),
            'my-iftp-box custom-payment-box'
        );

        $this->render_text_option(
            $field,
            'public_button_css_classes',
            __('Public button CSS classes', 'ifthenpay-payments-for-wpforms'),
            isset($field['public_button_css_classes']) ? (string) $field['public_button_css_classes'] : '',
            __('Add one or more CSS classes to the public ifthenpay pay button, separated by spaces.', 'ifthenpay-payments-for-wpforms'),
            'my-iftp-button custom-pay-button'
        );

        $this->render_toggle_option(
            $field,
            'label_hide',
            __('Hide WPForms label', 'ifthenpay-payments-for-wpforms'),
            isset($field['label_hide']) && (string) $field['label_hide'] === '1',
            __('Hide the normal WPForms field label on the frontend.', 'ifthenpay-payments-for-wpforms')
        );

        $this->render_toggle_option(
            $field,
            'hide_logo',
            __('Hide ifthenpay logo', 'ifthenpay-payments-for-wpforms'),
            isset($field['hide_logo']) && (string) $field['hide_logo'] === '1',
            __('Hide the ifthenpay logo shown in the payment box.', 'ifthenpay-payments-for-wpforms')
        );

        $this->render_toggle_option(
            $field,
            'hide_public_box',
            __('Show pay button only', 'ifthenpay-payments-for-wpforms'),
            isset($field['hide_public_box']) && (string) $field['hide_public_box'] === '1',
            __('Hide the payment methods box and show only the Pay now button.', 'ifthenpay-payments-for-wpforms')
        );

        $this->field_option('advanced-options', $field, ['markup' => 'close']);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function render_text_option(array $field, string $slug, string $label, string $value, string $tooltip = '', string $placeholder = '', string $class = ''): void
    {
        $output = $this->field_element(
            'label',
            $field,
            [
                'slug' => $slug,
                'value' => $label,
                'tooltip' => $tooltip,
            ],
            false
        );

        $atts = [
            'slug' => $slug,
            'value' => esc_attr($value),
        ];

        if ($placeholder !== '') {
            $atts['placeholder'] = $placeholder;
        }

        if ($class !== '') {
            $atts['class'] = $class;
        }

        $output .= $this->field_element('text', $field, $atts, false);

        $this->render_field_option_row($field, $slug, $output);
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, string> $options
     */
    private function render_select_option(array $field, string $slug, string $label, string $value, array $options, string $tooltip = ''): void
    {
        $output = $this->field_element(
            'label',
            $field,
            [
                'slug' => $slug,
                'value' => $label,
                'tooltip' => $tooltip,
            ],
            false
        );

        $output .= $this->field_element(
            'select',
            $field,
            [
                'slug' => $slug,
                'value' => $value,
                'options' => $options,
            ],
            false
        );

        $this->render_field_option_row($field, $slug, $output);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function render_toggle_option(array $field, string $slug, string $label, bool $checked, string $tooltip = ''): void
    {
        $fieldId = isset($field['id']) ? (string) $field['id'] : '';
        $output = '<input type="hidden" name="fields[' . esc_attr($fieldId) . '][' . esc_attr($slug) . ']" value="0" />';

        $output .= $this->field_element(
            'toggle',
            $field,
            [
                'slug' => $slug,
                'value' => $checked ? '1' : '0',
                'checked' => $checked,
                'desc' => $label,
                'tooltip' => $tooltip,
            ],
            false
        );

        $this->render_field_option_row($field, $slug, $output);
    }

    /**
	 * @param array<string, mixed> $field
	 */
	private function render_field_option_row(array $field, string $slug, string $content): void
	{
		$output = $this->field_element(
			'row',
			$field,
			[
				'slug'    => $slug,
				'content' => $content,
			],
			false
		);

		$allowed_html = array_merge(
			wp_kses_allowed_html( 'post' ),
			[
				'input'  => [
					'type'        => true,
					'name'        => true,
					'value'       => true,
					'checked'     => true,
					'class'       => true,
					'id'          => true,
					'placeholder' => true,
				],
				'select' => [
					'name'  => true,
					'id'    => true,
					'class' => true,
				],
				'option' => [
					'value'    => true,
					'selected' => true,
				],
				'label'  => [
					'for'   => true,
					'class' => true,
				],
			]
		);

		echo wp_kses( (string) $output, $allowed_html );
	}

    /**
     * @param array<string, mixed> $field
     * @return void
     */
    public function field_preview($field): void
    {
        $label = !empty($field['label']) ? (string) $field['label'] : $this->name;
        $hideLabel = isset($field['label_hide']) && (string) $field['label_hide'] === '1';

        echo '<label class="label-title">';
        echo '<span class="hidden_text" title="' . esc_attr__('Label Hidden', 'ifthenpay-payments-for-wpforms') . '" style="' . ($hideLabel ? '' : 'display:none;') . '"><i class="fa fa-eye-slash"></i></span>';
        echo '<span class="empty_text" title="' . esc_attr__('Every field should have a descriptive label. You can hide the label from the Advanced tab.', 'ifthenpay-payments-for-wpforms') . '"><i class="fa fa-exclamation-triangle"></i></span>';
        echo '<span class="text" style="' . ($hideLabel ? 'color:#999;' : '') . '">' . esc_html($label) . '</span>';
        echo '<span class="required">*</span>';
        echo '</label>';

        if (Settings::get_backoffice_key() === '') {
            echo '<div class="iftp-pbl-runtime-warning">';
            echo '<div class="wpforms-iftp-pbl-warning-div">';
            echo '<p class="wpforms-iftp-pbl-warning-title">' . esc_html__('Configuration Required', 'ifthenpay-payments-for-wpforms') . '</p>';
            echo '<div class="wpforms-iftp-pbl-warning-body">';
            echo '<p class="wpforms-iftp-pbl-warning-message">' . esc_html__('Connect your ifthenpay Backoffice Key in WPForms Settings » Payments » ifthenpay to use this field.', 'ifthenpay-payments-for-wpforms') . '</p>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            return;
        }

        $this->render_payment_field_preview($field);
    }

    /**
     * @param array<string, mixed> $field
     * @param mixed $deprecated
     * @param array<string, mixed> $formData
     * @return void
     */
    public function field_display($field, $deprecated, $formData): void
    {
        unset($deprecated);

        $formData = is_array($formData) ? $formData : [];
        $availability = $this->get_frontend_field_availability($formData);
        $gatewayKey = (string) $availability['gateway_key'];
        $payNowLabel = !empty($field['pay_now_label']) ? (string) $field['pay_now_label'] : __('Pay now', 'ifthenpay-payments-for-wpforms');
        $publicBoxClasses = $this->sanitize_custom_class_list(isset($field['public_box_css_classes']) ? (string) $field['public_box_css_classes'] : '');
        $publicButtonClasses = $this->sanitize_custom_class_list(isset($field['public_button_css_classes']) ? (string) $field['public_button_css_classes'] : '');
        $boxMaxWidth = $this->get_box_max_width(isset($field['public_box_size']) ? (string) $field['public_box_size'] : self::DEFAULT_BOX_SIZE);
        $defaultMethod = (string) ($availability['default_method'] ?? '');
        $enabledMethods = is_array($availability['enabled_methods']) ? $availability['enabled_methods'] : [];
        $labelPresentation = $this->get_pay_button_presentation($payNowLabel);
        $hideLogo = isset($field['hide_logo']) && (string) $field['hide_logo'] === '1';
        $hidePublicBox = isset($field['hide_public_box']) && (string) $field['hide_public_box'] === '1';

        $boxClass = trim('iftp-pbl-public-box iftp-pbl-field-block-section ' . $publicBoxClasses);
        $buttonClass = trim('iftp-pbl-pay-now-button iftp-pbl-public-button ' . $publicButtonClasses);

        $wrapperClass = 'wpforms-field wpforms-field-' . esc_attr($this->type) . ' iftp-pbl-live-field' . ($hidePublicBox ? ' iftp-pbl-box-hidden' : '');
        $wrapperStyle = $hidePublicBox ? 'padding:15px 0 0;margin:0;' : 'margin:16px 0 0;';
        $confirmations = is_array($availability['config']['confirmations'] ?? null) ? $availability['config']['confirmations'] : [];

        echo '<div class="' . $wrapperClass . '" data-iftp-config-ready="' . esc_attr(!empty($availability['is_ready']) ? '1' : '0') . '" data-iftp-disabled-reason="' . esc_attr((string) $availability['message']) . '" data-iftp-confirmations="' . esc_attr((string) wp_json_encode($this->build_confirmation_overrides($confirmations))) . '" style="' . esc_attr($wrapperStyle) . '">';
        echo '<input type="hidden" class="iftp-pbl-payment-id-input" name="iftp_pbl_payment_id" value="">';
        echo '<input type="hidden" class="iftp-pbl-paid-now-return-input" name="iftp_pbl_paid_now_return" value="">';
        echo '<input type="hidden" class="iftp-pbl-nonce-input" name="iftp_pbl_nonce" value="' . esc_attr(wp_create_nonce('iftp_pbl_frontend')) . '">';

        echo '<div class="iftp-pbl-field-shell">';

        if (!$hidePublicBox) {
            echo '<div class="' . esc_attr($boxClass) . '" style="max-width:' . esc_attr($boxMaxWidth) . ';font-family:inherit;text-align:left;box-sizing:border-box;padding:12px;">';

            echo '<div class="iftp-pbl-header-row" style="display:flex;align-items:center;">';
            if (!$hideLogo) {
                echo '<img class="iftp-pbl-header-icon" style="width:30px;height:28px;margin-right:20px;flex:none;" src="' . esc_url(IFTP_PBL_URL . 'assets/images/mini_icon.svg') . '" data-logo-dark="' . esc_url(IFTP_PBL_URL . 'assets/images/icon-white.svg') . '" alt="' . esc_attr__('Ifthenpay', 'ifthenpay-payments-for-wpforms') . '">';
            }

            $this->render_enabled_methods_section($enabledMethods, $defaultMethod, $this->get_catalog_keyed_by_entity((int) ($formData['id'] ?? 0)));

            echo '</div>';

            echo '</div>';
        }

        echo '<div style="' . ($hidePublicBox ? '' : 'margin-top:30px;') . '">';
        echo '<button class="' . esc_attr($buttonClass) . '" data-gateway-key="' . esc_attr($gatewayKey) . '" title="' . esc_attr($labelPresentation['full']) . '">' . esc_html($labelPresentation['display']) . '</button>';
        echo '</div>';
        echo '</div>';

		echo '<div class="iftp-pbl-runtime-warning" style="display:none;margin-top:10px;max-width:' . esc_attr($boxMaxWidth) . ';"></div>';
        echo '</div>';
    }

    /**
     * Builds the data passed to the frontend popup (see assets/js/frontend.js
     * showOutcomeNotice()/handleOutcome()) so a form's custom confirmation settings can
     * override this plugin's global default text, or — for paid only — send the
     * customer to a page/URL instead of showing the popup at all. Only includes a
     * "message" when the admin actually typed one, so an empty per-form field still falls
     * back to the global default rather than showing a blank popup. A "page"/"redirect"
     * type that can't actually resolve to a URL (page deleted, redirect URL left blank)
     * silently falls back to "message" rather than sending the customer nowhere.
     *
     * @param array<string, mixed> $confirmations
     * @return array<string, array<string, mixed>>
     */
    private function build_confirmation_overrides(array $confirmations): array
    {
        $overrides = [];

        foreach (['paid', 'pending', 'cancelled', 'failed'] as $status) {
            $entry = isset($confirmations[$status]) && is_array($confirmations[$status]) ? $confirmations[$status] : [];
            $message = trim((string) ($entry['message'] ?? ''));

            $statusOverride = [];
            if ($message !== '') {
                $statusOverride['message'] = $message;
            }

            if ($status === 'paid') {
                $type = (string) ($entry['type'] ?? 'message');
                $redirectUrl = '';

                if ($type === 'page') {
                    $pageId = absint($entry['page'] ?? 0);
                    $redirectUrl = $pageId > 0 ? (string) get_permalink($pageId) : '';
                } elseif ($type === 'redirect') {
                    $redirectUrl = trim((string) ($entry['redirect'] ?? ''));
                }

                $statusOverride['type'] = $redirectUrl !== '' ? $type : 'message';
                if ($redirectUrl !== '') {
                    $statusOverride['redirect_url'] = $redirectUrl;
                }
            }

            if ($status === 'paid') {
                $statusOverride['entry_preview'] = !empty($entry['entry_preview']);
            }

            $overrides[$status] = $statusOverride;
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    private function get_frontend_field_availability(array $formData): array
    {
        $config = Settings::get_form_payment_settings($formData);
        $enabled = !empty($config['enabled']);
        $gatewayKey = isset($config['gateway_key']) ? (string) $config['gateway_key'] : '';
        $defaultMethod = isset($config['default_method']) ? (string) $config['default_method'] : '';
        $enabledMethods = $this->get_enabled_methods_from_config($config, $gatewayKey);
        $message = '';
        $isReady = true;

        if (Settings::get_backoffice_key() === '') {
            $isReady = false;
            $message = Settings::get_unusable_reason($formData);
        } elseif (!$enabled) {
            $isReady = false;
            $message = __('This field is disabled because ifthenpay is turned off in the Payments tab for this form.', 'ifthenpay-payments-for-wpforms');
        } elseif ($gatewayKey === '') {
            $isReady = false;
            $message = __('This field is disabled because no gateway key is selected in the Payments tab.', 'ifthenpay-payments-for-wpforms');
        } elseif (empty($enabledMethods)) {
            $isReady = false;
            $message = __('This field is disabled because no payment methods are enabled for the selected gateway.', 'ifthenpay-payments-for-wpforms');
        }

        return [
            'config' => $config,
            'enabled' => $enabled,
            'gateway_key' => $gatewayKey,
            'default_method' => $defaultMethod,
            'enabled_methods' => $enabledMethods,
            'is_ready' => $isReady,
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $config  Pre-processed output of Settings::get_form_payment_settings().
     * @param string $gatewayKey
     * @return array<int, string>
     */
    private function get_enabled_methods_from_config(array $config, string $gatewayKey): array
    {
        if ($gatewayKey === '') {
            return [];
        }

        $methods = isset($config['methods']) && is_array($config['methods']) ? $config['methods'] : [];

        $enabledMethods = [];
        foreach ($methods as $entity => $methodConfig) {
            if (!empty($methodConfig['enabled'])) {
                $enabledMethods[] = (string) $entity;
            }
        }

        return $enabledMethods;
    }

    /**
     * @param array<int, string> $enabledMethods
     * @param string $defaultMethod
     * @param array<string, array<string, string>> $catalog
     * @return void
     */
    private function render_enabled_methods_section(array $enabledMethods, string $defaultMethod, array $catalog): void
    {
        if (empty($enabledMethods)) {
            return;
        }

        echo '<span class="iftp-pbl-header-divider" aria-hidden="true"></span>';
        // Inline, not just the iftp-pbl-preview-methods class: this markup also renders
        // inside the Gutenberg block editor's post-content iframe (a separate document from
        // the admin page), where a plugin stylesheet enqueued via enqueue_block_editor_assets
        // doesn't reliably load. Without this flex rule applying, each method-item span below
        // (itself display:flex, i.e. block-level) stacks vertically instead of laying out in
        // a row.
        echo '<div class="iftp-pbl-methods-section iftp-pbl-preview-methods" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;">';

        foreach ($enabledMethods as $entity) {
            $meta        = $catalog[$entity] ?? ['entity' => $entity, 'label' => $entity, 'logo' => '', 'logo_dark' => ''];
            $methodName  = (string) $meta['label'];
            $logoUrl     = (string) $meta['logo'];
            $logoDarkUrl = (string) ($meta['logo_dark'] ?? '');
            $isDefault   = $defaultMethod !== '' && $defaultMethod === $entity;
            // CCARD's light and dark-mode logo assets have wildly different native pixel
            // widths (light ~85px, dark ~400px, both roughly the same aspect ratio at that
            // width) — no single fixed container width fits both without squeezing one of
            // them out of proportion. Sizing by height only and letting width follow the
            // image's own aspect ratio (auto) makes each variant render at its own correct
            // width instead.
            $isCard      = $entity === 'CCARD';
            $widthCss    = $isCard ? 'width:auto;' : 'width:30px;';
            $logoStyle   = 'display:flex;align-items:center;justify-content:center;' . $widthCss . 'height:25px;box-sizing:border-box;';
            // Non-card icons still need max-width to stay inside their fixed 30px box; CCARD's
            // box is auto-width (sized to the image itself), so a max-width there would just
            // constrain the image against its own natural size — leave it unset. CCARD is also
            // capped a touch shorter than the other 25px icons (20px) — at the full 25px it
            // reads oversized next to them.
            $imgStyle    = $isCard ? 'max-height:20px;object-fit:contain;' : 'max-width:100%;max-height:25px;object-fit:contain;';

            echo '<span class="iftp-pbl-method-item' . ($isDefault ? ' is-default' : '') . '" data-entity="' . esc_attr($entity) . '" style="' . esc_attr($logoStyle) . '">';
            if ($logoUrl !== '') {
                echo '<img src="' . esc_url($logoUrl) . '"'
                    . ( $logoDarkUrl !== '' ? ' data-logo-dark="' . esc_attr($logoDarkUrl) . '"' : '' )
                    . ' alt="' . esc_attr($methodName) . '" title="' . esc_attr($methodName) . '" style="' . esc_attr($imgStyle) . '">';
            } else {
                echo esc_html($methodName);
            }
            echo '</span>';
        }

        echo '</div>';
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function get_catalog_keyed_by_entity(int $formId): array
    {
        $catalog = [];

        if ($formId > 0) {
            $table = get_option('iftp_pbl_table_form_' . $formId);
            if (is_array($table) && !empty($table['pay_methods'])) {
                foreach ($table['pay_methods'] as $method) {
                    $entity = strtoupper((string) ($method['entity'] ?? ''));
                    if ($entity === '') {
                        continue;
                    }
                    $label = isset($method['label']) && $method['label'] !== '' ? (string) $method['label'] : $entity;
                    $catalog[$entity] = [
                        'entity'    => $entity,
                        'label'     => $label,
                        'logo'      => (string) ($method['img_url']      ?? ''),
                        'logo_dark' => (string) ($method['img_url_dark'] ?? ''),
                    ];
                }
            }
        }

        $methodCatalog = (array) get_option('iftp_pbl_method_catalog', []);
        foreach ($methodCatalog as $method) {
            $entity = strtoupper((string) ($method['entity'] ?? ''));
            if ($entity === '') {
                continue;
            }
            $logo     = (string) ($method['logo']      ?? '');
            $logoDark = (string) ($method['logo_dark'] ?? '');
            if (!isset($catalog[$entity])) {
                $catalog[$entity] = [
                    'entity'    => $entity,
                    'label'     => (string) ($method['label'] ?? $entity),
                    'logo'      => $logo,
                    'logo_dark' => $logoDark,
                ];
            } else {
                if ($catalog[$entity]['logo']      === '' && $logo !== '')     { $catalog[$entity]['logo']      = $logo; }
                if ($catalog[$entity]['logo_dark'] === '' && $logoDark !== '') { $catalog[$entity]['logo_dark'] = $logoDark; }
            }
        }

        return $catalog;
    }

    private function get_box_max_width(string $size): string
    {
        return self::BOX_SIZE_MAX_WIDTHS[$size] ?? self::BOX_SIZE_MAX_WIDTHS[self::DEFAULT_BOX_SIZE];
    }

    /**
     * @param string $value
     * @return string
     */
    private function sanitize_custom_class_list(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $classes = preg_split('/\s+/', $value);
        $classes = is_array($classes) ? $classes : [];
        $out = [];

        foreach ($classes as $className) {
            $className = sanitize_html_class((string) $className);
            if ($className !== '') {
                $out[] = $className;
            }
        }

        return implode(' ', array_values(array_unique($out)));
    }

    /**
     * @param string $label
     * @return array<string, string>
     */
    private function get_pay_button_presentation(string $label): array
    {
        $fullLabel = trim(wp_strip_all_tags($label));

        if ($fullLabel === '') {
            $fullLabel = __('Pay now', 'ifthenpay-payments-for-wpforms');
        }

        $length = function_exists('mb_strlen') ? mb_strlen($fullLabel) : strlen($fullLabel);
        $displayLabel = $fullLabel;
        $fontSize = 1.0;
        $letterSpacing = 0.08;

        if ($length > self::PAY_BUTTON_MAX_VISIBLE_LENGTH) {
            $displayLabel = $this->truncate_text($fullLabel, self::PAY_BUTTON_MAX_VISIBLE_LENGTH);
            $fontSize = 0.74;
            $letterSpacing = 0.02;
        } elseif ($length > 20) {
            $fontSize = 0.82;
            $letterSpacing = 0.03;
        } elseif ($length > 16) {
            $fontSize = 0.88;
            $letterSpacing = 0.05;
        } elseif ($length > 12) {
            $fontSize = 0.95;
            $letterSpacing = 0.06;
        }

        return [
            'full' => $fullLabel,
            'display' => $displayLabel,
            'font_size' => number_format($fontSize, 2, '.', '') . 'rem',
            'letter_spacing' => number_format($letterSpacing, 2, '.', '') . 'rem',
        ];
    }

    /**
     * @param string $text
     * @param int $limit
     * @return string
     */
    private function truncate_text(string $text, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length <= $limit) {
            return $text;
        }

        $slice = function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);

        return rtrim((string) $slice) . '...';
    }

    /**
     * Busts the browser cache on every edit to this specific asset, rather than
     * IFTP_PBL_VERSION — a fixed release-version string, unrelated to how often these
     * particular files change. Falls back to IFTP_PBL_VERSION only if the file is somehow
     * missing.
     */
    private function asset_version(string $relativePath): string
    {
        $path = IFTP_PBL_DIR . $relativePath;
        $mtime = file_exists($path) ? filemtime($path) : false;

        return $mtime !== false ? (string) $mtime : IFTP_PBL_VERSION;
    }

    /**
     * @param array<string, mixed> $field
     * @return void
     */
    private function render_payment_field_preview(array $field): void
    {
        $payNowLabel = !empty($field['pay_now_label']) ? (string) $field['pay_now_label'] : __('Pay now', 'ifthenpay-payments-for-wpforms');
        $formData = $this->formData;
        $availability = $this->get_frontend_field_availability($formData);
        $defaultMethod = (string) ($availability['default_method'] ?? '');
        $enabledMethods = is_array($availability['enabled_methods']) ? $availability['enabled_methods'] : [];
        $labelPresentation = $this->get_pay_button_presentation($payNowLabel);
        $boxMaxWidth = $this->get_box_max_width(isset($field['public_box_size']) ? (string) $field['public_box_size'] : self::DEFAULT_BOX_SIZE);
        $hideLogo = isset($field['hide_logo']) && (string) $field['hide_logo'] === '1';
        $hidePublicBox = isset($field['hide_public_box']) && (string) $field['hide_public_box'] === '1';

        echo '<div class="iftp-pbl-preview-container" style="margin:16px 0;">';

        if (!$hidePublicBox) {
            echo '<div class="iftp-pbl-public-box iftp-pbl-field-block-section " style="max-width:' . esc_attr($boxMaxWidth) . ';font-family:inherit;text-align:left;box-sizing:border-box;padding:2px;border: 1px solid #cccccc !important;border-radius: 4px!important;background-color:#fff !important;">';

            echo '<div class="iftp-pbl-header-row" style="display:flex;align-items:center;">';
            if (!$hideLogo) {
                echo '<img class="iftp-pbl-header-icon" style="width:30px;height:28px;margin-right:20px;flex:none;" src="' . esc_url(IFTP_PBL_URL . 'assets/images/mini_icon.svg') . '" alt="' . esc_attr__('Ifthenpay', 'ifthenpay-payments-for-wpforms') . '">';
            }

            $this->render_enabled_methods_section($enabledMethods, $defaultMethod, $this->get_catalog_keyed_by_entity((int) ($this->formData['id'] ?? 0)));

            echo '</div>';
            echo '</div>';
        }

        echo '<div style="' . ($hidePublicBox ? '' : 'margin-top:20px;') . '">';
        echo '<button class="iftp-pbl-pay-now-example-button" style="background:#999c9e;border:none;border-radius:4px;color:#ffffff;cursor:pointer;font-size:17px;font-weight:600;line-height:21px;padding:10px 15px;" title="' . esc_attr($labelPresentation['full']) . '" disabled>' . esc_html($labelPresentation['display']) . '</button>';
        echo '</div>';
        echo '</div>';
    }
}
