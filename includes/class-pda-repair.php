<?php
/**
 * The three things a stuck store owner actually needs to do.
 *
 * Deliberately small. Every action here is nonce-checked, capability-gated and
 * reversible except cancellation, which says so plainly before it runs. The
 * competing plugins in this space delete rows freely; deleting somebody's
 * pending subscription renewals without a clear warning would be worse than
 * doing nothing at all.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs, retries and cancels.
 */
class PDA_Repair {

	/**
	 * Ask Action Scheduler to process a batch immediately.
	 *
	 * This is the diagnostic that answers the real question: if a manual run
	 * clears actions, the jobs are fine and scheduling is broken. If it clears
	 * nothing, the jobs themselves are failing.
	 *
	 * @return array{ran:int,message:string}
	 */
	public static function run_queue() {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return array(
				'ran'     => 0,
				'message' => __( 'Action Scheduler is not loaded.', 'past-due-actions' ),
			);
		}

		$before = PDA_Scanner::summary();

		try {
			// The runner is the supported entry point. Calling the store
			// directly would skip the hooks other plugins rely on.
			$runner = ActionScheduler::runner();
			$ran    = (int) $runner->run( 'Past-Due Actions' );
		} catch ( Throwable $e ) {
			return array(
				'ran'     => 0,
				/* translators: %s: error message */
				'message' => sprintf( __( 'The queue could not run: %s', 'past-due-actions' ), $e->getMessage() ),
			);
		}

		// The cached summary is stale now that work has been done.
		PDA_Scanner::flush();
		$after = PDA_Scanner::summary();
		$moved = max( $before['past_due'] - $after['past_due'], 0 );

		if ( $ran > 0 ) {
			return array(
				'ran'     => $ran,
				'message' => sprintf(
					/* translators: 1: actions processed, 2: reduction in backlog */
					__( 'Processed %1$d actions. The past-due backlog fell by %2$d. Since a manual run works, the problem is that nothing is triggering the queue automatically — see the WP-Cron check above.', 'past-due-actions' ),
					$ran,
					$moved
				),
			);
		}

		return array(
			'ran'     => 0,
			'message' => __( 'The queue ran but processed nothing. That usually means the actions are failing rather than waiting — check the failures column below.', 'past-due-actions' ),
		);
	}

	/**
	 * Put failed actions for one hook back in the queue.
	 *
	 * @param string $hook  Hook name.
	 * @param int    $limit Safety ceiling per click.
	 * @return int Number re-queued.
	 */
	public static function retry_hook( $hook, $limit = 100 ) {
		global $wpdb;

		if ( ! PDA_Scanner::tables_exist() ) {
			return 0;
		}

		$ids = self::ids_for( $hook, 'failed', false, $limit );
		if ( ! $ids ) {
			return 0;
		}

		$table = $wpdb->prefix . 'actionscheduler_actions';
		$in    = implode( ',', $ids );

		// Re-queue rather than delete: the action keeps its arguments and its
		// history, so a second failure is still visible.
		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix, ids are cast integers.
				"UPDATE {$table}
				 SET status = %s, scheduled_date_gmt = %s, scheduled_date_local = %s
				 WHERE action_id IN ({$in})",
				'pending',
				gmdate( 'Y-m-d H:i:s' ),
				current_time( 'mysql' )
			)
		);

		PDA_Scanner::flush();
		return (int) $updated;
	}

	/**
	 * Cancel past-due actions for one hook.
	 *
	 * Marked cancelled rather than deleted, so the rows remain auditable and a
	 * mistake is visible afterwards. The UI states clearly that the work will
	 * not happen.
	 *
	 * @param string $hook  Hook name.
	 * @param int    $limit Safety ceiling per click.
	 * @return int Number cancelled.
	 */
	public static function cancel_hook( $hook, $limit = 1000 ) {
		global $wpdb;

		if ( ! PDA_Scanner::tables_exist() ) {
			return 0;
		}

		$ids = self::ids_for( $hook, 'pending', true, $limit );
		if ( ! $ids ) {
			return 0;
		}

		$table = $wpdb->prefix . 'actionscheduler_actions';
		$in    = implode( ',', $ids );

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix, ids are cast integers.
				"UPDATE {$table} SET status = %s WHERE action_id IN ({$in})",
				'canceled'
			)
		);

		PDA_Scanner::flush();
		return (int) $updated;
	}

	/**
	 * The ids to change, chosen first so the UPDATE can be portable.
	 *
	 * `UPDATE ... LIMIT` is a MySQL extension. SQLite only supports it when
	 * compiled with a non-default flag, and it is not standard SQL anywhere -
	 * so selecting ids and updating by primary key keeps the same safety
	 * ceiling while working on every database WordPress runs on.
	 *
	 * @param string $hook      Hook name.
	 * @param string $status    Status to match.
	 * @param bool   $past_only Restrict to actions already overdue.
	 * @param int    $limit     Maximum ids to return.
	 * @return int[] Integer ids, safe to interpolate.
	 */
	private static function ids_for( $hook, $status, $past_only, $limit ) {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';

		if ( $past_only ) {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix.
				"SELECT action_id FROM {$table}
				 WHERE hook = %s AND status = %s AND scheduled_date_gmt < %s
				 ORDER BY action_id ASC LIMIT %d",
				$hook,
				$status,
				gmdate( 'Y-m-d H:i:s' ),
				(int) $limit
			);
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix.
				"SELECT action_id FROM {$table}
				 WHERE hook = %s AND status = %s
				 ORDER BY action_id ASC LIMIT %d",
				$hook,
				$status,
				(int) $limit
			);
		}

		// Cast every id: they are interpolated into the UPDATE, so nothing
		// non-numeric may survive this step.
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}
}
