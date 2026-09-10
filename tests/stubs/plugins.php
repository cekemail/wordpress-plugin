<?php
/**
 * Stubs standing in for the third party plugins the integrations detect.
 *
 * @package CekEmail
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	/**
	 * Marker class WooCommerce defines when it is active.
	 */
	class WooCommerce {}
}

if ( ! class_exists( 'WPCF7' ) ) {
	/**
	 * Marker class Contact Form 7 defines when it is active.
	 */
	class WPCF7 {}
}
