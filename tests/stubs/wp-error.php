<?php
/**
 * Minimal WP_Error stub for the unit tests.
 *
 * @package CekEmail
 */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stub matching the parts of WP_Error the plugin uses.
	 */
	class WP_Error {

		/**
		 * Stored messages, keyed by code.
		 *
		 * @var array<string, string[]>
		 */
		protected $errors = array();

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message );
			}
		}

		/**
		 * Add a message.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @return void
		 */
		public function add( $code, $message = '' ) {
			$this->errors[ $code ][] = $message;
		}

		/**
		 * Every stored code.
		 *
		 * @return string[]
		 */
		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		/**
		 * First stored code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			$codes = $this->get_error_codes();

			return empty( $codes ) ? '' : $codes[0];
		}

		/**
		 * First message for a code.
		 *
		 * @param string $code Error code.
		 * @return string
		 */
		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		/**
		 * Whether anything was stored.
		 *
		 * @return bool
		 */
		public function has_errors() {
			return ! empty( $this->errors );
		}
	}
}
