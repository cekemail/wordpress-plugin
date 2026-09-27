<?php
/**
 * Removes the options and notice transients the plugin created.
 *
 * Result transients expire on their own after the cache lifetime, so they are
 * left for WordPress to clear.
 *
 * @package CekEmail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Admin_Notices.php';

$cekemail_uninstall_site = static function () {
	delete_option( 'cekemail_settings' );
	delete_option( 'cekemail_recent_checks' );

	foreach ( \CekEmail\Admin_Notices::types() as $cekemail_notice_type ) {
		delete_transient( \CekEmail\Admin_Notices::TRANSIENT_PREFIX . $cekemail_notice_type );
	}
};

if ( is_multisite() ) {
	$cekemail_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $cekemail_site_ids as $cekemail_site_id ) {
		switch_to_blog( (int) $cekemail_site_id );
		$cekemail_uninstall_site();
		restore_current_blog();
	}
} else {
	$cekemail_uninstall_site();
}
