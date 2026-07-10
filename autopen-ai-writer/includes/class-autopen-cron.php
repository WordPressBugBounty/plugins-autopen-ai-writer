<?php
/**
 * Handles cron-related functionality for the plugin.
 *
 * This class is responsible for adding custom cron schedules, scheduling and
 * unscheduling the main generation event, and listening for option updates
 * that might affect the schedule (e.g., changing frequency or disabling automation).
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AutoPen_Cron {

	/**
	 * Initializes the cron-related hooks.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		// Adds custom time intervals to the list of WordPress cron schedules.
		add_filter( 'cron_schedules', [ $this, 'add_custom_schedules' ] );
		
		// Hooks the main generation function to our custom cron action.
		add_action( AUTOPEN_CRON_HOOK, [ 'AutoPen_Generator', 'cron_generate' ] );
		
		// Listens for changes to the plugin's options to reschedule the cron if needed.
		add_action( 'update_option_' . AUTOPEN_OPTIONS_KEY, [ $this, 'handle_options_update' ], 10, 2 );
	}

	/**
	 * Adds custom cron schedules to WordPress.
	 *
	 * This allows users to select more varied intervals for content generation
	 * directly from the plugin's settings page.
	 *
	 * @since 1.0.0
	 * @param  array $schedules The existing array of cron schedules.
	 * @return array The modified array of cron schedules.
	 */
	public static function add_custom_schedules( array $schedules ): array {
		$schedules['hourly'] = [
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'Every 1 Hour', 'autopen-ai-writer' ),
		];
		$schedules['autopen_every_2_hours'] = [
			'interval' => 2 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 2 Hours', 'autopen-ai-writer' ),
		];
		$schedules['autopen_every_4_hours'] = [
			'interval' => 4 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 4 Hours', 'autopen-ai-writer' ),
		];
		$schedules['autopen_every_6_hours'] = [
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 Hours', 'autopen-ai-writer' ),
		];
		$schedules['autopen_every_12_hours'] = [
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 12 Hours', 'autopen-ai-writer' ),
		];
		$schedules['daily'] = [
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once Daily', 'autopen-ai-writer' ),
		];
		$schedules['autopen_every_2_days'] = [
			'interval' => 2 * DAY_IN_SECONDS,
			'display'  => __( 'Every 2 Days', 'autopen-ai-writer' ),
		];
		$schedules['autopen_twice_weekly'] = [
			'interval' => WEEK_IN_SECONDS / 2, // 302400 seconds
			'display'  => __( 'Twice Weekly', 'autopen-ai-writer' ),
		];
		$schedules['weekly'] = [
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly', 'autopen-ai-writer' ),
		];
		return $schedules;
	}

	/**
	 * Schedules, reschedules, or clears the main cron event based on plugin settings.
	 *
	 * @since 1.0.0
	 * @param array|null $options The plugin options. If null, they are fetched automatically.
	 */
	public static function reschedule_event( ?array $options = null ): void {
		// Always clear any existing scheduled event to prevent duplicates.
		wp_clear_scheduled_hook( AUTOPEN_CRON_HOOK );
		
		// If options aren't passed, get the latest ones.
		if ( null === $options ) {
			$options = AutoPen_Utils::get_options();
		}
		
		// If automation is disabled in settings, do not schedule the event.
		if ( empty( $options['cron_enabled'] ) ) {
			AutoPen_Logger::info( 'Automation is disabled. Cron event not scheduled.' );
			return;
		}
		
		$frequency = $options['frequency'];
		$schedules = wp_get_schedules();

		// Fallback mechanism: if the saved frequency is somehow invalid, default to 'daily'.
		if ( ! array_key_exists( $frequency, $schedules ) ) {
			AutoPen_Logger::error( "Cron scheduling failed: Invalid frequency '{$frequency}' selected. Falling back to 'daily'." );
			$options['frequency'] = 'daily';
			update_option( AUTOPEN_OPTIONS_KEY, $options );
			$frequency = 'daily';
		}

		// Schedule the event to run at the specified frequency.
		// `time() + 60` adds a small delay to prevent it from running immediately on save.
		wp_schedule_event( time() + 60, $frequency, AUTOPEN_CRON_HOOK );
		AutoPen_Logger::info( "Automation scheduled. Frequency: {$frequency}." );
	}
	
	/**
	 * Detects changes in relevant options and reschedules the cron event if necessary.
	 *
	 * This is hooked to `update_option_{option_name}` and runs whenever the plugin
	 * settings are saved.
	 *
	 * @since 1.0.0
	 * @param array $old_options The options before they were updated.
	 * @param array $new_options The options after they were updated.
	 */
	public static function handle_options_update( array $old_options, array $new_options ): void {
		$freq_changed = ( $old_options['frequency'] ?? '' ) !== ( $new_options['frequency'] ?? '' );
		$cron_toggled = ( empty( $old_options['cron_enabled'] ) ) !== ( empty( $new_options['cron_enabled'] ) );
		
		// If either the frequency was changed or the master toggle was flipped, we need to update the schedule.
		if ( $freq_changed || $cron_toggled ) {
			AutoPen_Logger::info( 'Automation settings changed. Rescheduling event.' );
			self::reschedule_event( $new_options );
		}
	}
}