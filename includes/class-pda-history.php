<?php
/**
 * A backlog of 848 means nothing. 848 and rising means something.
 *
 * The free plugin answers "how bad is it right now". The question a store owner
 * asks next is "is this getting worse, or did it already peak" - and that needs
 * yesterday's number, which nobody records. Action Scheduler keeps no history
 * of queue depth at all.
 *
 * Stored as a capped array in one option rather than a custom table: no schema
 * to migrate, no dbDelta on activation, works identically on MySQL and SQLite,
 * and uninstall stays a single delete_option() call. Ninety samples of four
 * integers is a few kilobytes - well inside what an autoloaded option should
 * never be, which is why it is stored with autoload off.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backlog samples over time.
 */
class PDA_History {

	const OPTION = 'pda_history';

	/**
	 * How many samples to keep.
	 *
	 * At hourly sampling that is just under four days of detail; at daily, three
	 * months. Both are enough to answer "is it rising", which is the only
	 * question this data exists to answer.
	 */
	const KEEP = 90;

	/**
	 * Record one sample.
	 *
	 * Called from the scheduled check, so the sampling interval is whatever the
	 * check runs at - hourly on Pro, daily otherwise.
	 *
	 * @param array $summary Output of PDA_Scanner::summary().
	 * @return void
	 */
	public static function record( array $summary ) {
		if ( empty( $summary['available'] ) ) {
			return;
		}

		$rows   = self::all();
		$rows[] = array(
			't' => time(),
			'p' => (int) $summary['past_due'],
			'q' => (int) $summary['pending'],
			'f' => (int) $summary['failed'],
		);

		if ( count( $rows ) > self::KEEP ) {
			$rows = array_slice( $rows, -self::KEEP );
		}

		update_option( self::OPTION, $rows, false );
	}

	/**
	 * Every stored sample, oldest first.
	 *
	 * @return array<int,array{t:int,p:int,q:int,f:int}>
	 */
	public static function all() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Wipe the record.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Which way the backlog is going.
	 *
	 * Compares the newest sample against the oldest one available rather than
	 * against the previous sample: a queue that drains every night and refills
	 * every morning would otherwise report a different direction on every page
	 * load, which is worse than reporting nothing.
	 *
	 * @return array{known:bool,direction:string,change:int,percent:float,from:int,to:int,samples:int,since:int}
	 */
	public static function trend() {
		$rows = self::all();
		$n    = count( $rows );

		if ( $n < 2 ) {
			return array(
				'known'     => false,
				'direction' => 'unknown',
				'change'    => 0,
				'percent'   => 0.0,
				'from'      => $n ? (int) $rows[0]['p'] : 0,
				'to'        => $n ? (int) $rows[0]['p'] : 0,
				'samples'   => $n,
				'since'     => $n ? (int) $rows[0]['t'] : 0,
			);
		}

		$first  = $rows[0];
		$last   = $rows[ $n - 1 ];
		$change = (int) $last['p'] - (int) $first['p'];

		// A queue that moves by a handful of actions is noise, not a trend. Ten
		// is the smallest number that is not somebody's cron firing twice.
		if ( abs( $change ) < 10 ) {
			$direction = 'steady';
		} elseif ( $change > 0 ) {
			$direction = 'rising';
		} else {
			$direction = 'falling';
		}

		$percent = (int) $first['p'] > 0
			? ( $change / (int) $first['p'] ) * 100
			: 0.0;

		return array(
			'known'     => true,
			'direction' => $direction,
			'change'    => $change,
			'percent'   => round( $percent, 1 ),
			'from'      => (int) $first['p'],
			'to'        => (int) $last['p'],
			'samples'   => $n,
			'since'     => (int) $first['t'],
		);
	}

	/**
	 * The most recent samples, newest first, for display.
	 *
	 * @param int $limit How many.
	 * @return array<int,array{t:int,p:int,q:int,f:int}>
	 */
	public static function recent( $limit = 14 ) {
		$rows = array_reverse( self::all() );
		return array_slice( $rows, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Highest past-due figure on record, for scaling the bars.
	 *
	 * @return int
	 */
	public static function peak() {
		$peak = 0;
		foreach ( self::all() as $row ) {
			$peak = max( $peak, (int) $row['p'] );
		}
		return $peak;
	}
}
