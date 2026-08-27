<?php
/**
 * Reading the queue: what is past due, and who put it there.
 *
 * "Past due" is not a status Action Scheduler stores. It is a pending action
 * whose scheduled time has passed - which is why the warning can appear while
 * every row still says "pending", and why store owners cannot find the problem
 * by filtering the list.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queries the Action Scheduler tables.
 */
class PDA_Scanner {

	/**
	 * Cache within a single request. The admin screen asks several questions
	 * that would otherwise repeat the same scan.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Forget the cached summary.
	 *
	 * Anything that changes the queue must call this, or the screen will report
	 * the numbers from before the repair and look like it did nothing.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * The actions table, whatever prefix the site uses.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'actionscheduler_actions';
	}

	/**
	 * Is Action Scheduler's storage actually there?
	 *
	 * Some setups run the library on a custom store, in which case these tables
	 * are absent and every count below would be a database error rather than a
	 * zero.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
	}

	/**
	 * Above this many rows, grouping the whole table is too slow to do on a
	 * page load. One of the support threads that prompted this plugin was
	 * titled "10+ million action scheduler tasks", so this is not theoretical.
	 */
	const HUGE_TABLE = 250000;

	/**
	 * Is the table big enough that full scans should be avoided?
	 *
	 * @return bool
	 */
	public static function is_huge() {
		global $wpdb;

		if ( ! self::tables_exist() ) {
			return false;
		}

		// Counting up to the ceiling and stopping is cheap even on a huge
		// table; COUNT(*) over ten million rows is not.
		$table = self::table();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix.
				"SELECT COUNT(*) FROM ( SELECT action_id FROM {$table} LIMIT %d ) AS capped",
				self::HUGE_TABLE
			)
		);

		return (int) $found >= self::HUGE_TABLE;
	}

	/**
	 * Past-due actions grouped by hook, worst first.
	 *
	 * Grouping is the point. A raw list of 848 rows tells a store owner
	 * nothing; "812 of them are woocommerce_scheduled_subscription_payment"
	 * tells them exactly which plugin to look at.
	 *
	 * @param int $limit Maximum hooks to return.
	 * @return array<int,array>
	 */
	public static function past_due_by_hook( $limit = 20 ) {
		global $wpdb;

		if ( ! self::tables_exist() ) {
			return array();
		}

		$table = self::table();

		// Include hooks that are only failing, not just ones with pending work.
		// A hook whose actions all failed has nothing pending, so an
		// inner-joined query drops it entirely - and the diagnostics tell the
		// reader to "check which hook is failing in the table below". It has to
		// actually be there.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
				"SELECT hook,
				        SUM(CASE WHEN status = %s AND scheduled_date_gmt < %s THEN 1 ELSE 0 END) AS total,
				        MIN(CASE WHEN status = %s AND scheduled_date_gmt < %s THEN scheduled_date_gmt END) AS oldest
				 FROM {$table}
				 WHERE ( status = %s AND scheduled_date_gmt < %s ) OR status = %s
				 GROUP BY hook
				 ORDER BY total DESC
				 LIMIT %d",
				'pending',
				gmdate( 'Y-m-d H:i:s' ),
				'pending',
				gmdate( 'Y-m-d H:i:s' ),
				'pending',
				gmdate( 'Y-m-d H:i:s' ),
				'failed',
				(int) $limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['total']    = (int) $row['total'];
			$row['overdue']  = $row['oldest'] ? self::human_overdue( $row['oldest'] ) : '';
			$row['owner']    = self::guess_owner( $row['hook'] );
			$row['failures'] = self::failures_for( $row['hook'] );
		}
		unset( $row );

		// Failing hooks with nothing pending sort to the bottom rather than
		// disappearing, since they still need a decision.
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( $b['total'], $b['failures'] ) <=> array( $a['total'], $a['failures'] );
			}
		);

		return $rows;
	}

	/**
	 * Totals for the dashboard.
	 *
	 * @return array
	 */
	public static function summary() {
		global $wpdb;

		if ( null !== self::$cache ) {
			return self::$cache;
		}

		if ( ! self::tables_exist() ) {
			self::$cache = array(
				'available' => false,
				'past_due'  => 0,
				'pending'   => 0,
				'failed'    => 0,
				'oldest'    => null,
			);
			return self::$cache;
		}

		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix.
		$past_due = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s AND scheduled_date_gmt < %s",
				'pending',
				$now
			)
		);
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending' )
		);
		$failed = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'failed' )
		);
		$oldest = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(scheduled_date_gmt) FROM {$table} WHERE status = %s AND scheduled_date_gmt < %s",
				'pending',
				$now
			)
		);
		// phpcs:enable

		self::$cache = array(
			'available' => true,
			'past_due'  => $past_due,
			'pending'   => $pending,
			'failed'    => $failed,
			'oldest'    => $oldest,
			'oldest_by' => $oldest ? self::human_overdue( $oldest ) : null,
		);

		return self::$cache;
	}

	/**
	 * Which plugin is likely responsible for a hook.
	 *
	 * Matching on the hook prefix, because that is the only clue Action
	 * Scheduler records. Deliberately phrased as a guess in the UI - a wrong
	 * confident answer would send someone to deactivate the wrong plugin.
	 *
	 * @param string $hook Hook name.
	 * @return string
	 */
	public static function guess_owner( $hook ) {
		$known = array(
			'woocommerce_scheduled_subscription' => 'WooCommerce Subscriptions',
			'woocommerce_run_product_attribute'  => 'WooCommerce',
			'wc-admin'                           => 'WooCommerce Admin',
			'wc_admin'                           => 'WooCommerce Admin',
			'wc_'                                => 'WooCommerce',
			'woocommerce'                        => 'WooCommerce',
			'action_scheduler'                   => 'Action Scheduler itself',
			'wpforms'                            => 'WPForms',
			'jetpack'                            => 'Jetpack',
			'mailpoet'                           => 'MailPoet',
			'wcf_'                               => 'Cart Abandonment Recovery',
			'wp_mail_smtp'                       => 'WP Mail SMTP',
			'yith'                               => 'YITH',
			'gravityforms'                       => 'Gravity Forms',
			'edd_'                               => 'Easy Digital Downloads',
		);

		foreach ( $known as $prefix => $name ) {
			if ( 0 === strpos( $hook, $prefix ) ) {
				return $name;
			}
		}

		// Fall back to the first segment, which is nearly always the plugin's
		// own prefix. Phrased as a guess, because a confident wrong answer
		// could send someone off deactivating the wrong plugin.
		$parts  = preg_split( '/[_\-]/', $hook );
		$prefix = $parts && $parts[0] ? $parts[0] : '';

		if ( '' === $prefix ) {
			return __( 'Unknown', 'past-due-actions' );
		}

		return sprintf(
			/* translators: %s: hook prefix, e.g. "mailchimp" */
			__( 'maybe %s', 'past-due-actions' ),
			ucfirst( $prefix )
		);
	}

	/**
	 * How many times this hook has failed outright.
	 *
	 * A hook that is both backed up and failing is a different problem from one
	 * that is merely waiting: the queue is running, this job is broken.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	public static function failures_for( $hook ) {
		global $wpdb;
		$table = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix.
				"SELECT COUNT(*) FROM {$table} WHERE hook = %s AND status = %s",
				$hook,
				'failed'
			)
		);
	}

	/**
	 * "3 days" rather than a timestamp nobody wants to subtract in their head.
	 *
	 * @param string $gmt_date Scheduled date, GMT.
	 * @return string
	 */
	public static function human_overdue( $gmt_date ) {
		$then = strtotime( $gmt_date . ' UTC' );
		if ( ! $then ) {
			return '';
		}
		return human_time_diff( $then, time() );
	}
}
