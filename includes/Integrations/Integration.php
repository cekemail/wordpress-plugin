<?php
/**
 * Integration contract.
 *
 * @package CekEmail
 */

namespace CekEmail\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Contract every form integration implements.
 *
 * Implementations are constructed with the settings repository and the
 * validator, in that order, and are only registered when an API key is saved,
 * `is_available()` returns true and the matching toggle is on.
 */
interface Integration {

	/**
	 * Slug matching the key inside the `integrations` setting.
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Whether the software this integration hooks into is present.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Register the integration's hooks.
	 *
	 * @return void
	 */
	public function register(): void;
}
