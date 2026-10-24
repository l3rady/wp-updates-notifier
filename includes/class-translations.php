<?php
/**
 * Loading the translations bundled with the plugin.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Makes WordPress load the plugin's bundled translations, and fills the gaps of an installed language pack with them.
 *
 * WordPress resolves one languages directory per text domain and locale, and a language pack in
 * `wp-content/languages/plugins` wins over any other. A pack from translate.wordpress.org may be old and partial, so on
 * its own it would hide the complete bundled file. So:
 *
 * - `lang_dir_for_domain` points WordPress at the bundled directory for every locale the plugin ships.
 * - When WordPress is about to load the bundled file, a pack for the same locale is loaded first. WordPress looks a
 *   string up in the files of a domain in the order they were loaded and takes the first hit, so a string in the pack
 *   wins and the bundled file fills every gap.
 *
 * Loading stays just in time: nothing is loaded, and nothing is translated, until a string is first translated.
 *
 * The plugin bundles `.l10n.php` files only, as WordPress 6.5+ prefers them. A site that forces the MO format with the
 * `translation_file_format` filter therefore gets no bundled strings. A pack counts in either format.
 *
 * @internal
 */
final class Translations {
	const DOMAIN = 'wp-updates-notifier';

	/**
	 * The directory with the bundled files, with a trailing slash.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Remember the bundled directory.
	 *
	 * @param string|null $dir Directory of the bundled translation files. Default is the plugin's `languages` directory.
	 */
	public function __construct( ?string $dir = null ) {
		$this->dir = trailingslashit( $dir ?? dirname( SC_WPUN_FILE ) . '/languages' );
	}

	/**
	 * Hook in. Translates nothing.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'lang_dir_for_domain', array( $this, 'lang_dir' ), 10, 3 );
		add_action( 'load_textdomain', array( $this, 'load_pack_first' ), 10, 2 );
	}

	/**
	 * Use the bundled directory for a locale the plugin ships.
	 *
	 * @param string|false $path   The directory WordPress found, or false.
	 * @param string       $domain Text domain.
	 * @param string       $locale Locale.
	 * @return string|false
	 */
	public function lang_dir( $path, $domain, $locale ) {
		$locale = (string) $locale;
		if ( self::DOMAIN === $domain && '' !== $locale && is_file( $this->dir . self::DOMAIN . '-' . $locale . '.l10n.php' ) ) {
			return $this->dir;
		}

		return $path;
	}

	/**
	 * Before WordPress loads the bundled file, load the language pack of the same locale, so its strings come first.
	 *
	 * The pack is loaded through `load_textdomain()`, with its `.mo` path (WordPress swaps in the `.l10n.php` file
	 * itself), so every filter and the file format setting apply to it as to any other file. That call fires this
	 * action again, but for a file outside the bundled directory.
	 *
	 * @param string $domain Text domain.
	 * @param string $mofile Path to the file about to be loaded.
	 * @return void
	 */
	public function load_pack_first( $domain, $mofile ): void {
		if ( self::DOMAIN !== $domain || ! is_string( $mofile ) || trailingslashit( dirname( $mofile ) ) !== $this->dir ) {
			return;
		}

		$locale = substr( basename( $mofile, '.mo' ), strlen( self::DOMAIN ) + 1 );
		$pack   = trailingslashit( WP_LANG_DIR ) . 'plugins/';
		if ( '' === $locale || ! $this->has_pack( $pack . self::DOMAIN . '-' . $locale ) ) {
			return;
		}

		load_textdomain( $domain, $pack . self::DOMAIN . '-' . $locale . '.mo', $locale );
	}

	/**
	 * Whether a language pack is installed, in either format.
	 *
	 * @param string $base Path of the pack's files, without the extension.
	 * @return bool
	 */
	private function has_pack( string $base ): bool {
		return is_file( $base . '.l10n.php' ) || is_file( $base . '.mo' );
	}
}
