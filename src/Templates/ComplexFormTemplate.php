<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * "ifthenpay Complex Template" — a 3-page demo form (progress bar, page breaks, a
 * payment dropdown, a spam-check field, and the ifthenpay | Payment Gateway field on
 * the final page) showing a more realistic multi-step checkout than the single-page
 * ExampleFormTemplate.
 *
 * See ExampleFormTemplate's docblock for why `$core = true` + a low `$priority` is what
 * makes this sort near the top of the Add New Form screen instead of using the
 * plugin-facing `wpforms_form_templates` filter.
 */
class ComplexFormTemplate extends \WPForms_Template {

	public function init() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

		$this->name        = __( 'ifthenpay Complex Template', 'ifthenpay-payments-for-wpforms' );
		$this->slug        = 'ifthenpay-complex-form-template';
		$this->source      = 'ifthenpay-payments-for-wpforms';
		$this->categories  = 'all';
		$this->core        = true;
		// Same priority as ExampleFormTemplate (0) — both beat every built-in template's
		// priority of 1, and Plugin::register_templates() instantiates this one second, so
		// insertion order (WordPress preserves it for same-priority filter callbacks) keeps
		// it right after ExampleFormTemplate rather than ahead of it.
		$this->priority    = 0;
		$this->description = __( 'A 3-step example checkout showing the ifthenpay | Payment Gateway field alongside page breaks, a payment dropdown, and a spam-check field.', 'ifthenpay-payments-for-wpforms' );
		$this->icon        = IFTP_PBL_URL . 'assets/images/icon_templates.svg';
		// See ExampleFormTemplate's docblock re: reusing $icon as $thumbnail and the
		// CSS-driven card styling.
		$this->thumbnail   = IFTP_PBL_URL . 'assets/images/icon_templates.svg';

		$this->data = [
			'fields'   => [
				// Special settings-only entry — WPForms renders the "Step X of Y" progress
				// bar from this rather than from an inline field (see
				// \WPForms\Forms\Fields\Pagebreak\Field, position === 'top').
				'1' => [
					'id'            => '1',
					'type'          => 'pagebreak',
					'label'         => __( 'Page Break', 'ifthenpay-payments-for-wpforms' ),
					'position'      => 'top',
					'indicator'     => 'progress',
					'progress_text' => __( 'Step {current_page} of {last_page}', 'ifthenpay-payments-for-wpforms' ),
					'next'          => __( 'Next', 'ifthenpay-payments-for-wpforms' ),
				],
				'2' => [
					'id'       => '2',
					'type'     => 'name',
					'format'   => 'first-last',
					'label'    => __( 'Name', 'ifthenpay-payments-for-wpforms' ),
					'required' => '1',
					'size'     => 'medium',
				],
				'3' => [
					'id'       => '3',
					'type'     => 'email',
					'label'    => __( 'Email', 'ifthenpay-payments-for-wpforms' ),
					'required' => '1',
					'size'     => 'medium',
				],
				'4' => [
					'id'          => '4',
					'type'        => 'text',
					'label'       => __( 'Address', 'ifthenpay-payments-for-wpforms' ),
					'size'        => 'medium',
					'placeholder' => '',
					'css'         => '',
				],
				// Inline page break — ends page 1.
				'5' => [
					'id'   => '5',
					'type' => 'pagebreak',
					'next' => __( 'Next', 'ifthenpay-payments-for-wpforms' ),
				],
				'6' => [
					'id'      => '6',
					// The WPForms Payment Fields "Dropdown Items" field (\WPForms\Forms\Fields\PaymentSelect\Field),
					// not the Standard Fields "select" one of the same display name — matches its own defaults.
					'type'    => 'payment-select',
					'label'   => __( 'Dropdown Items', 'ifthenpay-payments-for-wpforms' ),
					'choices' => [
						'1' => [
							'label'   => __( 'First Item', 'ifthenpay-payments-for-wpforms' ),
							'value'   => '10',
							'default' => '',
						],
						'2' => [
							'label'   => __( 'Second Item', 'ifthenpay-payments-for-wpforms' ),
							'value'   => '25',
							'default' => '',
						],
						'3' => [
							'label'   => __( 'Third Item', 'ifthenpay-payments-for-wpforms' ),
							'value'   => '50',
							'default' => '',
						],
						'4' => [
							'label'   => __( 'Fourth Item', 'ifthenpay-payments-for-wpforms' ),
							'value'   => '100',
							'default' => '',
						],
					],
				],
				'7' => [
					'id'       => '7',
					// \WPForms\Forms\Fields\CustomCaptcha\Field — 'format' => 'text' keeps its
					// bundled default question/answer pair ("What is 7+4?" / "11") rather than
					// generating a random equation every page load.
					'type'     => 'captcha',
					'label'    => __( 'Custom Captcha', 'ifthenpay-payments-for-wpforms' ),
					'format'   => 'text',
					'required' => '1',
				],
				// Inline page break — ends page 2.
				'8' => [
					'id'   => '8',
					'type' => 'pagebreak',
					'next' => __( 'Next', 'ifthenpay-payments-for-wpforms' ),
				],
				'9' => [
					'id'    => '9',
					'type'  => 'payment-total',
					'label' => __( 'Total', 'ifthenpay-payments-for-wpforms' ),
				],
				'10' => [
					'id'              => '10',
					// Matches IFTP_PBL_FIELD_TYPE (defined in the plugin's bootstrap file, loaded
					// well before any template can be instantiated) without a hard class
					// dependency on Builder\Field.
					'type'            => IFTP_PBL_FIELD_TYPE,
					'label'           => IFTP_PBL_GATEWAY_LABEL,
					'pay_now_label'   => __( 'Pay now', 'ifthenpay-payments-for-wpforms' ),
					'public_box_size' => 'medium',
					'required'        => '1',
				],
			],
			'field_id' => 11,
			'settings' => [
				'form_desc'              => '',
				'submit_text'            => __( 'Submit', 'ifthenpay-payments-for-wpforms' ),
				'submit_text_processing' => __( 'Sending...', 'ifthenpay-payments-for-wpforms' ),
				'antispam_v3'            => '1',
				'notification_enable'    => '1',
				'notifications'          => [
					'1' => [
						'email'   => '{admin_email}',
						'replyto' => '{field_id="3"}',
						'message' => '{all_fields}',
					],
				],
				'confirmations'          => [
					'1' => [
						'type'           => 'message',
						'message'        => __( 'Thanks! We will be in touch shortly.', 'ifthenpay-payments-for-wpforms' ),
						'message_scroll' => '1',
					],
				],
				'ajax_submit'            => '1',
			],
			'meta'     => [
				'template' => $this->slug,
			],
		];
	}
}
