<?php
/**
 * Removes every option and transient the plugin created.
 *
 * @package CekEmail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'cekemail_settings' );
delete_option( 'cekemail_recent_checks' );

global $wpdb;

$cekemail_transient = $wpdb->esc_like( '_transient_cekemail_' ) . '%';
$cekemail_timeout   = $wpdb->esc_like( '_transient_timeout_cekemail_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$cekemail_transient,
		$cekemail_timeout
	)
);

wp_cache_flush();
