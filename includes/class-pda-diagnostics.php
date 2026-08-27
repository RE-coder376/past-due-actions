<?php
/**
 * Why the queue stopped. This is the part nothing else does.
 *
 * A backlog of past-due actions is a symptom, and it has a small number of
 * causes. Each check below returns what was found, what it means in plain
 * words, and the single next step - because the person reading it is a store
 * owner who has just been told "something may be wrong" and given no clue what.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the cause-of-death checks.
 */
class PDA_Diagnostics {

	const OK   = 'ok';
	const WARN = 'warn';
	const FAIL = 'fail';

	/**
	 * Every check, worst first.
	 *
	 * @return array<int,array{status:string,title:string,detail:string,fix:string}>
	 */
	public static function run() {
		$checks = array(
			self::check_wp_cron(),
			self::check_external_http(),
			self::check_last_run(),
			self::check_failures(),
			self::check_backlog_size(),
			self::check_php_limits(),
		);

		$rank = array( self::FAIL => 0, self::WARN => 1, self::OK => 2 );
		usort(
			$checks,
			static function ( $a, $b ) use ( $rank ) {
				return $rank[ $a['status'] ] <=> $rank[ $b['status'] ];
			}
		);

		return $checks;
	}

	/**
	 * The single most common cause: WP-Cron switched off and nothing put in its
	 * place. Hosts and optimisation guides recommend disabling it, then nobody
	 * adds the server cron job it assumed you would.
	 *
	 * @return array
	 */
	private static function check_wp_cron() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return array(
				'status' => self::FAIL,
				'title'  => __( 'WP-Cron is disabled', 'past-due-actions' ),
				'detail' => __( 'DISABLE_WP_CRON is set to true in wp-config.php, so WordPress will not run scheduled work on its own. Action Scheduler depends on it unless a real server cron job replaces it. This is the most common reason a queue stops moving.', 'past-due-actions' ),
				'fix'    => __( 'Either set DISABLE_WP_CRON to false, or add a server cron job that requests wp-cron.php every five minutes. Your host\'s control panel usually has a "Cron Jobs" section for this.', 'past-due-actions' ),
			);
		}

		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			return array(
				'status' => self::WARN,
				'title'  => __( 'Alternate WP-Cron is in use', 'past-due-actions' ),
				'detail' => __( 'ALTERNATE_WP_CRON is enabled. It only fires when someone visits the site, so a quiet store can sit for hours without processing anything.', 'past-due-actions' ),
				'fix'    => __( 'On a low-traffic store, a real server cron job is far more reliable.', 'past-due-actions' ),
			);
		}

		return array(
			'status' => self::OK,
			'title'  => __( 'WP-Cron is enabled', 'past-due-actions' ),
			'detail' => __( 'WordPress is allowed to run scheduled work.', 'past-due-actions' ),
			'fix'    => '',
		);
	}

	/**
	 * WP-Cron works by the site calling itself. If outbound requests are
	 * blocked, that call never lands and the queue silently stops - with no
	 * error anywhere, which is what makes it so hard to diagnose.
	 *
	 * @return array
	 */
	private static function check_external_http() {
		if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
			$allowed = defined( 'WP_ACCESSIBLE_HOSTS' ) ? WP_ACCESSIBLE_HOSTS : '';
			$host    = wp_parse_url( home_url(), PHP_URL_HOST );

			if ( $host && false === strpos( (string) $allowed, (string) $host ) ) {
				return array(
					'status' => self::FAIL,
					'title'  => __( 'The site cannot call itself', 'past-due-actions' ),
					'detail' => __( 'WP_HTTP_BLOCK_EXTERNAL is on and this site\'s own address is not in WP_ACCESSIBLE_HOSTS. WP-Cron works by making a request to your own site, so that request is being blocked and the queue never runs.', 'past-due-actions' ),
					'fix'    => sprintf(
						/* translators: %s: site host name */
						__( 'Add %s to the WP_ACCESSIBLE_HOSTS constant in wp-config.php.', 'past-due-actions' ),
						'<code>' . esc_html( $host ) . '</code>'
					),
				);
			}
		}

		return array(
			'status' => self::OK,
			'title'  => __( 'Loopback requests are allowed', 'past-due-actions' ),
			'detail' => __( 'Nothing in the configuration is stopping the site from calling itself.', 'past-due-actions' ),
			'fix'    => '',
		);
	}

	/**
	 * When did anything last actually complete? A queue that has not moved in
	 * days is stopped, whatever the configuration claims.
	 *
	 * @return array
	 */
	private static function check_last_run() {
		global $wpdb;

		if ( ! PDA_Scanner::tables_exist() ) {
			return array(
				'status' => self::WARN,
				'title'  => __( 'Action Scheduler tables not found', 'past-due-actions' ),
				'detail' => __( 'The usual storage tables are missing, so this site may be using a custom store.', 'past-due-actions' ),
				'fix'    => '',
			);
		}

		$table = $wpdb->prefix . 'actionscheduler_actions';
		$last  = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix.
				"SELECT MAX(last_attempt_gmt) FROM {$table} WHERE status = %s",
				'complete'
			)
		);

		if ( ! $last || '0000-00-00 00:00:00' === $last ) {
			return array(
				'status' => self::WARN,
				'title'  => __( 'Nothing has completed yet', 'past-due-actions' ),
				'detail' => __( 'No finished actions are recorded, so there is no history to judge the queue by.', 'past-due-actions' ),
				'fix'    => '',
			);
		}

		$ago   = time() - strtotime( $last . ' UTC' );
		$human = human_time_diff( strtotime( $last . ' UTC' ), time() );

		if ( $ago > DAY_IN_SECONDS ) {
			return array(
				'status' => self::FAIL,
				'title'  => sprintf(
					/* translators: %s: human readable time difference */
					__( 'The queue has not run in %s', 'past-due-actions' ),
					$human
				),
				'detail' => __( 'The last action to finish did so a long time ago. Whatever the settings say, work is not being processed.', 'past-due-actions' ),
				'fix'    => __( 'Use "Run the queue now" below. If that clears actions, scheduling is the problem rather than the jobs themselves.', 'past-due-actions' ),
			);
		}

		if ( $ago > HOUR_IN_SECONDS * 3 ) {
			return array(
				'status' => self::WARN,
				'title'  => sprintf(
					/* translators: %s: human readable time difference */
					__( 'Last completed action was %s ago', 'past-due-actions' ),
					$human
				),
				'detail' => __( 'Slower than a healthy queue, which usually processes something every few minutes on an active store.', 'past-due-actions' ),
				'fix'    => '',
			);
		}

		return array(
			'status' => self::OK,
			'title'  => sprintf(
				/* translators: %s: human readable time difference */
				__( 'Queue ran %s ago', 'past-due-actions' ),
				$human
			),
			'detail' => __( 'Actions are completing, so the runner is alive.', 'past-due-actions' ),
			'fix'    => '',
		);
	}

	/**
	 * Failures mean the runner is working and a specific job is broken. That is
	 * a completely different fix from a stopped queue, and telling them apart is
	 * most of the value here.
	 *
	 * @return array
	 */
	private static function check_failures() {
		$summary = PDA_Scanner::summary();

		if ( $summary['failed'] > 50 ) {
			return array(
				'status' => self::FAIL,
				'title'  => sprintf(
					/* translators: %d: number of failed actions */
					__( '%d actions have failed', 'past-due-actions' ),
					(int) $summary['failed']
				),
				'detail' => __( 'The queue is running but these jobs error every time they are attempted. That points at a broken plugin rather than at scheduling.', 'past-due-actions' ),
				'fix'    => __( 'Look at which hook is failing in the table below, and check that plugin\'s own error log or support forum.', 'past-due-actions' ),
			);
		}

		if ( $summary['failed'] > 0 ) {
			return array(
				'status' => self::WARN,
				'title'  => sprintf(
					/* translators: %d: number of failed actions */
					__( '%d failed actions', 'past-due-actions' ),
					(int) $summary['failed']
				),
				'detail' => __( 'A small number of failures is normal after an outage. A growing number is not.', 'past-due-actions' ),
				'fix'    => '',
			);
		}

		return array(
			'status' => self::OK,
			'title'  => __( 'No failed actions', 'past-due-actions' ),
			'detail' => __( 'Nothing is erroring out.', 'past-due-actions' ),
			'fix'    => '',
		);
	}

	/**
	 * A very large backlog cannot be cleared by the default batch size in any
	 * reasonable time, so "just wait" stops being advice.
	 *
	 * @return array
	 */
	private static function check_backlog_size() {
		$summary = PDA_Scanner::summary();

		if ( PDA_Scanner::is_huge() ) {
			return array(
				'status' => self::FAIL,
				'title'  => __( 'The actions table is enormous', 'past-due-actions' ),
				'detail' => sprintf(
					/* translators: %s: row threshold */
					__( 'There are over %s rows. At this size the queue cannot catch up, every admin screen that touches the table gets slow, and backups start failing. Something is creating far more scheduled work than the site can process.', 'past-due-actions' ),
					number_format_i18n( PDA_Scanner::HUGE_TABLE )
				),
				'fix'    => __( 'Deal with the largest hook below first, then find what is generating it. Clearing without fixing the source only buys a few days.', 'past-due-actions' ),
			);
		}

		if ( $summary['past_due'] > 10000 ) {
			return array(
				'status' => self::FAIL,
				'title'  => sprintf(
					/* translators: %s: number of past-due actions */
					__( '%s past-due actions is too many to clear normally', 'past-due-actions' ),
					number_format_i18n( $summary['past_due'] )
				),
				'detail' => __( 'At the default batch size this backlog would take days to work through, and new actions keep arriving. Something is generating far more work than the site can process.', 'past-due-actions' ),
				'fix'    => __( 'Find the worst hook in the table below and cancel its backlog, then fix whatever is creating them. Clearing without fixing the source only buys time.', 'past-due-actions' ),
			);
		}

		// Anything past due at all is worth flagging. Saying "backlog is small"
		// next to a card reading 301 makes the whole page look broken - and the
		// person is here precisely because that number is not zero.
		if ( $summary['past_due'] > 0 ) {
			return array(
				'status' => self::WARN,
				'title'  => sprintf(
					/* translators: %s: number of past-due actions */
					__( '%s actions are waiting past their scheduled time', 'past-due-actions' ),
					number_format_i18n( $summary['past_due'] )
				),
				'detail' => __( 'A backlog this size will clear on its own once the queue runs normally. If it is not shrinking, the checks above explain why.', 'past-due-actions' ),
				'fix'    => '',
			);
		}

		return array(
			'status' => self::OK,
			'title'  => __( 'Nothing is past due', 'past-due-actions' ),
			'detail' => __( 'Every scheduled action is either finished or still in the future.', 'past-due-actions' ),
			'fix'    => '',
		);
	}

	/**
	 * Short execution limits kill long batches part-way through, which looks
	 * exactly like a stalled queue from the outside.
	 *
	 * @return array
	 */
	private static function check_php_limits() {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit > 0 && $limit < 30 ) {
			return array(
				'status' => self::WARN,
				'title'  => sprintf(
					/* translators: %d: seconds */
					__( 'PHP stops scripts after %d seconds', 'past-due-actions' ),
					$limit
				),
				'detail' => __( 'Action Scheduler processes work in batches. A short limit can cut a batch off part-way, so the same actions are attempted again and again without ever finishing.', 'past-due-actions' ),
				'fix'    => __( 'Ask your host to raise max_execution_time to at least 60 seconds.', 'past-due-actions' ),
			);
		}

		return array(
			'status' => self::OK,
			'title'  => __( 'PHP limits look workable', 'past-due-actions' ),
			'detail' => sprintf(
				/* translators: 1: execution limit, 2: memory limit */
				__( 'Execution limit %1$s, memory %2$s.', 'past-due-actions' ),
				$limit ? $limit . 's' : __( 'none', 'past-due-actions' ),
				esc_html( (string) ini_get( 'memory_limit' ) )
			),
			'fix'    => '',
		);
	}
}
