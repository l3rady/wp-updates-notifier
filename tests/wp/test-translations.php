<?php
/**
 * Loading the bundled translations next to a language pack (#147).
 *
 * @package wp-updates-notifier
 */

/**
 * The bundled files fill the gaps of an installed language pack: a string in the pack wins, a string missing from it
 * comes from the bundled file.
 *
 * The fixtures are made during the test, in a temporary directory (the bundled files) and in `WP_LANG_DIR/plugins`
 * (the language pack), for a locale that does not exist. Both are built in the PHP and the MO format.
 */
class Test_Translations extends WPUN_Test_Case {

	/**
	 * Text domain.
	 */
	const DOMAIN = 'wp-updates-notifier';

	/**
	 * A locale only the fixtures have.
	 */
	const LOCALE = 'xx_XX';

	/**
	 * Directory of the fake bundled files.
	 *
	 * @var string
	 */
	private $bundled;

	/**
	 * Files this test wrote into the language pack directory.
	 *
	 * @var string[]
	 */
	private $pack_files = array();

	/**
	 * The registry WordPress had before the test.
	 *
	 * @var WP_Textdomain_Registry
	 */
	private $registry;

	/**
	 * Start with nothing loaded and a registry that has cached nothing.
	 */
	public function set_up() {
		parent::set_up();

		$this->bundled    = trailingslashit( get_temp_dir() ) . 'wpun-bundled-' . wp_generate_password( 8, false ) . '/';
		$this->pack_files = array();
		wp_mkdir_p( $this->bundled );

		$this->registry                    = $GLOBALS['wp_textdomain_registry'];
		$GLOBALS['wp_textdomain_registry'] = new WP_Textdomain_Registry();
		$this->reset_loaded();

		( new \WPUN\Translations( $this->bundled ) )->register();
	}

	/**
	 * Remove the fixtures and restore what the test changed.
	 */
	public function tear_down() {
		foreach ( glob( $this->bundled . '*' ) as $file ) {
			wp_delete_file( $file );
		}
		rmdir( $this->bundled ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A temporary directory the test made.
		foreach ( $this->pack_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		wp_cache_delete( md5( WP_LANG_DIR . '/plugins/' ), 'translation_files' );

		$this->reset_loaded();
		$GLOBALS['wp_textdomain_registry'] = $this->registry;
		WP_Translation_Controller::get_instance()->set_locale( 'en_US' );

		parent::tear_down();
	}

	/**
	 * Forget every loaded file of the domain, in the old and the new translations API.
	 */
	private function reset_loaded() {
		unload_textdomain( self::DOMAIN, true );
		WP_Translation_Controller::get_instance()->unload_textdomain( self::DOMAIN );
		unset( $GLOBALS['l10n'][ self::DOMAIN ], $GLOBALS['l10n_unloaded'][ self::DOMAIN ] );
	}

	/**
	 * Write a translation file pair (MO and PHP) for the domain.
	 *
	 * @param string                $dir      Directory, with a trailing slash.
	 * @param string                $locale   Locale.
	 * @param array<string, string> $messages Original => translation.
	 * @return string[] The files written.
	 */
	private function write_files( $dir, $locale, array $messages ) {
		$base = $dir . self::DOMAIN . '-' . $locale;

		$mo = new MO();
		foreach ( $messages as $original => $translation ) {
			$mo->add_entry(
				new Translation_Entry(
					array(
						'singular'     => $original,
						'translations' => array( $translation ),
					)
				)
			);
		}
		$mo->export_to_file( $base . '.mo' );

		file_put_contents( $base . '.l10n.php', '<?php return ' . var_export( array( 'messages' => $messages ), true ) . ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return array( $base . '.mo', $base . '.l10n.php' );
	}

	/**
	 * Install a fake language pack.
	 *
	 * @param string                $locale   Locale.
	 * @param array<string, string> $messages Original => translation.
	 */
	private function install_pack( $locale, array $messages ) {
		wp_mkdir_p( WP_LANG_DIR . '/plugins' );
		$this->pack_files = array_merge( $this->pack_files, $this->write_files( trailingslashit( WP_LANG_DIR ) . 'plugins/', $locale, $messages ) );
		wp_cache_delete( md5( WP_LANG_DIR . '/plugins/' ), 'translation_files' );
	}

	/**
	 * Run the tests for both file formats.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function formats() {
		return array(
			'php' => array( 'php' ),
			'mo'  => array( 'mo' ),
		);
	}

	/**
	 * Use a locale and a file format.
	 *
	 * @param string $locale Locale.
	 * @param string $format php or mo.
	 */
	private function use_locale( $locale, $format ) {
		add_filter(
			'locale',
			static function () use ( $locale ) {
				return $locale;
			}
		);
		add_filter(
			'translation_file_format',
			static function () use ( $format ) {
				return $format;
			}
		);
	}

	/**
	 * Without a pack, the bundled file translates.
	 *
	 * @dataProvider formats
	 *
	 * @param string $format File format.
	 */
	public function test_bundled_file_translates_without_a_pack( $format ) {
		$this->write_files(
			$this->bundled,
			self::LOCALE,
			array(
				'Alpha' => 'Alpha (bundled)',
				'Beta'  => 'Beta (bundled)',
			)
		);
		$this->use_locale( self::LOCALE, $format );

		$this->assertSame( 'Alpha (bundled)', __( 'Alpha', 'wp-updates-notifier' ) );
		$this->assertSame( 'Beta (bundled)', __( 'Beta', 'wp-updates-notifier' ) );
		$this->assertSame( 'Gamma', __( 'Gamma', 'wp-updates-notifier' ), 'A string neither file has stays as it is.' );
	}

	/**
	 * With a pack, its strings win and the bundled file fills the gaps.
	 *
	 * @dataProvider formats
	 *
	 * @param string $format File format.
	 */
	public function test_pack_wins_and_bundled_file_fills_the_gaps( $format ) {
		$this->write_files(
			$this->bundled,
			self::LOCALE,
			array(
				'Alpha' => 'Alpha (bundled)',
				'Beta'  => 'Beta (bundled)',
			)
		);
		$this->install_pack( self::LOCALE, array( 'Alpha' => 'Alpha (pack)' ) );
		$this->use_locale( self::LOCALE, $format );

		$this->assertSame( 'Alpha (pack)', __( 'Alpha', 'wp-updates-notifier' ) );
		$this->assertSame( 'Beta (bundled)', __( 'Beta', 'wp-updates-notifier' ) );
	}

	/**
	 * A locale the plugin does not bundle is left to WordPress: the pack alone translates.
	 */
	public function test_locale_without_a_bundled_file_is_unchanged() {
		$this->write_files( $this->bundled, 'yy_YY', array( 'Alpha' => 'Alpha (bundled)' ) );
		$this->install_pack( self::LOCALE, array( 'Alpha' => 'Alpha (pack)' ) );
		$this->use_locale( self::LOCALE, 'php' );

		$this->assertSame( 'Alpha (pack)', __( 'Alpha', 'wp-updates-notifier' ) );
		$this->assertSame( 'Beta', __( 'Beta', 'wp-updates-notifier' ) );
	}

	/**
	 * Neither a pack nor a bundled file: English, and no error.
	 */
	public function test_locale_without_any_file_is_english() {
		$this->use_locale( self::LOCALE, 'php' );

		$this->assertSame( 'Alpha', __( 'Alpha', 'wp-updates-notifier' ) );
	}

	/**
	 * The directory filter only answers for the plugin's domain and the locales it bundles.
	 */
	public function test_lang_dir_filter_is_limited_to_the_domain_and_its_locales() {
		$this->write_files( $this->bundled, self::LOCALE, array( 'Alpha' => 'Alpha (bundled)' ) );

		$this->assertSame( $this->bundled, apply_filters( 'lang_dir_for_domain', false, self::DOMAIN, self::LOCALE ) );
		$this->assertFalse( apply_filters( 'lang_dir_for_domain', false, self::DOMAIN, 'yy_YY' ) );
		$this->assertSame( '/other/', apply_filters( 'lang_dir_for_domain', '/other/', 'another-domain', self::LOCALE ) );
	}

	/**
	 * Switching locale, as WordPress does to email a user in their own language, loads that locale's files, pack first.
	 */
	public function test_switching_locale_loads_the_files_of_that_locale() {
		// de_DE is installed in the test suite's WP_LANG_DIR, so WordPress lets us switch to it.
		$this->write_files(
			$this->bundled,
			'de_DE',
			array(
				'Alpha' => 'Alpha (bundled)',
				'Beta'  => 'Beta (bundled)',
			)
		);
		$this->install_pack( 'de_DE', array( 'Alpha' => 'Alpha (pack)' ) );

		$this->assertSame( 'Alpha', __( 'Alpha', 'wp-updates-notifier' ) );

		$this->assertTrue( switch_to_locale( 'de_DE' ) );
		$this->assertSame( 'Alpha (pack)', __( 'Alpha', 'wp-updates-notifier' ) );
		$this->assertSame( 'Beta (bundled)', __( 'Beta', 'wp-updates-notifier' ) );

		restore_previous_locale();
		$this->assertSame( 'Alpha', __( 'Alpha', 'wp-updates-notifier' ) );
		$this->assertSame( 'Beta', __( 'Beta', 'wp-updates-notifier' ) );
	}

	/**
	 * Every locale the plugin ships loads from its languages/ directory.
	 *
	 * @dataProvider formats
	 *
	 * @param string $format File format.
	 */
	public function test_shipped_locales_translate( $format ) {
		( new \WPUN\Translations() )->register();
		$files = glob( dirname( SC_WPUN_FILE ) . '/languages/' . self::DOMAIN . '-*.po' );
		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$locale = substr( basename( $file, '.po' ), strlen( self::DOMAIN ) + 1 );
			$this->reset_loaded();
			$this->use_locale( $locale, $format );

			$this->assertNotSame( 'Channels', __( 'Channels', 'wp-updates-notifier' ), $locale );
		}
		$this->reset_loaded();
		$this->use_locale( 'de_DE', $format );
		$this->assertSame( 'Kanäle', __( 'Channels', 'wp-updates-notifier' ) );
	}

	/**
	 * The plugin does not call load_plugin_textdomain(): WordPress finds the files by itself.
	 */
	public function test_plugin_does_not_set_a_custom_text_domain_path() {
		$registry = new ReflectionProperty( WP_Textdomain_Registry::class, 'custom_paths' );
		$registry->setAccessible( true );

		$this->assertArrayNotHasKey( self::DOMAIN, $registry->getValue( $this->registry ) );
	}
}
