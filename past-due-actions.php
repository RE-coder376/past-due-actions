<?php
/**
 * Plugin Name:       Past-Due Actions — Action Scheduler Monitor
 * Plugin URI:        https://wordpress.org/plugins/past-due-actions/
 * Description:       Find out why Action Scheduler has past-due actions, which plugin is responsible, and fix it. Diagnoses the cause instead of just deleting rows.
 * Version:           1.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Hamza Naimat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       past-due-actions
 *
 * Why this exists
 * ---------------
 * WooCommerce shows "Action Scheduler: 848 past-due actions found; something may
 * be wrong." and then offers no way to find out what. Store owners paste that
 * sentence into search engines - the support forums are full of exactly that
 * text, with numbers from 6 to over ten million - and the existing tools do not
 * answer it:
 *
 *   - Action Scheduler itself is the library generating the warning, not a fix.
 *     Its reviews are people who installed it expecting help: "broken and almost
 *     malicious in nature", "Like a Leech", "Refuses to Support".
 *   - The cleaner plugins delete *completed* actions to shrink the database.
 *     Useful housekeeping, but they do nothing for actions that are stuck.
 *
 * So this plugin answers the question the warning raises: which hooks are
 * backed up, which plugin owns them, why the queue stopped running, and what to
 * do about it.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

define( 'PDA_VERSION', '1.1.0' );
define( 'PDA_FILE', __FILE__ );
define( 'PDA_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Boot only when Action Scheduler is actually present.
 *
 * It ships inside WooCommerce, WPForms, Jetpack and others rather than being
 * installed directly, so checking for the library beats checking for any one
 * plugin. Without it there is nothing to monitor and every query below would
 * hit a table that does not exist.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'ActionScheduler' ) && ! function_exists( 'as_get_scheduled_actions' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					echo '<div class="notice notice-warning"><p>';
					esc_html_e(
						'Past-Due Actions needs Action Scheduler, which ships with WooCommerce, WPForms, Jetpack and similar plugins. Nothing to monitor until one of those is active.',
						'past-due-actions'
					);
					echo '</p></div>';
				}
			);
			return;
		}

		require_once PDA_PATH . 'includes/class-pda-license.php';
		require_once PDA_PATH . 'includes/class-pda-history.php';
		require_once PDA_PATH . 'includes/class-pda-webhooks.php';
		require_once PDA_PATH . 'includes/class-pda-scanner.php';
		require_once PDA_PATH . 'includes/class-pda-diagnostics.php';
		require_once PDA_PATH . 'includes/class-pda-repair.php';
		require_once PDA_PATH . 'includes/class-pda-alerts.php';

		PDA_Alerts::init();

		if ( is_admin() ) {
			require_once PDA_PATH . 'includes/class-pda-admin.php';
			PDA_Admin::init();
		}
	}
);

register_deactivation_hook(
	PDA_FILE,
	static function () {
		wp_clear_scheduled_hook( 'pda_daily_check' );
	}
);
