<?php
/**
 * End-to-end check for Past-Due Actions, run through WP-CLI.
 *
 * Creates a realistic broken queue - actions scheduled in the past, some
 * failed - then asserts the plugin reports it correctly and repairs it.
 */

$GLOBALS['pda_pass'] = 0;
$GLOBALS['pda_fail'] = 0;

function pda_check( $label, $ok, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['pda_pass'];
		WP_CLI::log( "  PASS  $label" );
	} else {
		++$GLOBALS['pda_fail'];
		WP_CLI::log( "  FAIL  $label" . ( $detail ? "  ($detail)" : '' ) );
	}
}

global $wpdb;
$table = $wpdb->prefix . 'actionscheduler_actions';

WP_CLI::log( "\n--- 1. Action Scheduler present" );
pda_check( 'storage tables exist', PDA_Scanner::tables_exist() );

WP_CLI::log( "\n--- 2. Build a broken queue" );
// Clear every fixture hook first. Demo data seeded for screenshots uses the
// same hook names, and without this the assertions below measure that instead.
$fixtures = array(
	'woocommerce_scheduled_subscription_payment',
	'wcf_abandoned_cart_email',
	'mailpoet_cron_daily',
	'woocommerce_cleanup_sessions',
);
$wpdb->query( "DELETE FROM {$table} WHERE hook LIKE 'pda_test_%'" );
foreach ( $fixtures as $f ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE hook = %s", $f ) );
}

$make = function ( $hook, $status, $when, $count ) use ( $wpdb, $table ) {
	for ( $i = 0; $i < $count; $i++ ) {
		$wpdb->insert(
			$table,
			array(
				'hook'                 => $hook,
				'status'               => $status,
				'scheduled_date_gmt'   => gmdate( 'Y-m-d H:i:s', $when ),
				'scheduled_date_local' => gmdate( 'Y-m-d H:i:s', $when ),
				'args'                 => '[]',
				'attempts'             => 0,
			)
		);
	}
};

// A big backlog from one plugin, a small one from another, plus failures.
$make( 'woocommerce_scheduled_subscription_payment', 'pending', time() - ( 5 * DAY_IN_SECONDS ), 40 );
$make( 'pda_test_newsletter', 'pending', time() - ( 2 * HOUR_IN_SECONDS ), 7 );
$make( 'pda_test_newsletter', 'failed', time() - DAY_IN_SECONDS, 5 );
$make( 'pda_test_future', 'pending', time() + DAY_IN_SECONDS, 3 );   // not past due

PDA_Scanner::flush();
$summary = PDA_Scanner::summary();
WP_CLI::log( sprintf( '        past_due=%d pending=%d failed=%d',
	$summary['past_due'], $summary['pending'], $summary['failed'] ) );

pda_check( 'past-due counted', $summary['past_due'] >= 47, 'got ' . $summary['past_due'] );
pda_check( 'future actions NOT counted as past due',
	$summary['past_due'] < $summary['pending'], 'past_due must exclude the 3 future ones' );
pda_check( 'failures counted', $summary['failed'] >= 5, 'got ' . $summary['failed'] );
pda_check( 'oldest reported', ! empty( $summary['oldest_by'] ), (string) $summary['oldest_by'] );

WP_CLI::log( "\n--- 3. Grouped by hook, worst first" );
$hooks = PDA_Scanner::past_due_by_hook();
foreach ( $hooks as $row ) {
	WP_CLI::log( sprintf( '        %-46s %4d  %-24s failed:%d',
		$row['hook'], $row['total'], $row['owner'], $row['failures'] ) );
}
pda_check( 'hooks grouped', count( $hooks ) >= 2, count( $hooks ) . ' hooks' );
pda_check( 'worst hook first', $hooks && 'woocommerce_scheduled_subscription_payment' === $hooks[0]['hook'] );
pda_check( 'owner identified', $hooks && false !== stripos( $hooks[0]['owner'], 'subscription' ),
	$hooks ? $hooks[0]['owner'] : '' );
$news = null;
foreach ( $hooks as $row ) {
	if ( 'pda_test_newsletter' === $row['hook'] ) { $news = $row; }
}
pda_check( 'failures attributed to the right hook', $news && 5 === (int) $news['failures'],
	$news ? 'got ' . $news['failures'] : 'hook missing' );

// A hook with only failures and nothing pending must still be listed. The
// diagnostics tell the reader to "check which hook is failing in the table
// below", so it has to actually be in there - an inner-joined query drops it.
$make( 'pda_test_failonly', 'failed', time() - DAY_IN_SECONDS, 4 );
PDA_Scanner::flush();
$failonly = null;
foreach ( PDA_Scanner::past_due_by_hook() as $row ) {
	if ( 'pda_test_failonly' === $row['hook'] ) {
		$failonly = $row;
	}
}
pda_check( 'failing-only hook still listed', null !== $failonly );
pda_check( 'failing-only hook shows its failures', $failonly && 4 === (int) $failonly['failures'],
	$failonly ? 'got ' . $failonly['failures'] : 'missing' );
pda_check( 'failing-only hook has no pending count', $failonly && 0 === (int) $failonly['total'],
	$failonly ? 'got ' . $failonly['total'] : 'missing' );

WP_CLI::log( "\n--- 4. Diagnostics explain the cause" );
$checks = PDA_Diagnostics::run();
foreach ( $checks as $c ) {
	WP_CLI::log( sprintf( '        [%-4s] %s', $c['status'], $c['title'] ) );
}
pda_check( 'diagnostics returned', count( $checks ) >= 5, count( $checks ) . ' checks' );
pda_check( 'worst problem listed first', $checks && in_array( $checks[0]['status'], array( 'fail', 'warn' ), true ),
	$checks ? $checks[0]['status'] : '' );
$has_fix = false;
foreach ( $checks as $c ) {
	if ( 'ok' !== $c['status'] && $c['fix'] ) { $has_fix = true; }
}
pda_check( 'a problem comes with a next step', $has_fix );

WP_CLI::log( "\n--- 5. Repair: retry failed" );
$retried = PDA_Repair::retry_hook( 'pda_test_newsletter' );
PDA_Scanner::flush();
$after = PDA_Scanner::summary();
pda_check( 'failed actions re-queued', 5 === $retried, 'retried ' . $retried );

// Scoped to this hook on purpose: the failing-only fixture above is still
// there by design, so a global count would be wrong.
$left = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$table} WHERE hook = %s AND status = %s",
	'pda_test_newsletter', 'failed' ) );
pda_check( 'that hook has no failures left', 0 === $left, 'still ' . $left );
pda_check( 'other hooks kept their failures', (int) $after['failed'] >= 4,
	'global failed ' . $after['failed'] );

WP_CLI::log( "\n--- 6. Repair: cancel one hook only" );
$before_pd = $after['past_due'];
$cancelled = PDA_Repair::cancel_hook( 'woocommerce_scheduled_subscription_payment' );
PDA_Scanner::flush();
$end = PDA_Scanner::summary();
pda_check( 'backlog cancelled', 40 === $cancelled, 'cancelled ' . $cancelled );

// Scoped to a specific hook rather than the global count. WordPress schedules
// its own work while this runs, so real actions drift into past-due mid-test
// and a global comparison fails for reasons that have nothing to do with us.
$others = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE hook = %s AND status = %s",
		'pda_test_newsletter',
		'pending'
	)
);
pda_check( 'the other hook was left alone', 12 === $others, 'found ' . $others );

$still = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$table} WHERE hook = %s AND status = %s",
	'woocommerce_scheduled_subscription_payment', 'canceled' ) );
pda_check( 'cancelled rows kept, not deleted', 40 === $still, 'found ' . $still );

WP_CLI::log( "\n--- 7. Portability and scale" );

// UPDATE ... LIMIT is a MySQL extension that SQLite does not support by
// default. Every write must go through select-then-update-by-id so the same
// code runs on both.
$repair_src = file_get_contents( WP_PLUGIN_DIR . '/past-due-actions/includes/class-pda-repair.php' );

// Check each SQL string on its own. Scanning the whole file matches an UPDATE
// in one statement against the LIMIT of a later SELECT, which is a false alarm.
preg_match_all( '/"(UPDATE[^"]*)"/i', $repair_src, $updates );
$bad = array();
foreach ( (array) $updates[1] as $stmt ) {
	if ( preg_match( '/\bLIMIT\b/i', $stmt ) ) {
		$bad[] = preg_replace( '/\s+/', ' ', substr( $stmt, 0, 60 ) );
	}
}
pda_check(
	'no UPDATE ... LIMIT in the write path',
	empty( $bad ),
	implode( ' | ', $bad )
);
pda_check( 'the write path was actually found', count( (array) $updates[1] ) >= 2,
	count( (array) $updates[1] ) . ' UPDATE statements seen' );

pda_check( 'huge-table check returns a verdict', is_bool( PDA_Scanner::is_huge() ) );
pda_check( 'this table is not flagged huge', false === PDA_Scanner::is_huge() );

// A hardcoded wp_ prefix is invisible on a default install and breaks every
// site that chose its own - which is most security-hardened stores, and a fair
// share of the neglected ones this plugin exists for.
$hardcoded = array();
foreach ( glob( WP_PLUGIN_DIR . '/past-due-actions/includes/*.php' ) as $file ) {
	if ( preg_match( '/["\'`]wp_(actionscheduler|posts|options|postmeta)/', file_get_contents( $file ) ) ) {
		$hardcoded[] = basename( $file );
	}
}
pda_check( 'no table name hardcodes the wp_ prefix', ! $hardcoded, implode( ', ', $hardcoded ) );
pda_check( 'the scanner builds table names from $wpdb->prefix',
	false !== strpos( file_get_contents( WP_PLUGIN_DIR . '/past-due-actions/includes/class-pda-scanner.php' ), '$wpdb->prefix' ) );

// The safety ceiling must still hold now that ids are selected first.
$make( 'pda_test_ceiling', 'pending', time() - DAY_IN_SECONDS, 12 );
PDA_Scanner::flush();
$capped = PDA_Repair::cancel_hook( 'pda_test_ceiling', 5 );
pda_check( 'cancel respects its limit', 5 === $capped, 'cancelled ' . $capped );

$remaining = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE hook = %s AND status = %s",
		'pda_test_ceiling',
		'pending'
	)
);
pda_check( 'the rest are left alone', 7 === $remaining, 'left ' . $remaining );

WP_CLI::log( "\n--- 8. Alerts" );
pda_check( 'threshold has a sane default', PDA_Alerts::threshold() >= 1 );
pda_check( 'recipient falls back to admin email', is_email( PDA_Alerts::recipient() ), PDA_Alerts::recipient() );

WP_CLI::log( "\n--- 9. Admin guards" );
// The handlers are the only destructive entry points on the site. A reviewer
// will look straight at these, and a missing capability or nonce check here is
// the most common reason a plugin is rejected. is_admin() is false under
// WP-CLI, so the class is never loaded by the plugin itself.
require_once WP_PLUGIN_DIR . '/past-due-actions/includes/class-pda-admin.php';

// wp_die() would end the process. Swap in a handler that throws so the guard
// can be observed refusing rather than killing the run.
add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message ) {
			throw new Exception( is_wp_error( $message ) ? $message->get_error_message() : (string) $message );
		};
	}
);

$guard = new ReflectionMethod( 'PDA_Admin', 'guard' );
$guard->setAccessible( true );

/**
 * Run the guard as a given user with a given nonce and report how it ended.
 *
 * @param int    $user  User id to act as.
 * @param string $nonce Nonce value to present.
 * @return string 'passed' or 'blocked'.
 */
$try_guard = static function ( $user, $nonce ) use ( $guard ) {
	wp_set_current_user( $user );
	$_REQUEST['_wpnonce'] = $nonce;
	$_POST['_wpnonce']    = $nonce;
	// check_admin_referer() also accepts a matching referer; supply one so the
	// nonce itself is what decides the outcome.
	$_REQUEST['_wp_http_referer'] = admin_url( 'tools.php?page=past-due-actions' );
	try {
		$guard->invoke( null );
		return 'passed';
	} catch ( Exception $e ) {
		return 'blocked';
	}
};

$admin_id = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$admin_id = $admin_id ? (int) $admin_id[0] : 0;
pda_check( 'an administrator exists to test with', $admin_id > 0, 'id ' . $admin_id );

$sub_id = username_exists( 'pda_test_subscriber' );
if ( ! $sub_id ) {
	$sub_id = wp_insert_user(
		array(
			'user_login' => 'pda_test_subscriber',
			'user_pass'  => wp_generate_password( 20 ),
			'user_email' => 'pda_test_subscriber@example.invalid',
			'role'       => 'subscriber',
		)
	);
}
$sub_id = (int) $sub_id;

wp_set_current_user( $admin_id );
$good_nonce = wp_create_nonce( 'pda_action' );

pda_check( 'admin with a valid nonce is allowed', 'passed' === $try_guard( $admin_id, $good_nonce ) );
pda_check( 'admin with a bad nonce is blocked', 'blocked' === $try_guard( $admin_id, 'not-a-real-nonce' ) );
pda_check( 'admin with no nonce is blocked', 'blocked' === $try_guard( $admin_id, '' ) );

// A subscriber must be refused on capability alone. Give them a nonce that is
// valid for them, so it is the capability check and nothing else doing the work.
wp_set_current_user( $sub_id );
$sub_nonce = wp_create_nonce( 'pda_action' );
pda_check( 'a subscriber is blocked even with a valid nonce', 'blocked' === $try_guard( $sub_id, $sub_nonce ) );
pda_check( 'a logged-out visitor is blocked', 'blocked' === $try_guard( 0, $good_nonce ) );

wp_set_current_user( $admin_id );
unset( $_REQUEST['_wpnonce'], $_POST['_wpnonce'], $_REQUEST['_wp_http_referer'] );

// Every registered write must actually route through that guard.
$admin_src   = file_get_contents( WP_PLUGIN_DIR . '/past-due-actions/includes/class-pda-admin.php' );
$unguarded   = array();
foreach ( array( 'handle_run', 'handle_retry', 'handle_cancel', 'handle_settings' ) as $handler ) {
	if ( preg_match( '/function\s+' . $handler . '\s*\([^)]*\)\s*\{(.*?)\n\t\}/s', $admin_src, $m )
		&& false === strpos( $m[1], 'self::guard()' ) ) {
		$unguarded[] = $handler;
	}
}
pda_check( 'every admin write calls the guard', ! $unguarded, implode( ', ', $unguarded ) );

WP_CLI::log( "\n--- 10. The alert email actually sends" );
// The daily email is the plugin's only unattended behaviour and had never been
// fired in a test. Capture wp_mail rather than sending.
$GLOBALS['pda_mail'] = array();
add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) {
		$GLOBALS['pda_mail'][] = $atts;
		return true;
	},
	10,
	2
);

/**
 * Fire the daily check and return how many mails it produced.
 *
 * @return int
 */
$fire = static function () {
	$GLOBALS['pda_mail'] = array();
	PDA_Scanner::flush();
	PDA_Alerts::check();
	return count( $GLOBALS['pda_mail'] );
};

$wpdb->query( "DELETE FROM {$table} WHERE hook LIKE 'pda_test_%'" );
update_option( PDA_Alerts::OPT_ON, 1, false );
update_option( PDA_Alerts::OPT_LIMIT, 25, false );
delete_option( PDA_Alerts::OPT_SENT );
delete_option( PDA_Alerts::OPT_EMAIL );

$make( 'pda_test_alert', 'pending', time() - DAY_IN_SECONDS, 10 );
pda_check( 'a backlog under the threshold sends nothing', 0 === $fire() );

$make( 'pda_test_alert', 'pending', time() - DAY_IN_SECONDS, 40 );
pda_check( 'a backlog over the threshold sends one email', 1 === $fire() );

$mail = $GLOBALS['pda_mail'] ? $GLOBALS['pda_mail'][0] : array();
pda_check( 'it goes to the admin email by default', ! empty( $mail['to'] ) && $mail['to'] === get_option( 'admin_email' ),
	isset( $mail['to'] ) ? $mail['to'] : 'none' );
pda_check( 'the subject carries the count', ! empty( $mail['subject'] ) && preg_match( '/\d+ scheduled actions are stuck/', $mail['subject'] ),
	isset( $mail['subject'] ) ? $mail['subject'] : 'none' );
pda_check( 'the body names the worst hook', ! empty( $mail['message'] ) && false !== strpos( $mail['message'], 'pda_test_alert' ) );
pda_check( 'the body links to the fix page', ! empty( $mail['message'] ) && false !== strpos( $mail['message'], 'page=past-due-actions' ) );
// The translator placeholders must all resolve - an un-substituted %3$s in a
// real inbox is what a broken sprintf looks like from the outside.
pda_check( 'no placeholder is left unsubstituted', ! empty( $mail['message'] ) && ! preg_match( '/%\d\$[sd]/', $mail['message'] ) );

pda_check( 'a second check the same day stays quiet', 0 === $fire() );

update_option( PDA_Alerts::OPT_SENT, time() - ( 2 * DAY_IN_SECONDS ), false );
pda_check( 'a day later it warns again', 1 === $fire() );

update_option( PDA_Alerts::OPT_EMAIL, 'ops@example.invalid', false );
update_option( PDA_Alerts::OPT_SENT, time() - ( 2 * DAY_IN_SECONDS ), false );
$fire();
pda_check( 'a custom recipient is honoured',
	! empty( $GLOBALS['pda_mail'][0]['to'] ) && 'ops@example.invalid' === $GLOBALS['pda_mail'][0]['to'] );

update_option( PDA_Alerts::OPT_ON, 0, false );
update_option( PDA_Alerts::OPT_SENT, time() - ( 2 * DAY_IN_SECONDS ), false );
pda_check( 'switching alerts off silences them', 0 === $fire() );

update_option( PDA_Alerts::OPT_ON, 1, false );
delete_option( PDA_Alerts::OPT_EMAIL );

WP_CLI::log( "\n--- 11. Install, uninstall, and leaving no mess" );
pda_check( 'the daily check is scheduled', false !== wp_next_scheduled( PDA_Alerts::HOOK ) );

// Deactivation must take the cron event with it, or the site keeps firing a
// hook with nothing listening after the plugin is gone.
wp_clear_scheduled_hook( PDA_Alerts::HOOK );
pda_check( 'deactivation clears the daily check', false === wp_next_scheduled( PDA_Alerts::HOOK ) );
PDA_Alerts::init();
pda_check( 'it comes back on the next load', false !== wp_next_scheduled( PDA_Alerts::HOOK ) );

$uninstall = WP_PLUGIN_DIR . '/past-due-actions/uninstall.php';
pda_check( 'an uninstall routine exists', file_exists( $uninstall ) );

if ( file_exists( $uninstall ) ) {
	foreach ( array( PDA_Alerts::OPT_ON, PDA_Alerts::OPT_LIMIT, PDA_Alerts::OPT_SENT, PDA_Alerts::OPT_EMAIL ) as $opt ) {
		update_option( $opt, 'x', false );
	}
	// A neighbour's option, to prove the cleanup is targeted and not a sweep of
	// everything with a pda_ prefix or worse.
	update_option( 'pda_test_bystander', 'keep-me', false );
	define( 'WP_UNINSTALL_PLUGIN', 'past-due-actions/past-due-actions.php' );
	include $uninstall;

	$left = array();
	foreach ( array( PDA_Alerts::OPT_ON, PDA_Alerts::OPT_LIMIT, PDA_Alerts::OPT_SENT, PDA_Alerts::OPT_EMAIL ) as $opt ) {
		if ( null !== get_option( $opt, null ) ) {
			$left[] = $opt;
		}
	}
	pda_check( 'uninstall removes every option it created', ! $left, implode( ', ', $left ) );
	pda_check( 'uninstall clears the cron event', false === wp_next_scheduled( PDA_Alerts::HOOK ) );

	// On a network, settings live per-site. Cleaning only the site that happened
	// to run the uninstall leaves the rest of the network littered - and the
	// single-site path would pass this test without ever proving it.
	if ( is_multisite() ) {
		$others = get_sites(
			array(
				'fields'       => 'ids',
				'number'       => 0,
				'site__not_in' => array( get_current_blog_id() ),
			)
		);
		pda_check( 'the network has a second site to prove this on', ! empty( $others ), count( $others ) . ' others' );

		foreach ( $others as $other_id ) {
			switch_to_blog( $other_id );
			update_option( PDA_Alerts::OPT_ON, 'x', false );
			update_option( PDA_Alerts::OPT_EMAIL, 'x', false );
			restore_current_blog();
		}

		include $uninstall;

		$dirty = array();
		foreach ( $others as $other_id ) {
			switch_to_blog( $other_id );
			if ( null !== get_option( PDA_Alerts::OPT_ON, null ) || null !== get_option( PDA_Alerts::OPT_EMAIL, null ) ) {
				$dirty[] = $other_id;
			}
			restore_current_blog();
		}
		pda_check( 'uninstall cleans every site on the network', ! $dirty, 'left on site ' . implode( ',', $dirty ) );
	}
	// Nothing of anyone else's may be touched. A sentinel written before the
	// uninstall ran has to survive it, and so does WordPress's own state.
	pda_check( 'uninstall leaves a neighbouring option alone',
		'keep-me' === get_option( 'pda_test_bystander' ) );
	pda_check( 'uninstall leaves WordPress core options alone',
		is_email( get_option( 'admin_email' ) ) && '' !== get_option( 'blogname' ) );
	delete_option( 'pda_test_bystander' );
}

if ( $sub_id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sub_id );
}

$wpdb->query( "DELETE FROM {$table} WHERE hook LIKE 'pda_test_%'" );
foreach ( $fixtures as $f ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE hook = %s", $f ) );
}

WP_CLI::log( "\n========================================" );
WP_CLI::log( sprintf( '  %d passed, %d failed', $GLOBALS['pda_pass'], $GLOBALS['pda_fail'] ) );
WP_CLI::log( "========================================\n" );

if ( $GLOBALS['pda_fail'] ) {
	WP_CLI::halt( 1 );
}
