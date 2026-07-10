<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * This script is executed when a user clicks the "Delete" link for the plugin
 * in the WordPress admin area. It's responsible for cleaning up all data
 * created by the plugin to ensure the user's database is left in a clean state.
 *
 * @package AutoPen
 * @since 1.0.0
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Define constants again as this file is loaded independently of the main plugin file.
define( 'AUTOPEN_OPTIONS_KEY', 'autopen_options' );
define( 'AUTOPEN_CRON_HOOK', 'autopen_cron_event' );

// 1. Delete plugin options from the wp_options table.
delete_option( AUTOPEN_OPTIONS_KEY );
delete_option( 'autopen_recent_logs' ); // Delete the logs option.

// 2. Clear any scheduled cron events.
wp_clear_scheduled_hook( AUTOPEN_CRON_HOOK );

// 3. Clean up post meta from generated posts.
// We are not deleting the posts themselves, only the meta fields this plugin added.
global $wpdb;
$meta_keys = [
    '_autopen_generated',
    '_autopen_generation_data',
    '_autopen_parent_post',
];

if ( ! empty( $meta_keys ) ) {
    // Create a comma-separated string of placeholders ('%s, %s, %s').
    $placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

    // Build the SQL query with placeholders.
    // We need to use sprintf to build the query template first, then prepare it.
    $sql = sprintf(
        "DELETE FROM %s WHERE meta_key IN ( %s )",
        $wpdb->postmeta,
        $placeholders
    );

    // Prepare the query with actual values.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is built safely above with placeholders.
    $query = $wpdb->prepare( $sql, $meta_keys );

    // Execute the query.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query( $query );
}

// Note: We intentionally do not delete the generated posts themselves,
// as this is user-owned content and deleting it would be destructive.