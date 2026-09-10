<?php
/**
 * Admin notices for account level API failures.
 *
 * @package CekEmail
 */

namespace CekEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Records API failures that need the site owner's attention and renders them.
 */
class Admin_Notices {

	/**
	 * Prefix used for the notice transients.
	 */
	const TRANSIENT_PREFIX = 'cekemail_notice_';

	/**
	 * How long a recorded notice keeps showing, in seconds.
	 */
	const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Notice types the plugin knows about.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array( 'unauthorized', 'insufficient_credits', 'forbidden' );
	}

	/**
	 * Hook the notice renderer.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Remember that an API failure happened.
	 *
	 * @param string $type Notice type.
	 * @return void
	 */
	public function record( string $type ): void {
		if ( ! in_array( $type, self::types(), true ) ) {
			return;
		}

		set_transient( self::TRANSIENT_PREFIX . $type, time(), self::TTL );
	}

	/**
	 * Forget a recorded failure.
	 *
	 * @param string $type Notice type.
	 * @return void
	 */
	public function clear( string $type ): void {
		delete_transient( self::TRANSIENT_PREFIX . $type );
	}

	/**
	 * Forget every recorded failure.
	 *
	 * @return void
	 */
	public function clear_all(): void {
		foreach ( self::types() as $type ) {
			$this->clear( $type );
		}
	}

	/**
	 * Render the pending notices.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( self::types() as $type ) {
			if ( false === get_transient( self::TRANSIENT_PREFIX . $type ) ) {
				continue;
			}

			printf(
				'<div class="notice notice-error is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'CekEmail:', 'cekemail-email-validation' ),
				esc_html( $this->message( $type ) ),
				esc_url( Settings::page_url() ),
				esc_html__( 'Open CekEmail settings', 'cekemail-email-validation' )
			);
		}
	}

	/**
	 * Message shown for a notice type.
	 *
	 * @param string $type Notice type.
	 * @return string
	 */
	private function message( string $type ): string {
		switch ( $type ) {
			case 'insufficient_credits':
				return __( 'Your account has run out of credits, so email addresses are no longer being verified.', 'cekemail-email-validation' );
			case 'forbidden':
				return __( 'Your API key is not allowed to verify email addresses from this site. Check its scopes and IP allowlist.', 'cekemail-email-validation' );
			case 'unauthorized':
			default:
				return __( 'Your API key was rejected. Email addresses are no longer being verified.', 'cekemail-email-validation' );
		}
	}
}
