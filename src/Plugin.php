<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\WPForms\Admin\Settings;
use Ifthenpay\WPForms\Api\WPForms\Field;
use Ifthenpay\WPForms\Api\WPForms\Payments;
use Ifthenpay\WPForms\Api\WPForms\Process;
use Ifthenpay\WPForms\Cron\ExpiredPaymentsCron;
use Ifthenpay\WPForms\Templates\ComplexFormTemplate;
use Ifthenpay\WPForms\Templates\ExampleFormTemplate;
use Ifthenpay\WPForms\Themes\IfthenpayDarkTheme;
use Ifthenpay\WPForms\Themes\IfthenpayLightTheme;

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
		add_action( 'wpforms_loaded', [ $this, 'register_templates' ] );
		add_action( 'wpforms_loaded', [ $this, 'register_theme' ] );
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

		( new ExpiredPaymentsCron() )->boot();

		if ( is_admin() ) {
			new Settings();

			// Always instantiate (not gated to the builder page): the builder's
			// save button POSTs to admin-ajax.php without a `page` param, so
			// wpforms_is_admin_page( 'builder' ) is false on that request. Since
			// wpforms_save_form_args (which triggers callback activation) is
			// hooked from the constructor, gating this would silently skip it.
			$this->payments = new Payments( IFTP_PBL_GATEWAY_LABEL, IFTP_PBL_SLUG );
		}

		add_action( 'wp_ajax_iftp_pbl_activate_payment_method', fn() => $this->get_payments()->ajax_activate_payment_method() );
		add_action( 'wp_ajax_iftp_pbl_load_gateway_methods',   fn() => $this->get_payments()->ajax_load_gateway_methods() );

		$this->process = new Process( IFTP_PBL_SLUG );
		add_action( 'wp_ajax_iftp_pbl_create_pay_button_payment',        [ $this->process, 'ajax_create_pay_button_payment' ] );
		add_action( 'wp_ajax_nopriv_iftp_pbl_create_pay_button_payment', [ $this->process, 'ajax_create_pay_button_payment' ] );
		add_action( 'wp_ajax_iftp_pbl_verify_payment',                   [ $this->process, 'ajax_verify_payment' ] );
		add_action( 'wp_ajax_nopriv_iftp_pbl_verify_payment',            [ $this->process, 'ajax_verify_payment' ] );
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
	 * Registers the ifthenpay form templates on the Add New Form screen.
	 * @return void
	 */
	public function register_templates(): void {
		if ( ! is_admin() || ! class_exists( '\WPForms_Template' ) ) {
			return;
		}

		new ExampleFormTemplate();
		new ComplexFormTemplate();

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_template_card_styles' ] );
	}

	/**
	 * Brands the ifthenpay template cards (thumbnail background, hover border, "Create
	 * Form" button) on WPForms' template pickers — the standalone Templates page and the
	 * builder's Add New Form screen both render the same markup via
	 * \WPForms\Admin\Traits\FormTemplates, which only exposes generic classes/IDs, so
	 * there's no template-data hook to color these from PHP; CSS scoped to our card IDs
	 * is the only integration point.
	 * @return void
	 */
	public function enqueue_template_card_styles(): void {
		if ( ! function_exists( 'wpforms_is_admin_page' ) || ( ! wpforms_is_admin_page( 'templates' ) && ! wpforms_is_admin_page( 'builder' ) ) ) {
			return;
		}

		wp_register_style( 'ifthenpay-wpforms-templates', false, [], defined( 'IFTP_PBL_VERSION' ) ? IFTP_PBL_VERSION : '2.0.0' );
		wp_enqueue_style( 'ifthenpay-wpforms-templates' );
		wp_add_inline_style( 'ifthenpay-wpforms-templates', $this->template_card_css() );
	}

	/**
	 * @return string
	 */
	private function template_card_css(): string {
		$ids = [ '#wpforms-template-ifthenpay-example-form-template', '#wpforms-template-ifthenpay-complex-form-template' ];

		// Ancestor ID repeated on every selector below (both card pages render inside
		// #wpforms-setup-templates-list) to out-specificity the standalone Templates
		// page's own admin-form-templates.css — e.g. its
		// "#wpforms-setup-templates-list .wpforms-template .wpforms-template-thumbnail"
		// (1 ID + 2 classes) otherwise beats our "#card-id .wpforms-template-thumbnail"
		// (1 ID + 1 class). The builder page has no such competing rule, which is why
		// this only showed up on /wpforms-templates and not on wpforms-builder.
		$thumbnail  = implode( ', ', array_map( static fn( $id ) => "#wpforms-setup-templates-list {$id} .wpforms-template-thumbnail", $ids ) );
		$image      = implode( ', ', array_map( static fn( $id ) => "#wpforms-setup-templates-list {$id} .wpforms-template-thumbnail img", $ids ) );
		$select     = implode( ', ', array_map( static fn( $id ) => "#wpforms-setup-templates-list {$id} .wpforms-template-select", $ids ) );
		$select_hov = implode( ', ', array_map( static fn( $id ) => "#wpforms-setup-templates-list {$id} .wpforms-template-select:hover", $ids ) );
		$hover      = implode( ', ', array_map(
			static fn( $id ) => "#wpforms-setup-templates-list {$id}:hover, #wpforms-setup-templates-list {$id}.active",
			$ids
		) );

		return "
			{$thumbnail} {
				background-color: #e4f3fc;
			}
			{$image} {
				background-color: #ffffff;
				aspect-ratio: 1;
				width: 100%;
				max-width: 350px;
				min-height: 100%;
				object-fit: none;
				object-position: center;
				margin: 0 auto;
				display: block;
				border-radius: 2px 2px 0 0;
				box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
			}
			{$hover} {
				box-shadow: 0 0 0 2px #00609c, 0 3px 4px rgba(0, 0, 0, 0.15);
			}
			{$select} {
				background-color: #00609c;
				border-color: #00609c;
			}
			{$select_hov} {
				background-color: #004d80;
				border-color: #004d80;
			}
		";
	}

	/**
	 * Registers the "ifthenpay dark"/"ifthenpay light" presets in WPForms' Themes tab.
	 * @return void
	 */
	public function register_theme(): void {
		if ( ! is_admin() || ! function_exists( 'wpforms_upload_dir' ) ) {
			return;
		}

		( new IfthenpayDarkTheme() )->boot();
		( new IfthenpayLightTheme() )->boot();
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
			$this->asset_version( 'assets/js/frontend.js' ),
			true
		);

		wp_enqueue_style(
			'ifthenpay-wpforms-frontend',
			IFTP_PBL_URL . 'assets/css/frontend.css',
			[],
			$this->asset_version( 'assets/css/frontend.css' )
		);

		wp_localize_script(
			'ifthenpay-wpforms-frontend',
			'iftpPblFrontend',
			[
				'ajax_url'                        => admin_url( 'admin-ajax.php' ),
				'ajax_nonce'                      => wp_create_nonce( 'iftp_pbl_frontend' ),
				'opening_text'                    => __( 'Opening payment...', 'ifthenpay-payments-for-wpforms' ),
				'processing_text'                 => __( 'Processing payment...', 'ifthenpay-payments-for-wpforms' ),
				'waiting_external_text'           => __( "Complete your payment in the tab that just opened — we'll update this page automatically.", 'ifthenpay-payments-for-wpforms' ),
				'warning_popup_blocked'           => __( 'Please allow pop-ups for this site to complete the payment, then try again.', 'ifthenpay-payments-for-wpforms' ),
				'warning_missing_amount'          => __( 'The payment total is not ready yet. Please review the form and try again.', 'ifthenpay-payments-for-wpforms' ),
				'warning_config_title'            => __( 'Configuration Required', 'ifthenpay-payments-for-wpforms' ),
				'warning_payment_error_title'     => __( 'Unable to open payment', 'ifthenpay-payments-for-wpforms' ),
				'cancelled_title'                 => __( 'Payment cancelled', 'ifthenpay-payments-for-wpforms' ),
				'cancelled_message'               => __( 'You cancelled the payment.', 'ifthenpay-payments-for-wpforms' ),
				'paid_title'                      => __( 'Payment received', 'ifthenpay-payments-for-wpforms' ),
				'paid_message'                    => __( 'Your payment was successful. Thank you!', 'ifthenpay-payments-for-wpforms' ),
				'pending_title'                   => __( 'Payment processing', 'ifthenpay-payments-for-wpforms' ),
				'pending_message'                 => __( "We're waiting for your payment to be confirmed. You don't need to do anything else — this will update automatically once it's complete.", 'ifthenpay-payments-for-wpforms' ),
				'failed_title'                    => __( 'Payment failed', 'ifthenpay-payments-for-wpforms' ),
				'failed_message'                  => __( 'Your payment could not be completed.', 'ifthenpay-payments-for-wpforms' ),
			]
		);
	}

	private function get_payments(): Payments {
		return $this->payments ??= new Payments( IFTP_PBL_GATEWAY_LABEL, IFTP_PBL_SLUG );
	}

	/**
	 * Busts the browser cache on every edit to this specific asset, rather than
	 * IFTP_PBL_VERSION — a fixed release-version string, unrelated to how often these
	 * particular files change. Falls back to IFTP_PBL_VERSION only if the file is somehow
	 * missing.
	 */
	private function asset_version( string $relative_path ): string {
		$path  = IFTP_PBL_DIR . $relative_path;
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;

		return $mtime !== false ? (string) $mtime : IFTP_PBL_VERSION;
	}

	private function __construct() {}
}
