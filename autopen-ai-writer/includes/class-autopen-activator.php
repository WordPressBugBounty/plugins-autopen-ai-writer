<?php
/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 * It is responsible for setting up default options and scheduling the initial
 * cron event.
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AutoPen_Activator {

	/**
	 * The main activation method called by the activation hook.
	 *
	 * @since 1.0.0
	 */
	public static function activate(): void {
		self::set_default_options();
		self::schedule_cron_event();
	}

	/**
	 * Sets the default options for the plugin on first activation.
	 *
	 * This ensures the plugin has a valid configuration out of the box. It only
	 * runs if the options key does not already exist in the database, preventing
	 * user settings from being overwritten on re-activation.
	 *
	 * @since 1.0.0
	 */
	private static function set_default_options(): void {
		if ( false === get_option( AUTOPEN_OPTIONS_KEY ) ) {
			update_option( AUTOPEN_OPTIONS_KEY, AutoPen_Utils::get_default_options() );
		}
	}

	/**
	 * Schedules the main cron event if it's not already scheduled.
	 *
	 * This method ensures the automation feature is ready to run based on the
	 * default (or existing) settings.
	 *
	 * @since 1.0.0
	 */
	private static function schedule_cron_event(): void {
		// First, ensure our custom cron schedules are available to WordPress.
		AutoPen_Cron::add_custom_schedules( [] );

		// Schedule the event based on the current settings.
		AutoPen_Cron::reschedule_event();

		AutoPen_Logger::info( 'Plugin activated and cron event scheduled.' );
	}
}