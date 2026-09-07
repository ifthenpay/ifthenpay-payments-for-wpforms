<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Cron;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Daily cleanup of stale pending ifthenpay Pay By Link payments.
 *
 * ifthenpay's own hosted Pay By Link page stops accepting the payment once the
 * "expire days" sent when the link was created has passed (see
 * Api\WPForms\Process::handle_process()) — a WPForms payment row left "pending" past that
 * point can never be completed, and would otherwise stay pending forever. This sweeps
 * those rows once a day, mirroring the daily expiry cron used by
 * ifthenpay-payments-for-contactform7 (Activation + EntryRepository::mark_expired_pending())
 * and ifthenpay-payments-for-surecart (Setup\ExpiredPaymentsCron).
 *
 * WPForms core has no "expired" payment status — see
 * WPForms\Db\Payments\ValueValidator::get_allowed_one_time_statuses() — so a swept
 * payment is marked "failed" instead. That's already a non-terminal status a later
 * ifthenpay webhook can still resolve to "completed" (see
 * Api\WPForms\Process\PaymentRecordStore::mark_wpforms_payments_status()), the same way a Multibanco/Payshop
 * reference reported cancelled/failed can still genuinely get paid afterwards.
 */
final class ExpiredPaymentsCron {

	private const HOOK = 'iftp_pbl_expire_payments';

	/** Mirrors the expire_days sent to ifthenpay's Pay By Link API (see Api\WPForms\Process::handle_process()). */
	private const EXPIRE_DAYS = 1;

	/** Upper bound on rows swept per run, so a large backlog can't turn one cron tick into a long-running request. */
	private const MAX_PER_RUN = 500;

	/**
	 * Registers the cron hook and (re)schedules the recurring event if needed. Checking
	 * wp_next_scheduled() on every load rather than only on plugin activation means the
	 * event is also picked up after an upgrade from a version that predates this class,
	 * with no activation hook required.
	 */
	public function boot(): void {
		add_action( self::HOOK, [ $this, 'run' ] );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	public function run(): void {
		if ( ! function_exists( 'wpforms' ) ) {
			return;
		}

		$payment = wpforms()->obj( 'payment' );
		if ( ! $payment || ! method_exists( $payment, 'update' ) || ! method_exists( $payment, 'get' ) ) {
			return;
		}

		foreach ( $this->find_expired_pending_ids() as $payment_id ) {
			// The candidate list was read a moment ago — re-confirm the row is still
			// "pending" immediately before writing, since a concurrent ifthenpay webhook
			// (Api\WPForms\Process\WebhookHandler::handle_webhook_success()) may have resolved it to
			// "completed" (or "cancelled"/"failed") in between. Without this a still-open
			// batch could clobber a payment that was genuinely confirmed paid moments ago.
			$current = $payment->get( $payment_id, [ 'cap' => false ] );
			if ( ! $current || ! isset( $current->status ) || (string) $current->status !== 'pending' ) {
				continue;
			}

			$updated = (bool) $payment->update(
				$payment_id,
				[
					'status'           => 'failed',
					'date_updated_gmt' => current_time( 'mysql', true ),
				],
				'',
				'',
				[ 'cap' => false ]
			);

			if ( $updated ) {
				do_action( 'iftp_pbl_payment_status_changed', $payment_id, 'failed' );
			}
		}
	}

	/**
	 * Read-only lookup of candidate payment ids. WPForms core exposes no query API for
	 * "pending payments older than X", so this reads its payments table directly. This
	 * list can go stale between the read and the write — see the re-check in run() —
	 * so nothing here is treated as more than a candidate set.
	 *
	 * @return array<int, int>
	 */
	private function find_expired_pending_ids(): array {
		global $wpdb;

		$table     = wpforms()->obj( 'payment' )->get_table_name();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - self::EXPIRE_DAYS * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only; see method docblock.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE gateway = %s AND status = 'pending' AND date_created_gmt <= %s ORDER BY id ASC LIMIT %d",
				$table,
				IFTP_PBL_SLUG,
				$threshold,
				self::MAX_PER_RUN
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Clears the scheduled event on deactivation.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}
}
