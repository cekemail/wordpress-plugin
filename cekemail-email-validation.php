<?php
/**
 * Plugin Name: CekEmail Email Validation
 * Plugin URI: https://cekemail.com/
 * Description: Validates email addresses submitted through WordPress forms with the CekEmail API, blocking invalid, undeliverable and disposable addresses before they reach your database.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: CekEmail
 * Author URI: https://cekemail.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cekemail-email-validation
 * Domain Path: /languages
 *
 * @package CekEmail
 */

defined( 'ABSPATH' ) || exit;

define( 'CEKEMAIL_VERSION', '1.0.0' );
define( 'CEKEMAIL_PLUGIN_FILE', __FILE__ );
define( 'CEKEMAIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Autoload plugin classes from the includes directory.
 *
 * Maps the `CekEmail\` namespace onto `includes/`, so `CekEmail\Api_Client`
 * lives in `includes/Api_Client.php`.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function cekemail_autoload( $class_name ) {
	$prefix = 'CekEmail\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
	$path     = CEKEMAIL_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
}

spl_autoload_register( 'cekemail_autoload' );

/**
 * Boot the plugin once WordPress and every other plugin are loaded.
 *
 * @return void
 */
function cekemail_bootstrap() {
	load_plugin_textdomain(
		'cekemail-email-validation',
		false,
		dirname( plugin_basename( CEKEMAIL_PLUGIN_FILE ) ) . '/languages'
	);

	\CekEmail\Plugin::instance()->register();
}

add_action( 'plugins_loaded', 'cekemail_bootstrap' );
