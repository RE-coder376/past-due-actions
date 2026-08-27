<?php
/**
 * Tell the owner before the store breaks, not after.
 *
 * The whole reason past-due actions hurt is that they fail silently: renewals
 * stop, confirmation emails stop, and nobody finds out until a customer
 * complains. WooCommerce's own notice only appears if you happen to be looking
 * at the right admin screen.
 *
 * One email, at most once a day, only when the backlog is genuinely growing.
 * An alert that cries wolf gets filtered, and then it is worth less than
 * nothing.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily backlog watch.
 */
class PDA_Alerts {

	const HOOK      = 'pda_daily_check';
	const OPT_ON    = 'pda_alerts_enabled';
	const OPT_LIMIT = 'pda_alert_threshold';
	const OPT_SENT  = 'pda_alert_last_sent';
	const OPT_EMAIL = 'pda_alert_email';

	/**
	 * Hook in and make sure the daily check exists.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'check' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Where the warning goes.
	 *
	 * @return string
	 */
	public static function recipient() {
		$set = get_option( self::OPT_EMAIL, '' );
		return is_email( $set ) ? $set : get_option( 'admin_email' );
	}

	/**
	 * Backlog size that counts as a problem.
	 *
	 * @return int
	 */
	public static function threshold() {
		return max( (int) get_option( self::OPT_LIMIT, 100 ), 1 );
	}

	/**
	 * Is the watch switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) get_option( self::OPT_ON, 1 );
	}

	/**
	 * The daily check.
	 */
	public static function check() {
		if ( ! self::enabled() ) {
			return;
		}

		$summary = PDA_Scanner::summary();
		if ( empty( $summary['available'] ) || $summary['past_due'] < self::threshold() ) {
			return;
		}

		// Once a day at most, however often cron fires.
		$last = (int) get_option( self::OPT_SENT, 0 );
		if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) {
			return;
		}

		$hooks = PDA_Scanner::past_due_by_hook( 5 );
		$lines = array();
		foreach ( $hooks as $row ) {
			$lines[] = sprintf(
				/* translators: 1: count, 2: hook name, 3: owning plugin, 4: how overdue */
				__( '%1$d x %2$s (%3$s) - oldest is %4$s late', 'past-due-actions' ),
				$row['total'],
				$row['hook'],
				$row['owner'],
				$row['overdue']
			);
		}

		$body = sprintf(
			/* translators: 1: site name, 2: count, 3: list of the worst hooks, 4: admin page URL */
			__( "%1\$s has %2\$d past-due scheduled actions.\n\nThis usually means background work has stopped: order emails, subscription renewals and similar jobs are queued but not running.\n\nThe biggest backlogs:\n\n%3\$s\n\nOpen %4\$s to see the likely cause and fix it.\n", 'past-due-actions' ),
			get_bloginfo( 'name' ),
			(int) $summary['past_due'],
			$lines ? '  ' . implode( "\n  ", $lines ) : __( '  (no detail available)', 'past-due-actions' ),
			admin_url( 'tools.php?page=past-due-actions' )
		);

		wp_mail(
			self::recipient(),
			sprintf(
				/* translators: 1: site name, 2: count */
				__( '[%1$s] %2$d scheduled actions are stuck', 'past-due-actions' ),
				get_bloginfo( 'name' ),
				(int) $summary['past_due']
			),
			$body
		);

		update_option( self::OPT_SENT, time(), false );
	}
}
