<?php

declare(strict_types=1);

/**
 * Plugin Name: 		ifthenpay | Payments for WPForms
 * Plugin URI:        	https://ifthenpay.com
 * Description: 		ifthenpay Pay by Link integration for WPForms.
 * Version: 			1.0.0
 * Tested up to:        7.0
 * Requires at least: 	6.5
 * Requires PHP:        8.2
 * Author:              ifthenpay
 * Author URI:          https://ifthenpay.com/
 * License:             GPL v3
 * License URI:         https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:         ifthenpay-payments-for-wpforms
 * Domain Path:         /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_PBL_VERSION', '1.0.0' );
define( 'IFTP_PBL_FILE', __FILE__ );
define( 'IFTP_PBL_DIR', plugin_dir_path( __FILE__ ) );
define( 'IFTP_PBL_URL', plugin_dir_url( __FILE__ ) );
define( 'IFTP_PBL_SLUG', 'iftp_pbl' );
define( 'IFTP_PBL_FIELD_TYPE', 'iftp_pbl_field' );
define( 'IFTP_PBL_GATEWAY_LABEL', 'ifthenpay | Payment Gateway' );

$ifthenpay_wpforms_dir      = plugin_dir_path( __FILE__ );
$ifthenpay_wpforms_autoload = $ifthenpay_wpforms_dir . 'vendor/autoload.php';
if ( file_exists( $ifthenpay_wpforms_autoload ) ) {
	require_once $ifthenpay_wpforms_autoload;
} else {
	spl_autoload_register(
		static function ( string $class ) use ( $ifthenpay_wpforms_dir ): void {
			$prefix = 'Ifthenpay\\WPForms\\';
			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = $ifthenpay_wpforms_dir . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

require_once $ifthenpay_wpforms_dir . 'src/Plugin.php';

\Ifthenpay\WPForms\Plugin::instance()->init();
