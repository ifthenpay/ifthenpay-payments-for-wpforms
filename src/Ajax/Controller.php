<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Ajax;

use Ifthenpay\WPForms\Builder\Process;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * AJAX route controller for frontend payment actions.
 */
final class Controller
{
    public function __construct(
        private Process $process
    ) {
    }

    public function register_hooks(): void
    {
        add_action('wp_ajax_iftp_pbl_create_pay_button_payment', [$this->process, 'ajax_create_pay_button_payment']);
        add_action('wp_ajax_nopriv_iftp_pbl_create_pay_button_payment', [$this->process, 'ajax_create_pay_button_payment']);
        add_action('wp_ajax_iftp_pbl_verify_payment', [$this->process, 'ajax_verify_payment']);
        add_action('wp_ajax_nopriv_iftp_pbl_verify_payment', [$this->process, 'ajax_verify_payment']);
        add_action('wp_ajax_iftp_pbl_cancel_payment', [$this->process, 'ajax_cancel_payment']);
        add_action('wp_ajax_nopriv_iftp_pbl_cancel_payment', [$this->process, 'ajax_cancel_payment']);
    }
}
