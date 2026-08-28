<?php
/**
 * Email is the wrong channel for this and everybody knows it.
 *
 * The alert that matters arrives at 3am and says renewals have stopped. Nobody
 * reads email at 3am; teams read Slack. An agency watching twenty stores does
 * not want twenty inboxes either - they want one channel and one webhook.
 *
 * Two shapes only. A Slack incoming webhook takes {"text": "..."} and is
 * recognised by its host, so there is nothing for the user to configure beyond
 * pasting the URL. Anything else gets a flat JSON document with the numbers in
 * named fields, which is what a receiving endpoint actually wants - forwarding
 * a human sentence and expecting the far end to parse it back out would be
 * hostile.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Outbound alerts to Slack or any JSON endpoint.
 */
class PDA_Webhooks {

	const OPT_URL = 'pda_webhook_url';

	/**
	 * Requests are made while cron is running. A slow endpoint must not hold
	 * the queue open, so this is short and failures are simply reported.
	 */
	const TIMEOUT = 8;

	/**
	 * The configured endpoint, or an empty string.
	 *
	 * @return string
	 */
	public static function url() {
		$url = (string) get_option( self::OPT_URL, '' );
		return self::is_valid( $url ) ? $url : '';
	}

	/**
	 * Is this a URL we are willing to post to?
	 *
	 * HTTPS only. The payload names the site and its backlog, which is not a
	 * secret but is not something to put on the wire in clear either, and an
	 * http:// webhook in 2026 is nearly always a mistake rather than a choice.
	 *
	 * @param string $url Candidate.
	 * @return bool
	 */
	public static function is_valid( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}
		return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	}

	/**
	 * Is the endpoint a Slack incoming webhook?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_slack( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'hooks.slack.com' === $host || self::ends_with( $host, '.slack.com' );
	}

	/**
	 * str_ends_with, which does not exist on PHP 7.4.
	 *
	 * @param string $haystack Subject.
	 * @param string $needle   Suffix.
	 * @return bool
	 */
	private static function ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		return 0 !== $len && substr( $haystack, -$len ) === $needle;
	}

	/**
	 * Send the alert.
	 *
	 * @param array $summary Output of PDA_Scanner::summary().
	 * @param array $hooks   Rows from PDA_Scanner::past_due_by_hook().
	 * @return array{sent:bool,message:string}
	 */
	public static function notify( array $summary, array $hooks = array() ) {
		$url = self::url();

		if ( '' === $url ) {
			return array(
				'sent'    => false,
				'message' => __( 'No webhook URL is set.', 'past-due-actions' ),
			);
		}

		$body = self::is_slack( $url )
			? array( 'text' => self::sentence( $summary, $hooks ) )
			: self::document( $summary, $hooks );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'sent'    => false,
				/* translators: %s: error message */
				'message' => sprintf( __( 'The webhook failed: %s', 'past-due-actions' ), $response->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'sent'    => false,
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'The endpoint answered %d rather than a success code.', 'past-due-actions' ),
					$code
				),
			);
		}

		return array(
			'sent'    => true,
			'message' => __( 'Webhook delivered.', 'past-due-actions' ),
		);
	}

	/**
	 * The human sentence Slack shows.
	 *
	 * @param array $summary Summary.
	 * @param array $hooks   Worst hooks.
	 * @return string
	 */
	private static function sentence( array $summary, array $hooks ) {
		$lines = array(
			sprintf(
				/* translators: 1: site name, 2: count */
				__( '*%1$s* has %2$d past-due scheduled actions.', 'past-due-actions' ),
				get_bloginfo( 'name' ),
				(int) $summary['past_due']
			),
		);

		foreach ( array_slice( $hooks, 0, 5 ) as $row ) {
			$lines[] = sprintf(
				/* translators: 1: count, 2: hook name, 3: owning plugin */
				__( '• %1$d x `%2$s` (%3$s)', 'past-due-actions' ),
				(int) $row['total'],
				$row['hook'],
				$row['owner']
			);
		}

		$lines[] = admin_url( 'tools.php?page=past-due-actions' );

		return implode( "\n", $lines );
	}

	/**
	 * The machine-readable payload every other endpoint gets.
	 *
	 * @param array $summary Summary.
	 * @param array $hooks   Worst hooks.
	 * @return array
	 */
	private static function document( array $summary, array $hooks ) {
		$worst = array();
		foreach ( array_slice( $hooks, 0, 10 ) as $row ) {
			$worst[] = array(
				'hook'     => $row['hook'],
				'owner'    => $row['owner'],
				'past_due' => (int) $row['total'],
				'failed'   => isset( $row['failures'] ) ? (int) $row['failures'] : 0,
			);
		}

		return array(
			'event'     => 'past_due_actions.alert',
			'site'      => get_bloginfo( 'name' ),
			'site_url'  => home_url(),
			'admin_url' => admin_url( 'tools.php?page=past-due-actions' ),
			'observed'  => gmdate( 'c' ),
			'past_due'  => (int) $summary['past_due'],
			'pending'   => (int) $summary['pending'],
			'failed'    => (int) $summary['failed'],
			'threshold' => PDA_Alerts::threshold(),
			'worst'     => $worst,
		);
	}
}
