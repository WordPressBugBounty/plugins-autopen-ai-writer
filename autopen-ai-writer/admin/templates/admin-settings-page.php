<?php
/**
 * Template for the admin settings page.
 *
 * This file is responsible for rendering the entire user interface for the
 * plugin's admin dashboard, including the header, action buttons, tabs,
 * and content for each section (Dashboard, Settings, Prompts, Logs).
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Security check: Ensure the user has the required permissions.
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

// Get plugin options and determine the current state.
$options         = AutoPen_Utils::get_options();
$is_cron_enabled = (bool) $options['cron_enabled'];
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple navigation parameter, no data modification
$active_tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
?>

<div class="wrap autopen-wrap">
	<div class="autopen-header">
		<h1><span class="dashicons dashicons-edit-large"></span> <?php esc_html_e( 'AutoPen – AI Content Writer', 'autopen-ai-writer' ); ?> <span class="autopen-version">v<?php echo esc_html( AUTOPEN_VERSION ); ?></span></h1>
		<p class="autopen-subtitle"><?php esc_html_e( 'Automated, high-quality content generation powered by OpenAI.', 'autopen-ai-writer' ); ?></p>
	</div>

	<div class="autopen-actions-panel">
		<button id="autopen-toggle-cron-btn" class="button <?php echo $is_cron_enabled ? 'button-secondary' : 'button-primary'; ?>">
			<span class="dashicons dashicons-<?php echo $is_cron_enabled ? 'no' : 'update'; ?>"></span>
			<span class="autopen-button-text"><?php echo $is_cron_enabled ? esc_html__( 'Disable Automation', 'autopen-ai-writer' ) : esc_html__( 'Enable Automation', 'autopen-ai-writer' ); ?></span>
		</button>
		<button id="autopen-run-now-btn" class="button button-primary"><span class="dashicons dashicons-controls-forward"></span> <?php esc_html_e( 'Generate Post Now', 'autopen-ai-writer' ); ?></button>
	</div>

	<div id="autopen-toast-container"></div>

	<div class="autopen-container">
		<div class="autopen-main-content">
			<nav class="nav-tab-wrapper">
				<a href="?page=<?php echo esc_attr( AUTOPEN_SLUG ); ?>&tab=dashboard" class="nav-tab <?php echo 'dashboard' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Dashboard', 'autopen-ai-writer' ); ?></a>
				<a href="?page=<?php echo esc_attr( AUTOPEN_SLUG ); ?>&tab=settings" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'autopen-ai-writer' ); ?></a>
				<a href="?page=<?php echo esc_attr( AUTOPEN_SLUG ); ?>&tab=prompts" class="nav-tab <?php echo 'prompts' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Prompts', 'autopen-ai-writer' ); ?></a>
				<a href="?page=<?php echo esc_attr( AUTOPEN_SLUG ); ?>&tab=logs" class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Logs', 'autopen-ai-writer' ); ?></a>
			</nav>

			<?php if ( 'logs' === $active_tab ) : ?>
				<div class="autopen-settings-form">
					<?php $logs = AutoPen_Logger::get_recent_logs(); ?>
					<h2><?php esc_html_e( 'Activity Logs', 'autopen-ai-writer' ); ?></h2>
					<p><?php esc_html_e( 'Showing the last 100 log entries.', 'autopen-ai-writer' ); ?></p>
					
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'autopen-clear-logs' ); ?>
						<input type="hidden" name="action" value="autopen_clear_logs">
						<button type="submit" class="button button-secondary"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clear Recent Logs', 'autopen-ai-writer' ); ?></button>
					</form>

					<table class="autopen-log-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Timestamp', 'autopen-ai-writer' ); ?></th>
								<th><?php esc_html_e( 'Level', 'autopen-ai-writer' ); ?></th>
								<th><?php esc_html_e( 'Message', 'autopen-ai-writer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $logs ) ) : ?>
								<tr>
									<td colspan="3"><?php esc_html_e( 'No log entries found.', 'autopen-ai-writer' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $logs as $log ) : ?>
									<tr>
										<td><?php echo esc_html( $log['timestamp'] ); ?></td>
										<td><span class="autopen-log-level autopen-log-<?php echo esc_attr( strtolower( $log['level'] ) ); ?>"><?php echo esc_html( $log['level'] ); ?></span></td>
										<td><?php echo esc_html( $log['message'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<form method="post" action="options.php" class="autopen-settings-form">
					<?php
					settings_fields( 'autopen_settings_group' );

					if ( 'dashboard' === $active_tab ) {
						?>
						<h2><?php esc_html_e( 'System Dashboard', 'autopen-ai-writer' ); ?></h2>
						<div id="autopen-stats-grid" class="autopen-stats-grid">
							<div class="autopen-stat-card">
								<div class="autopen-stat-icon"><span class="dashicons dashicons-lightbulb"></span></div>
								<div>
									<div class="autopen-stat-number" data-stat="topics_queued">...</div>
									<div class="autopen-stat-label"><?php esc_html_e( 'Topics in Queue', 'autopen-ai-writer' ); ?></div>
								</div>
							</div>
							<div class="autopen-stat-card">
								<div class="autopen-stat-icon"><span class="dashicons dashicons-edit-page"></span></div>
								<div>
									<div class="autopen-stat-number" data-stat="posts_generated">...</div>
									<div class="autopen-stat-label"><?php esc_html_e( 'Posts Generated', 'autopen-ai-writer' ); ?></div>
								</div>
							</div>
							<div class="autopen-stat-card">
								<div class="autopen-stat-icon"><span class="dashicons dashicons-clock"></span></div>
								<div>
									<div class="autopen-stat-label"><?php esc_html_e( 'Next Scheduled Run', 'autopen-ai-writer' ); ?></div>
									<div class="autopen-stat-value" data-stat="next_run">...</div>
								</div>
							</div>
							<?php
							if ( $options['last_post_id'] && get_post( $options['last_post_id'] ) ) :
								?>
								<div class="autopen-stat-card">
									<div class="autopen-stat-icon"><span class="dashicons dashicons-external"></span></div>
									<div>
										<div class="autopen-stat-label"><?php esc_html_e( 'Latest Generated Post', 'autopen-ai-writer' ); ?></div>
										<div class="autopen-stat-value"><a href="<?php echo esc_url( get_edit_post_link( $options['last_post_id'] ) ); ?>" target="_blank"><?php echo esc_html( wp_trim_words( get_the_title( $options['last_post_id'] ), 6 ) ); ?></a></div>
									</div>
								</div>
							<?php endif; ?>
						</div>
						<?php
					} elseif ( 'settings' === $active_tab ) {
						AutoPen_Utils::render_settings_fields( 'api_config', __( 'API Configuration', 'autopen-ai-writer' ) );
						AutoPen_Utils::render_settings_fields( 'content_settings', __( 'Content Settings', 'autopen-ai-writer' ) );
						AutoPen_Utils::render_settings_fields( 'automation', __( 'Automation & Scheduling', 'autopen-ai-writer' ) );
						submit_button();
					} elseif ( 'prompts' === $active_tab ) {
						AutoPen_Utils::render_settings_fields( 'prompts', __( 'AI Prompt Configuration', 'autopen-ai-writer' ) );
						submit_button();
					}
					?>
				</form>
			<?php endif; ?>
		</div>

		<div class="autopen-sidebar">
			<div class="autopen-widget">
				<h3><span class="dashicons dashicons-info-outline"></span> <?php esc_html_e( 'System Status', 'autopen-ai-writer' ); ?></h3>
				<div id="autopen-status-widget" class="autopen-status autopen-status-loading"><span class="autopen-status-indicator"></span><span class="autopen-status-text"><?php esc_html_e( 'Loading...', 'autopen-ai-writer' ); ?></span></div>
				<div class="autopen-widget-content">
					<p><strong><?php esc_html_e( 'API Key:', 'autopen-ai-writer' ); ?></strong> <span data-status="api_key_status">...</span></p>
					<p><strong><?php esc_html_e( 'WP Cron:', 'autopen-ai-writer' ); ?></strong> <span data-status="cron_status">...</span></p>
					<p><strong><?php esc_html_e( 'PHP Version:', 'autopen-ai-writer' ); ?></strong> <?php echo esc_html( phpversion() ); ?></p>
				</div>
			</div>
			<div class="autopen-widget autopen-support-widget">
				<h3><span class="dashicons dashicons-sos"></span> <?php esc_html_e( 'Support & Docs', 'autopen-ai-writer' ); ?></h3>
				<div class="autopen-widget-content">
					<p><?php esc_html_e( 'If you find this plugin useful, please consider giving it a review or reaching out for professional support.', 'autopen-ai-writer' ); ?></p>
					<a href="https://www.linkedin.com/in/lkimran007/" target="_blank" class="button button-primary"><?php esc_html_e( 'Hire Me on LinkedIn', 'autopen-ai-writer' ); ?></a>
				</div>
			</div>
		</div>
	</div>
</div>