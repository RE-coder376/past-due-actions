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
 * What Pro adds here, and what it deliberately does not
 * ----------------------------------------------------
 * The daily email stays free, exactly as it shipped. Taking a working feature
 * away from people who already installed it is how a plugin earns "useless
 * without pro" reviews, and free installs are the entire distribution channel.
 *
 * Pro adds four things that did not exist before: checking every hour instead
 * of once a day, sending the same alert to Slack or a webhook, keeping a record
 * of the backlog over time so the screen can say whether it is rising, and
 * retrying failed actions unattended.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled backlog watch.
 */
class PDA_Alerts {

	const HOOK      = 'pda_daily_check';
	const OPT_ON    = 'pda_alerts_enabled';
	const OPT_LIMIT = 'pda_alert_threshold';
	const OPT_SENT  = 'pda_alert_last_sent';
	const OPT_EMAIL = 'pda_alert_email';
	const OPT_FREQ  = 'pda_alert_frequency';

	/**
	 * Hook in and make sure the check is scheduled at the right interval.
	 *
	 * The hook name stays pda_daily_check even when it runs hourly. Renaming it
	 * would orphan the event already scheduled on every existing install and
	 * silently stop their alerts, which is a far worse outcome than a constant
	 * whose name aged badly.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'check' ) );

		$wanted = self::frequency();
		$next   = wp_next_scheduled( self::HOOK );

		if ( ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $wanted, self::HOOK );
			return;
		}

		// Licence state and the setting can both change after the event was
		// created. Re-scheduling only when the recurrence actually differs
		// keeps this from resetting the timer on every page load.
		$event = wp_get_scheduled_event( self::HOOK );
		if ( $event && isset( $event->schedule ) && $event->schedule !== $wanted ) {
			wp_clear_scheduled_hook( self::HOOK );
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $wanted, self::HOOK );
		}
	}

	/**
	 * How often the check should run.
	 *
	 * Hourly is a Pro feature, so a lapsed licence quietly returns to daily
	 * rather than breaking. Both values are WordPress core schedules; no custom
	 * interval is registered, which means an existing site's cron list stays
	 * readable to every other tool that inspects it.
	 *
	 * @return string 'hourly' or 'daily'.
	 */
	public static function frequency() {
		if ( ! PDA_License::is_pro() ) {
			return 'daily';
		}
		return 'hourly' === get_option( self::OPT_FREQ, 'daily' ) ? 'hourly' : 'daily';
	}

	/**
	 * Shortest gap between two alerts, in seconds.
	 *
	 * Tied to the check interval rather than fixed at a day: an hourly watch
	 * that still refuses to speak more than once a day is not an hourly watch.
	 *
	 * @return int
	 */
	public static function quiet_period() {
		return 'hourly' === self::frequency() ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
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
	 * The scheduled check.
	 */
	public static function check() {
		if ( ! self::enabled() ) {
			return;
		}

		$summary = PDA_Scanner::summary();
		if ( empty( $summary['available'] ) ) {
			return;
		}

		// Recorded before the threshold test on purpose. The interesting part
		// of a trend is the shape either side of the spike, and a history that
		// only contains bad days cannot show a queue recovering.
		if ( PDA_License::is_pro() ) {
			PDA_History::record( $summary );
		}

		if ( $summary['past_due'] < self::threshold() ) {
			return;
		}

		$last = (int) get_option( self::OPT_SENT, 0 );
		if ( $last && ( time() - $last ) < self::quiet_period() ) {
			return;
		}

		// Retry before reporting, so the email states what is still wrong rather
		// than what was wrong a second ago.
		if ( PDA_Repair::auto_enabled() ) {
			$repaired = PDA_Repair::auto();
			if ( $repaired['requeued'] > 0 ) {
				PDA_Scanner::flush();
				$summary = PDA_Scanner::summary();
			}
		}

		$hooks = PDA_Scanner::past_due_by_hook( 5 );

		wp_mail( self::recipient(), self::subject( $summary ), self::body( $summary, $hooks ) );

		if ( PDA_License::is_pro() && '' !== PDA_Webhooks::url() ) {
			PDA_Webhooks::notify( $summary, $hooks );
		}

		update_option( self::OPT_SENT, time(), false );
	}

	/**
	 * Subject line for the alert email.
	 *
	 * @param array $summary Summary.
	 * @return string
	 */
	public static function subject( array $summary ) {
		return sprintf(
			/* translators: 1: site name, 2: count */
			__( '[%1$s] %2$d scheduled actions are stuck', 'past-due-actions' ),
			get_bloginfo( 'name' ),
			(int) $summary['past_due']
		);
	}

	/**
	 * Body of the alert email.
	 *
	 * @param array $summary Summary.
	 * @param array $hooks   Worst hooks.
	 * @return string
	 */
	public static function body( array $summary, array $hooks ) {
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

		// The trend is the one thing an email can say that the screen cannot:
		// the reader is looking at this at 3am and has no yesterday to compare
		// against.
		if ( PDA_License::is_pro() ) {
			$trend = PDA_History::trend();
			if ( $trend['known'] && 'steady' !== $trend['direction'] ) {
				$body .= "\n" . sprintf(
					/* translators: 1: direction word, 2: earlier count, 3: current count */
					__( 'Trend: the backlog is %1$s (%2$d then, %3$d now).', 'past-due-actions' ),
					'rising' === $trend['direction'] ? __( 'rising', 'past-due-actions' ) : __( 'falling', 'past-due-actions' ),
					$trend['from'],
					$trend['to']
				) . "\n";
			}
		}

		return $body;
	}
}
