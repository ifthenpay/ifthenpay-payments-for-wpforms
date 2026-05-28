<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\WPForms\Admin\Settings;
use Ifthenpay\WPForms\Builder\Field;
use Ifthenpay\WPForms\Builder\Payments;
use Ifthenpay\WPForms\Builder\Process;

final class Plugin {

	private static ?self $instance = null;
	private ?Payments $payments = null;
	private ?Process $process = null;

	/**
	 * Returns the singleton instance of the Plugin class.
	 * @return self Returns the single instance of the Plugin
	 */
	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initializes WordPress hooks for the plugin lifecycle.
	 * @return void
	 */
	public function init(): void {
		add_action( 'plugins_loaded', [ $this, 'init_components' ], 20 );
		add_action( 'wpforms_loaded', [ $this, 'register_field' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
	}

	/**
	 * Initializes plugin components after WordPress plugins are loaded.
	 * Checks WPForms dependency and registers admin and processing classes.
	 * @return void
	 */
	public function init_components(): void {
		if ( ! defined( 'WPFORMS_VERSION' ) ) {
			add_action( 'admin_notices', [ $this, 'missing_wpforms_notice' ] );

			return;
		}

		if ( is_admin() ) {
			new Settings();

			if ( function_exists( 'wpforms_is_admin_page' ) && wpforms_is_admin_page( 'builder' ) ) {
				$this->payments = new Payments( IFTP_PBL_GATEWAY_LABEL, IFTP_PBL_SLUG );
			}
		}

		add_action( 'wp_ajax_iftp_pbl_activate_payment_method', fn() => $this->get_payments()->ajax_activate_payment_method() );
		add_action( 'wp_ajax_iftp_pbl_load_gateway_methods',   fn() => $this->get_payments()->ajax_load_gateway_methods() );

		$this->process = new Process( IFTP_PBL_SLUG );
		add_action( 'wp_ajax_iftp_pbl_create_pay_button_payment',        [ $this->process, 'ajax_create_pay_button_payment' ] );
		add_action( 'wp_ajax_nopriv_iftp_pbl_create_pay_button_payment', [ $this->process, 'ajax_create_pay_button_payment' ] );
		add_action( 'wp_ajax_iftp_pbl_verify_payment',                   [ $this->process, 'ajax_verify_payment' ] );
		add_action( 'wp_ajax_nopriv_iftp_pbl_verify_payment',            [ $this->process, 'ajax_verify_payment' ] );
		add_action( 'wp_ajax_iftp_pbl_cancel_payment',                   [ $this->process, 'ajax_cancel_payment' ] );
		add_action( 'wp_ajax_nopriv_iftp_pbl_cancel_payment',            [ $this->process, 'ajax_cancel_payment' ] );
	}

	/**
	 * Registers the custom WPForms payment field.
	 * @return void
	 */
	public function register_field(): void {
		if ( ! class_exists( '\WPForms_Field' ) ) {
			return;
		}

		new Field( IFTP_PBL_GATEWAY_LABEL, IFTP_PBL_FIELD_TYPE );
	}

	/**
	 * Displays an admin notice when WPForms is missing or inactive.
	 * @return void
	 */
	public function missing_wpforms_notice(): void {
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'ifthenpay | Payment Gateway', 'ifthenpay-payments-for-wpforms' ),
			esc_html__( 'requires WPForms to be installed and active.', 'ifthenpay-payments-for-wpforms' )
		);
	}

	/**
	 * Enqueue frontend JS and CSS assets for the payment button and modal system.
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		wp_enqueue_script(
			'ifthenpay-wpforms-frontend',
			IFTP_PBL_URL . 'assets/js/frontend.js',
			[ 'jquery' ],
			IFTP_PBL_VERSION,
			true
		);

		wp_enqueue_style(
			'ifthenpay-wpforms-frontend',
			IFTP_PBL_URL . 'assets/css/frontend.css',
			[],
			IFTP_PBL_VERSION
		);

		wp_localize_script(
			'ifthenpay-wpforms-frontend',
			'iftpPblFrontend',
			[
				'ajax_url'                        => admin_url( 'admin-ajax.php' ),
				'ajax_nonce'                      => wp_create_nonce( 'iftp_pbl_frontend' ),
				'opening_text'                    => __( 'Opening payment...', 'ifthenpay-payments-for-wpforms' ),
				'processing_text'                 => __( 'Processing payment...', 'ifthenpay-payments-for-wpforms' ),
				'warning_missing_amount'          => __( 'The payment total is not ready yet. Please review the form and try again.', 'ifthenpay-payments-for-wpforms' ),
				'warning_config_title'            => __( 'Configuration Required', 'ifthenpay-payments-for-wpforms' ),
				'warning_gateway_conflict_title'  => __( 'Heads up! Another payment gateway is currently active', 'ifthenpay-payments-for-wpforms' ),
				'warning_gateway_conflict_message' => __( 'Another payment gateway is currently active on this form. The ifthenpay button is unavailable while that gateway is active.', 'ifthenpay-payments-for-wpforms' ),
				'warning_payment_error_title'     => __( 'Unable to open payment', 'ifthenpay-payments-for-wpforms' ),
			]
		);
	}

	private function get_payments(): Payments {
		return $this->payments ??= new Payments( IFTP_PBL_GATEWAY_LABEL, IFTP_PBL_SLUG );
	}

	private function __construct() {}
}
