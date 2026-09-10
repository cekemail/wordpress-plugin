<?php
/**
 * Translation completeness tests.
 *
 * @package CekEmail
 */

namespace CekEmail\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Checks the shipped translations cover every translatable string.
 */
class TranslationsTest extends PHPUnitTestCase {

	/**
	 * Directory holding the translation files.
	 */
	const LANGUAGES = CEKEMAIL_PLUGIN_DIR . 'languages/';

	/**
	 * Read the msgid to msgstr pairs out of a PO or POT file.
	 *
	 * Handles the multi line form gettext uses, where a string is written as an
	 * empty first line followed by one quoted chunk per line.
	 *
	 * @param string $path Absolute path of the file.
	 * @return array<string, string> Translations, keyed by msgid, header entry excluded.
	 */
	private function parse( string $path ): array {
		$lines   = file( $path, FILE_IGNORE_NEW_LINES );
		$entries = array();
		$msgid   = null;
		$field   = null;
		$buffer  = array(
			'msgid'  => '',
			'msgstr' => '',
		);

		$flush = static function () use ( &$entries, &$buffer, &$msgid, &$field ) {
			if ( null !== $msgid && '' !== $msgid ) {
				$entries[ $msgid ] = $buffer['msgstr'];
			}

			$msgid  = null;
			$field  = null;
			$buffer = array(
				'msgid'  => '',
				'msgstr' => '',
			);
		};

		foreach ( (array) $lines as $line ) {
			if ( 0 === strpos( $line, 'msgid "' ) ) {
				if ( null !== $field ) {
					$msgid = $buffer['msgid'];
					$flush();
				}

				$field            = 'msgid';
				$buffer['msgid']  = $this->unquote( $line );
				$buffer['msgstr'] = '';
				continue;
			}

			if ( 0 === strpos( $line, 'msgstr "' ) ) {
				$field            = 'msgstr';
				$buffer['msgstr'] = $this->unquote( $line );
				continue;
			}

			if ( null !== $field && 0 === strpos( $line, '"' ) ) {
				$buffer[ $field ] .= $this->unquote( $line );
				continue;
			}
		}

		if ( null !== $field ) {
			$msgid = $buffer['msgid'];
			$flush();
		}

		return $entries;
	}

	/**
	 * Decode one quoted gettext chunk.
	 *
	 * @param string $line Line holding the chunk.
	 * @return string
	 */
	private function unquote( string $line ): string {
		$start = strpos( $line, '"' );
		$chunk = substr( $line, (int) $start );

		return (string) json_decode( $chunk );
	}

	/**
	 * The POT file exists and holds the plugin's strings.
	 *
	 * @return void
	 */
	public function test_the_pot_file_is_generated(): void {
		$pot = $this->parse( self::LANGUAGES . 'cekemail-email-validation.pot' );

		$this->assertNotEmpty( $pot );
		$this->assertArrayHasKey( 'Disposable email addresses are not accepted. Please use a permanent address.', $pot );
	}

	/**
	 * Every string in the POT file has an Indonesian translation.
	 *
	 * @return void
	 */
	public function test_every_string_is_translated_into_indonesian(): void {
		$pot = $this->parse( self::LANGUAGES . 'cekemail-email-validation.pot' );
		$po  = $this->parse( self::LANGUAGES . 'cekemail-email-validation-id_ID.po' );

		$untranslated = array();

		foreach ( array_keys( $pot ) as $msgid ) {
			if ( ! isset( $po[ $msgid ] ) || '' === trim( $po[ $msgid ] ) ) {
				$untranslated[] = $msgid;
			}
		}

		$this->assertSame( array(), $untranslated, 'Untranslated strings in the id_ID catalogue.' );
	}

	/**
	 * The compiled catalogue ships alongside the PO file.
	 *
	 * @return void
	 */
	public function test_the_compiled_catalogue_is_shipped(): void {
		$this->assertFileExists( self::LANGUAGES . 'cekemail-email-validation-id_ID.mo' );
		$this->assertFileExists( self::LANGUAGES . 'cekemail-email-validation-id_ID.l10n.php' );
	}
}
