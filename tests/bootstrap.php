<?php
/**
 * PHPUnit bootstrap. The tests run without WordPress, using Brain Monkey.
 *
 * @package CekEmail
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );

define( 'CEKEMAIL_VERSION', '1.0.0' );
define( 'CEKEMAIL_PLUGIN_FILE', dirname( __DIR__ ) . '/cekemail-email-validation.php' );
define( 'CEKEMAIL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/stubs/wp-error.php';
require_once __DIR__ . '/stubs/plugins.php';
