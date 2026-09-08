<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * "ifthenpay Simple Template" — a ready-made demo form showing the ifthenpay | Payment
 * Gateway field alongside a few common field types, so a merchant can see a working
 * example instead of building one from scratch.
 *
 * Registered with `$core = true` — WPForms core always merges the results of the
 * `wpforms_form_templates_core` filter ahead of the plugin-facing `wpforms_form_templates`
 * one (see \WPForms\Admin\Builder\Templates::get_templates()), and every built-in template
 * (Blank Form, Simple Contact Form, …) registers on that same core filter at priority 1.
 * A lower priority here means WPForms_Template::template_details() appends this entry
 * before any of those run, putting it first in the Add New Form screen overall.
 */
class ExampleFormTemplate extends \WPForms_Template {

	public function init() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

		$this->name        = __( 'ifthenpay Simple Template', 'ifthenpay-payments-for-wpforms' );
		$this->slug        = 'ifthenpay-example-form-template';
		$this->source      = 'ifthenpay-payments-for-wpforms';
		$this->categories  = 'all';
		$this->core        = true;
		$this->priority    = 0;
		$this->description = __( 'A ready-made example form showing the ifthenpay | Payment Gateway field alongside common field types.', 'ifthenpay-payments-for-wpforms' );
		$this->icon        = IFTP_PBL_URL . 'assets/images/icon_templates.svg';
		// Reuses $icon as the thumbnail so WPForms renders it edge-to-edge (see
		// templates/builder/templates-item.php:48-61) instead of behind its generic
		// placeholder icon. The white padding/background around it, the card's hover
		// border, and the "Create Form" button color are brand-matched via CSS scoped to
		// this template's card ID — see Plugin::template_card_css().
		$this->thumbnail   = IFTP_PBL_URL . 'assets/images/icon_templates.svg';

		$this->data = [
			'fields'   => [
				'1' => [
					'id'       => '1',
					'type'     => 'name',
					'format'   => 'first-last',
					'label'    => __( 'Name', 'ifthenpay-payments-for-wpforms' ),
					'required' => '1',
					'size'     => 'medium',
				],
				'2' => [
					'id'       => '2',
					'type'     => 'email',
					'label'    => __( 'Email', 'ifthenpay-payments-for-wpforms' ),
					'required' => '1',
					'size'     => 'medium',
				],
				'3' => [
					'id'          => '3',
					'type'        => 'text',
					'label'       => __( 'Address', 'ifthenpay-payments-for-wpforms' ),
					'size'        => 'medium',
					'placeholder' => '',
					'css'         => '',
				],
				'4' => [
					'id'      => '4',
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
					],
				],
				'5' => [
					'id'              => '5',
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
			'field_id' => 6,
			'settings' => [
				'form_desc'              => '',
				'submit_text'            => __( 'Submit', 'ifthenpay-payments-for-wpforms' ),
				'submit_text_processing' => __( 'Sending...', 'ifthenpay-payments-for-wpforms' ),
				'antispam_v3'            => '1',
				'notification_enable'    => '1',
				'notifications'          => [
					'1' => [
						'email'   => '{admin_email}',
						'replyto' => '{field_id="2"}',
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
