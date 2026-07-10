<?php
/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 * Its primary purpose is to clean up scheduled tasks to prevent them from running
 * while the plugin is inactive.
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AutoPen_Deactivator {

	/**
	 * The main deactivation method called by the deactivation hook.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate(): void {
		self::clear_scheduled_cron();
		AutoPen_Logger::info( 'Plugin deactivated and cron event cleared.' );
	}

	/**
	 * Clears the scheduled cron event.
	 *
	 * This is crucial to prevent WordPress from attempting to run a hook
	 * for which the corresponding function no longer exists, which could
	 * lead to errors or unnecessary server load.
	 *
	 * @since 1.0.0
	 */
	private static function clear_scheduled_cron(): void {
		wp_clear_scheduled_hook( AUTOPEN_CRON_HOOK );
	}
}