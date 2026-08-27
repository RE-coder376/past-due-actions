<?php
/**
 * Leave nothing behind.
 *
 * Everything this plugin created is four options and one cron event. A
 * diagnostic tool that litters the options table after removal is exactly the
 * kind of thing the people who install this are already annoyed about.
 *
 * Scheduled actions themselves are never touched. They belong to WooCommerce
 * and whichever plugins queued them, not to us - deleting somebody's pending
 * subscription renewals because they uninstalled a monitor would be indefensible.
 *
 * @package PastDueActions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$pda_options = array(
	'pda_alerts_enabled',
	'pda_alert_threshold',
	'pda_alert_last_sent',
	'pda_alert_email',
);

if ( is_multisite() ) {
	$pda_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $pda_sites as $pda_site_id ) {
		switch_to_blog( $pda_site_id );
		foreach ( $pda_options as $pda_option ) {
			delete_option( $pda_option );
		}
		wp_clear_scheduled_hook( 'pda_daily_check' );
		restore_current_blog();
	}
} else {
	foreach ( $pda_options as $pda_option ) {
		delete_option( $pda_option );
	}
	wp_clear_scheduled_hook( 'pda_daily_check' );
}
