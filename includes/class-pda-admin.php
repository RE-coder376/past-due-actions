<?php
/**
 * The screen. One page, read top to bottom, no tabs.
 *
 * Someone arriving here has just read "848 past-due actions found; something
 * may be wrong" and wants three answers in this order: how bad is it, why is it
 * happening, and what do I press. The layout follows exactly that.
 *
 * @package PastDueActions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class PDA_Admin {

	const PAGE  = 'past-due-actions';
	const NONCE = 'pda_action';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_pda_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_pda_retry', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_post_pda_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_pda_settings', array( __CLASS__, 'handle_settings' ) );
	}

	/**
	 * Under Tools, because this is a diagnostic and not a store setting - and
	 * because Action Scheduler is used by plugins other than WooCommerce.
	 */
	public static function menu() {
		add_management_page(
			__( 'Past-Due Actions', 'past-due-actions' ),
			__( 'Past-Due Actions', 'past-due-actions' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Shared guard for every write.
	 */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot do that.', 'past-due-actions' ) );
		}
		check_admin_referer( self::NONCE );
	}

	/**
	 * Back to the page with a message.
	 *
	 * @param string $notice Message key.
	 * @param string $extra  Optional detail.
	 */
	private static function back( $notice, $extra = '' ) {
		$args = array(
			'page'       => self::PAGE,
			'pda_notice' => $notice,
		);
		if ( '' !== $extra ) {
			$args['pda_detail'] = rawurlencode( $extra );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Process a batch now.
	 */
	public static function handle_run() {
		self::guard();
		$result = PDA_Repair::run_queue();
		self::back( 'ran', $result['message'] );
	}

	/**
	 * Re-queue failures for one hook.
	 */
	public static function handle_retry() {
		self::guard();
		$hook = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		$n    = $hook ? PDA_Repair::retry_hook( $hook ) : 0;
		self::back(
			'retried',
			sprintf(
				/* translators: 1: count, 2: hook */
				__( 'Re-queued %1$d failed actions for %2$s.', 'past-due-actions' ),
				$n,
				$hook
			)
		);
	}

	/**
	 * Cancel a backlog for one hook.
	 */
	public static function handle_cancel() {
		self::guard();
		$hook = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		$n    = $hook ? PDA_Repair::cancel_hook( $hook ) : 0;
		self::back(
			'cancelled',
			sprintf(
				/* translators: 1: count, 2: hook */
				__( 'Cancelled %1$d past-due actions for %2$s. They will not run.', 'past-due-actions' ),
				$n,
				$hook
			)
		);
	}

	/**
	 * Save the alert settings.
	 */
	public static function handle_settings() {
		self::guard();

		update_option( PDA_Alerts::OPT_ON, empty( $_POST['enabled'] ) ? 0 : 1, false );

		$limit = isset( $_POST['threshold'] ) ? absint( wp_unslash( $_POST['threshold'] ) ) : 100;
		update_option( PDA_Alerts::OPT_LIMIT, max( $limit, 1 ), false );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		update_option( PDA_Alerts::OPT_EMAIL, is_email( $email ) ? $email : '', false );

		self::back( 'saved' );
	}

	/**
	 * Colour for a diagnostic row.
	 *
	 * @param string $status Status constant.
	 * @return string
	 */
	private static function tint( $status ) {
		switch ( $status ) {
			case PDA_Diagnostics::FAIL:
				return '#b32d2e';
			case PDA_Diagnostics::WARN:
				return '#996800';
			default:
				return '#007017';
		}
	}

	/**
	 * The page.
	 */
	public static function render() {
		$summary = PDA_Scanner::summary();
		$notice  = isset( $_GET['pda_notice'] ) ? sanitize_key( wp_unslash( $_GET['pda_notice'] ) ) : '';
		$detail  = isset( $_GET['pda_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['pda_detail'] ) ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Past-Due Actions', 'past-due-actions' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo 'ran' === $notice ? 'info' : 'success'; ?> is-dismissible">
					<p><?php echo esc_html( $detail ? $detail : __( 'Saved.', 'past-due-actions' ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $summary['available'] ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Action Scheduler storage was not found on this site, so there is nothing to report.', 'past-due-actions' ); ?>
				</p></div>
				</div>
				<?php
				return;
			endif;
			?>

			<h2 style="margin-top:1.4em"><?php esc_html_e( 'How bad is it?', 'past-due-actions' ); ?></h2>
			<p class="description" style="max-width:52em;margin-bottom:.8em">
				<?php esc_html_e( 'Counted right now, so this can differ slightly from the number in WooCommerce\'s own notice — that one is cached and may be minutes old.', 'past-due-actions' ); ?>
			</p>
			<div style="display:flex;gap:1rem;flex-wrap:wrap;max-width:70em">
				<?php
				$cards = array(
					array(
						__( 'Past due', 'past-due-actions' ),
						number_format_i18n( $summary['past_due'] ),
						$summary['past_due'] > 0
							? sprintf(
								/* translators: %s: human readable duration */
								__( 'oldest is %s late', 'past-due-actions' ),
								$summary['oldest_by']
							)
							: __( 'nothing waiting', 'past-due-actions' ),
						$summary['past_due'] > 0 ? '#b32d2e' : '#007017',
					),
					array( __( 'Pending in total', 'past-due-actions' ), number_format_i18n( $summary['pending'] ), __( 'including future work', 'past-due-actions' ), '#1d2327' ),
					array( __( 'Failed', 'past-due-actions' ), number_format_i18n( $summary['failed'] ), __( 'errored every attempt', 'past-due-actions' ), $summary['failed'] > 0 ? '#996800' : '#007017' ),
				);
				foreach ( $cards as $card ) :
					?>
					<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:1rem 1.2rem;min-width:13rem">
						<div style="font-size:.82rem;color:#50575e"><?php echo esc_html( $card[0] ); ?></div>
						<div style="font-size:1.9rem;font-weight:600;color:<?php echo esc_attr( $card[3] ); ?>"><?php echo esc_html( $card[1] ); ?></div>
						<div style="font-size:.8rem;color:#787c82"><?php echo esc_html( $card[2] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Why is it happening?', 'past-due-actions' ); ?></h2>
			<table class="widefat striped" style="max-width:70em">
				<tbody>
				<?php foreach ( PDA_Diagnostics::run() as $check ) : ?>
					<tr>
						<td style="width:2em;vertical-align:top;padding-top:1em">
							<span style="display:inline-block;width:.7em;height:.7em;border-radius:50%;background:<?php echo esc_attr( self::tint( $check['status'] ) ); ?>"></span>
						</td>
						<td>
							<strong><?php echo esc_html( $check['title'] ); ?></strong>
							<p style="margin:.3em 0 0;color:#50575e"><?php echo esc_html( $check['detail'] ); ?></p>
							<?php if ( $check['fix'] ) : ?>
								<p style="margin:.4em 0 0"><strong><?php esc_html_e( 'What to do:', 'past-due-actions' ); ?></strong>
									<?php echo wp_kses( $check['fix'], array( 'code' => array() ) ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em"><?php esc_html_e( 'What is backed up?', 'past-due-actions' ); ?></h2>
			<?php $hooks = PDA_Scanner::past_due_by_hook( 20 ); ?>
			<table class="widefat striped" style="max-width:70em">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Hook', 'past-due-actions' ); ?></th>
						<th><?php esc_html_e( 'Probably from', 'past-due-actions' ); ?></th>
						<th><?php esc_html_e( 'Waiting', 'past-due-actions' ); ?></th>
						<th><?php esc_html_e( 'Oldest', 'past-due-actions' ); ?></th>
						<th><?php esc_html_e( 'Failed', 'past-due-actions' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $hooks ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nothing is past due. The queue is keeping up.', 'past-due-actions' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $hooks as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row['hook'] ); ?></code></td>
							<td><?php echo esc_html( $row['owner'] ); ?></td>
							<td><strong><?php echo $row['total'] ? esc_html( number_format_i18n( $row['total'] ) ) : '—'; ?></strong></td>
							<td><?php echo $row['overdue'] ? esc_html( $row['overdue'] ) : '—'; ?></td>
							<td><?php echo $row['failures'] ? esc_html( number_format_i18n( $row['failures'] ) ) : '—'; ?></td>
							<td style="white-space:nowrap">
								<?php if ( $row['failures'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
										<input type="hidden" name="action" value="pda_retry">
										<input type="hidden" name="hook" value="<?php echo esc_attr( $row['hook'] ); ?>">
										<?php wp_nonce_field( self::NONCE ); ?>
										<button class="button button-small"><?php esc_html_e( 'Retry failed', 'past-due-actions' ); ?></button>
									</form>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
									onsubmit="return confirm('<?php echo esc_js( __( 'Cancel these actions? The work will not happen. Renewals, emails or syncs waiting in this hook will be skipped.', 'past-due-actions' ) ); ?>')">
									<input type="hidden" name="action" value="pda_cancel">
									<input type="hidden" name="hook" value="<?php echo esc_attr( $row['hook'] ); ?>">
									<?php wp_nonce_field( self::NONCE ); ?>
									<button class="button button-small"><?php esc_html_e( 'Cancel backlog', 'past-due-actions' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Fix it', 'past-due-actions' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pda_run">
				<?php wp_nonce_field( self::NONCE ); ?>
				<button class="button button-primary"><?php esc_html_e( 'Run the queue now', 'past-due-actions' ); ?></button>
				<p class="description" style="max-width:44em">
					<?php esc_html_e( 'Processes one batch immediately. This is also the quickest test: if actions clear, the jobs are fine and nothing is triggering the queue automatically. If nothing clears, the jobs themselves are failing.', 'past-due-actions' ); ?>
				</p>
			</form>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Warn me next time', 'past-due-actions' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:44em">
				<input type="hidden" name="action" value="pda_settings">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Daily check', 'past-due-actions' ); ?></th>
						<td><label>
							<input type="checkbox" name="enabled" value="1" <?php checked( PDA_Alerts::enabled() ); ?>>
							<?php esc_html_e( 'Email me if the backlog gets large', 'past-due-actions' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'At most one email a day. Stuck actions fail silently, so without this nobody finds out until a customer complains.', 'past-due-actions' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pda-threshold"><?php esc_html_e( 'Warn above', 'past-due-actions' ); ?></label></th>
						<td><input type="number" min="1" id="pda-threshold" name="threshold" value="<?php echo esc_attr( (string) PDA_Alerts::threshold() ); ?>" class="small-text">
							<?php esc_html_e( 'past-due actions', 'past-due-actions' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="pda-email"><?php esc_html_e( 'Send to', 'past-due-actions' ); ?></label></th>
						<td><input type="email" id="pda-email" name="email" class="regular-text"
							value="<?php echo esc_attr( (string) get_option( PDA_Alerts::OPT_EMAIL, '' ) ); ?>"
							placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'past-due-actions' ) ); ?>
			</form>
		</div>
		<?php
	}
}
