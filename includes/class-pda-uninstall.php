<?php
/**
 * Leave nothing behind.
 *
 * Everything this plugin created is nine options and one cron event. A
 * diagnostic tool that litters the options table after removal is exactly the
 * kind of thing the people who install this are already annoyed about.
 *
 * Scheduled actions themselves are never touched. They belong to WooCommerce
 * and whichever plugins queued them, not to us - deleting somebody's pending
 * subscription renewals because they uninstalled a monitor would be
 * indefensible.
 *
 * Why this is a class and not uninstall.php
 * -----------------------------------------
 * WordPress runs exactly one uninstall.php per plugin. The licensing SDK ships
 * its own to clear its data, so a second one in the plugin root silently wins
 * and the SDK's cleanup never happens - which is the opposite of the point.
 * The SDK's after_uninstall hook runs this instead, and both cleanups happen.
 *
 * Everything here names its options as literal strings rather than class
 * constants. During an uninstall the plugin's normal bootstrap has not run, so
 * nothing may be assumed to be loaded.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removal.
 */
class PDA_Uninstall {

	const CRON = 'pda_daily_check';

	/**
	 * Every option this plugin has ever written.
	 *
	 * @return string[]
	 */
	public static function options() {
		return array(
			'pda_alerts_enabled',
			'pda_alert_threshold',
			'pda_alert_last_sent',
			'pda_alert_email',
			'pda_alert_frequency',
			'pda_webhook_url',
			'pda_history',
			'pda_autorepair',
			'pda_autorepair_last',
		);
	}

	/**
	 * Delete everything, on every site of a network.
	 *
	 * A network install keeps its settings per site, so clearing only the site
	 * that happened to run the uninstall leaves the rest of the network
	 * littered with rows nothing will ever read again.
	 */
	public static function run() {
		if ( is_multisite() ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $sites as $site_id ) {
				switch_to_blog( $site_id );
				self::clear_site();
				restore_current_blog();
			}

			return;
		}

		self::clear_site();
	}

	/**
	 * One site's worth of cleanup.
	 */
	private static function clear_site() {
		foreach ( self::options() as $option ) {
			delete_option( $option );
		}

		wp_clear_scheduled_hook( self::CRON );
	}
}
