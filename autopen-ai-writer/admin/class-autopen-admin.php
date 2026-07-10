<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * This class handles all aspects of the plugin's back-end, including
 * creating the admin menu, enqueueing scripts and styles, registering settings,
 * handling AJAX requests, and displaying notices.
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AutoPen_Admin {

	/**
	 * Initializes the admin-side hooks.
	 *
	 * @since 1.0.0
	*/
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
		
		// Register all AJAX handlers for admin operations.
		add_action( 'wp_ajax_autopen_validate_api_key', [ $this, 'ajax_validate_api_key' ] );
		add_action( 'wp_ajax_autopen_manual_start', [ $this, 'ajax_manual_start' ] );
		add_action( 'wp_ajax_autopen_manual_step', [ $this, 'ajax_manual_step' ] );
		add_action( 'wp_ajax_autopen_get_system_status', [ $this, 'ajax_get_system_status' ] );
		add_action( 'wp_ajax_autopen_toggle_cron', [ $this, 'ajax_toggle_cron' ] );

		// Register handler for non-AJAX form submissions (e.g., clearing logs).
		add_action( 'admin_post_autopen_clear_logs', [ $this, 'handle_clear_logs' ] );
	}

	/**
	 * Adds the main menu page to the WordPress admin dashboard.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu(): void {
		add_menu_page( __( 'AutoPen', 'autopen-ai-writer' ), __( 'AutoPen', 'autopen-ai-writer' ), 'manage_options', AUTOPEN_SLUG, [ $this, 'render_settings_page' ], 'dashicons-edit-large', 30 );
	}

	/**
	 * Renders the main settings page template.
	 *
	 * @since 1.0.0
	 */
	public function render_settings_page(): void {
		require_once AUTOPEN_PLUGIN_DIR . 'admin/templates/admin-settings-page.php';
	}

	/**
	 * Enqueues admin-specific scripts and styles.
	 *
	 * @since 1.0.0
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		// Only load assets on the plugin's own admin page.
		if ( 'toplevel_page_' . AUTOPEN_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'autopen-admin-style', AUTOPEN_PLUGIN_URL . 'admin/assets/css/admin-style.css', [], AUTOPEN_VERSION );
		wp_enqueue_script( 'autopen-admin-script', AUTOPEN_PLUGIN_URL . 'admin/assets/js/admin-script.js', [ 'jquery' ], AUTOPEN_VERSION, true );
		
		// Localize script to pass PHP variables (like AJAX URL, nonce, and translations) to JavaScript.
		wp_localize_script(
			'autopen-admin-script',
			'autopen_ajax',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'autopen-admin-nonce' ),
				'i18n'     => [
					'generating'        => __( 'Generating...', 'autopen-ai-writer' ),
					'generateNow'       => __( 'Generate Post Now', 'autopen-ai-writer' ),
					'validating'        => __( 'Validating...', 'autopen-ai-writer' ),
					'validateKey'       => __( 'Validate API Key', 'autopen-ai-writer' ),
					'enableAutomation'  => __( 'Enable Automation', 'autopen-ai-writer' ),
					'disableAutomation' => __( 'Disable Automation', 'autopen-ai-writer' ),
				],
			]
		);
	}

	/**
	 * Registers the plugin's settings with the WordPress Settings API.
	 *
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		register_setting(
			'autopen_settings_group',
			AUTOPEN_OPTIONS_KEY,
			[
				'sanitize_callback' => [ 'AutoPen_Utils', 'sanitize_options' ],
			]
		);
	}

	/**
	 * Displays admin notices by passing them to the enqueued script.
	 *
	 * This method uses transients to store messages and `wp_add_inline_script`
	 * to safely pass those messages to the frontend JavaScript for display
	 * as toast notifications.
	 *
	 * @since 1.0.0
	 */
	public function display_admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . AUTOPEN_SLUG !== $screen->id ) {
			return;
		}

		$notices = [];

		$success_message = get_transient( 'autopen_admin_notice_success' );
		if ( $success_message ) {
			delete_transient( 'autopen_admin_notice_success' );
			$notices[] = [
				'message' => $success_message,
				'type'    => 'success',
			];
		}

		$error_message = get_transient( 'autopen_admin_notice_error' );
		if ( $error_message ) {
			delete_transient( 'autopen_admin_notice_error' );
			$notices[] = [
				'message' => $error_message,
				'type'    => 'error',
			];
		}

		if ( ! empty( $notices ) ) {
			$script = 'document.addEventListener("DOMContentLoaded", function() {';
			foreach ( $notices as $notice ) {
				$script .= sprintf(
					'if (typeof window.showAutopenToast === "function") { window.showAutopenToast("%s", "%s"); }',
					esc_js( $notice['message'] ),
					esc_js( $notice['type'] )
				);
			}
			$script .= '});';
			wp_add_inline_script( 'autopen-admin-script', $script );
		}
	}


	/**
	 * AJAX handler for validating the OpenAI API key.
	 *
	 * @since 1.0.0
	 */
	public function ajax_validate_api_key(): void {
		check_ajax_referer( 'autopen-admin-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'autopen-ai-writer' ) ], 403 );
		}
		
		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'API Key field is empty.', 'autopen-ai-writer' ) ] );
		}
		
		$result = AutoPen_API::validate_key( $api_key );
		
		if ( $result['success'] ) {
			AutoPen_Logger::info( 'API Key validation successful.' );
			wp_send_json_success( [ 'message' => $result['message'] ] );
		} else {
			AutoPen_Logger::error( 'API Key validation failed: ' . $result['message'] );
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
	}

	/**
	 * AJAX handler for starting the manual generation process.
	 *
	 * @since 1.0.0
	 */
	public function ajax_manual_start(): void {
		check_ajax_referer( 'autopen-admin-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'autopen-ai-writer' ) ], 403 );
		}

		try {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@ini_set( 'max_execution_time', 300 );
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@ini_set( 'memory_limit', '512M' );
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 300 );

			AutoPen_Logger::info( 'Manual generation started.' );

			$result = AutoPen_Generator::manual_start();

			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			AutoPen_Logger::error( 'Start failed: ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * AJAX handler for executing a single step of the manual generation.
	 *
	 * @since 1.0.0
	 */
	public function ajax_manual_step(): void {
		check_ajax_referer( 'autopen-admin-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'autopen-ai-writer' ) ], 403 );
		}

		try {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@ini_set( 'max_execution_time', 300 );
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@ini_set( 'memory_limit', '512M' );
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 300 );

			$result = AutoPen_Generator::manual_do_step();

			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			// If a step fails, clean up the transient state and log the error.
			$user_id = get_current_user_id();
			$state   = get_transient( 'autopen_manual_state_' . $user_id );
			if ( $state && isset( $state['raw_topic'] ) ) {
				AutoPen_Generator::handle_failure( $state['raw_topic'], $e->getMessage() );
			}
			delete_transient( 'autopen_manual_state_' . $user_id );
			AutoPen_Logger::error( 'Step failed: ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * AJAX handler for fetching the latest system status.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_system_status(): void {
		check_ajax_referer( 'autopen-admin-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'autopen-ai-writer' ) ], 403 );
		}
		wp_send_json_success( AutoPen_Utils::get_system_status() );
	}

	/**
	 * AJAX handler for toggling the cron automation on and off.
	 *
	 * @since 1.0.0
	 */
	public function ajax_toggle_cron(): void {
		check_ajax_referer( 'autopen-admin-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'autopen-ai-writer' ) ], 403 );
		}

		// Get the latest options directly from the database to avoid stale data.
		$options       = AutoPen_Utils::get_options();
		$current_state = (int) ( $options['cron_enabled'] ?? 0 );
		
		// Toggle the state.
		$new_state               = 1 === $current_state ? 0 : 1;
		$options['cron_enabled'] = $new_state;
		
		// Save the updated options.
		update_option( AUTOPEN_OPTIONS_KEY, $options );
		
		// Clear WordPress object cache for the option to ensure the next read is fresh.
		wp_cache_delete( AUTOPEN_OPTIONS_KEY, 'options' );
		
		// Verify the update by reading the option again.
		$verification_options = get_option( AUTOPEN_OPTIONS_KEY );
		$actual_new_state     = (int) ( $verification_options['cron_enabled'] ?? 0 );
		
		$is_now_enabled = 1 === $actual_new_state;
		
		AutoPen_Logger::info( 'Automation ' . ( $is_now_enabled ? 'Enabled' : 'Disabled' ) . ' by user.' );
		
		// Reschedule the cron event based on the new, verified state.
		AutoPen_Cron::reschedule_event( $verification_options );
		
		$message = $is_now_enabled
			? __( 'Automation Enabled.', 'autopen-ai-writer' )
			: __( 'Automation Disabled.', 'autopen-ai-writer' );

		// Send the final state back to the frontend.
		wp_send_json_success(
			[
				'is_enabled' => $is_now_enabled,
				'message'    => $message,
			]
		);
	}

	/**
	 * Handles the non-AJAX form submission for clearing logs.
	 *
	 * @since 1.0.0
	 */
	public function handle_clear_logs(): void {
		// Verify nonce and user capabilities for security.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'autopen-clear-logs' ) ) {
			set_transient( 'autopen_admin_notice_error', __( 'Invalid nonce.', 'autopen-ai-writer' ), 5 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . AUTOPEN_SLUG . '&tab=logs' ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			set_transient( 'autopen_admin_notice_error', __( 'Permission denied.', 'autopen-ai-writer' ), 5 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . AUTOPEN_SLUG . '&tab=logs' ) );
			exit;
		}

		// Clear logs and set a success notice.
		AutoPen_Logger::clear_logs();
		set_transient( 'autopen_admin_notice_success', __( 'Recent logs have been cleared.', 'autopen-ai-writer' ), 5 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . AUTOPEN_SLUG . '&tab=logs' ) );
		exit;
	}
}