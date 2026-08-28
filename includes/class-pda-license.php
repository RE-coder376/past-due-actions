<?php
/**
 * One question, asked in one place: is this a paying install?
 *
 * Every Pro feature calls PDA_License::is_pro() and nothing else. The point is
 * that the licensing vendor never leaks into feature code - when the Freemius
 * SDK is dropped in, only this file changes, and the twelve call sites in the
 * rest of the plugin stay exactly as they are.
 *
 * The free plugin ships with this returning false and every Pro path dormant.
 * That is deliberate: the wordpress.org copy must be complete and honest on its
 * own, not a demo with holes in it. Nothing that already worked is gated here.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Licence state.
 */
class PDA_License {

	/**
	 * Where the paid version is sold.
	 */
	const BUY_URL = 'https://re-coder376.github.io/past-due-actions/#pro';

	/**
	 * Cached for the request. is_pro() is called on every admin row.
	 *
	 * @var bool|null
	 */
	private static $cache = null;

	/**
	 * Is the paid version active?
	 *
	 * Checked in order of authority: the licensing SDK if it is present, then a
	 * filter, then nothing. The filter exists so the test suite can exercise
	 * the Pro paths without a licence server, and so a site can be put into Pro
	 * mode from wp-config while a purchase is being sorted out.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$pro = false;

		// Freemius, once the SDK is bundled. Guarded by function_exists so the
		// free build never fatals on a helper that is not there.
		if ( function_exists( 'pda_fs' ) ) {
			$fs = pda_fs();
			if ( is_object( $fs ) && method_exists( $fs, 'can_use_premium_code' ) ) {
				$pro = (bool) $fs->can_use_premium_code();
			}
		}

		/**
		 * Override the licence state.
		 *
		 * @param bool $pro Whether Pro features are available.
		 */
		self::$cache = (bool) apply_filters( 'pda_is_pro', $pro );

		return self::$cache;
	}

	/**
	 * Forget the cached state. Used by the tests when they flip the filter.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * A short inline nudge for a Pro-only control.
	 *
	 * Written once so the wording cannot drift between the four places it
	 * appears, and kept mild on purpose - the free plugin has to be worth
	 * installing on its own or the upgrade never gets seen.
	 *
	 * @param string $what What the paid version adds, in a few words.
	 * @return string Escaped HTML.
	 */
	public static function nudge( $what ) {
		return sprintf(
			'<span class="description">%s <a href="%s" target="_blank" rel="noopener">%s</a></span>',
			esc_html( $what ),
			esc_url( self::BUY_URL ),
			esc_html__( 'Past-Due Actions Pro', 'past-due-actions' )
		);
	}
}
