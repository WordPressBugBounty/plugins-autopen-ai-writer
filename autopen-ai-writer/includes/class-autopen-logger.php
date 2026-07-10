<?php
/**
 * A simple logger class for the plugin.
 *
 * This class provides a straightforward way to log informational messages,
 * warnings, and errors. Logs are stored in the WordPress options table
 * and are viewable in the 'Logs' tab of the plugin's admin page.
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AutoPen_Logger {

	/**
	 * The key used to store logs in the wp_options table.
	 * @since 1.0.0
	 */
	private const LOG_OPTION_KEY = 'autopen_recent_logs';

	/**
	 * The maximum number of log entries to store.
	 * @since 1.0.0
	 */
	private const MAX_LOG_ENTRIES = 100;

	/**
	 * Logs an informational message.
	 *
	 * @since 1.0.0
	 * @param string $message The message to log.
	 * @param array  $context Optional context data for debugging.
	 */
	public static function info( string $message, array $context = [] ): void {
		self::log( 'INFO', $message, $context );
	}

	/**
	 * Logs a warning message.
	 *
	 * @since 1.0.0
	 * @param string $message The message to log.
	 * @param array  $context Optional context data for debugging.
	 */
	public static function warning( string $message, array $context = [] ): void {
		self::log( 'WARNING', $message, $context );
	}

	/**
	 * Logs an error message.
	 *
	 * @since 1.0.0
	 * @param string $message The message to log.
	 * @param array  $context Optional context data for debugging.
	 */
	public static function error( string $message, array $context = [] ): void {
		self::log( 'ERROR', $message, $context );
	}

	/**
	 * The core logging method.
	 *
	 * Appends a new log entry to the beginning of the log array and trims
	 * the array to maintain the maximum number of entries.
	 *
	 * @since 1.0.0
	 * @param string $level   The log level (INFO, WARNING, ERROR).
	 * @param string $message The message to log.
	 * @param array  $context Optional context data for debugging.
	 */
	private static function log( string $level, string $message, array $context = [] ): void {
		// Retrieve existing logs or initialize an empty array.
		$logs = get_option( self::LOG_OPTION_KEY, [] );
		
		// Create the new log entry.
		$entry = [
			'timestamp' => current_time( 'mysql' ),
			'level'     => $level,
			'message'   => $message,
		];
		
		// Add the new entry to the beginning of the array.
		array_unshift( $logs, $entry );
		
		// Trim the array to the maximum allowed size.
		if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
			$logs = array_slice( $logs, 0, self::MAX_LOG_ENTRIES );
		}
		
		// Save the updated logs back to the database. 'no' for autoload.
		update_option( self::LOG_OPTION_KEY, $logs, 'no' );
	}

	/**
	 * Retrieves recent log entries from the database.
	 *
	 * @since 1.0.0
	 * @return array The array of log entries.
	 */
	public static function get_recent_logs(): array {
		return get_option( self::LOG_OPTION_KEY, [] );
	}
	
	/**
	 * Clears all stored log entries from the database.
	 *
	 * @since 1.0.0
	 */
	public static function clear_logs(): void {
		delete_option( self::LOG_OPTION_KEY );
		// Add a log entry to record that the logs were cleared.
		self::info( 'Recent logs cleared by user.' );
	}
}