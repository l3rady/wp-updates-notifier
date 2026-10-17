<?php
/**
 * The plugin class autoloader (#157).
 *
 * @package wp-updates-notifier
 */

use WPUN\Autoloader;

/**
 * Class name to file mapping, and the guard that keeps every file in includes named by it.
 */
class Test_Autoloader extends WPUN_Test_Case {

	/**
	 * The includes directory.
	 *
	 * @return string
	 */
	private function includes_dir(): string {
		// The directory the plugin was loaded from, which may be a symlink to this checkout.
		return dirname( ( new ReflectionClass( Autoloader::class ) )->getFileName() ) . '/';
	}

	/**
	 * Classes, abstract classes, interfaces and sub-namespaces map to their files.
	 */
	public function test_maps_class_names_to_files() {
		$dir = $this->includes_dir();

		$this->assertSame( $dir . 'class-settings.php', Autoloader::path_for( 'WPUN\Settings' ) );
		$this->assertSame( $dir . 'abstract-class-channel.php', Autoloader::path_for( 'WPUN\Channel' ) );
		$this->assertSame( $dir . 'interface-config-source.php', Autoloader::path_for( 'WPUN\Config_Source' ) );
		$this->assertSame( $dir . 'admin/class-settings-page.php', Autoloader::path_for( 'WPUN\Admin\Settings_Page' ) );
	}

	/**
	 * Names outside the WPUN namespace are left to other autoloaders.
	 */
	public function test_foreign_names_are_ignored() {
		$this->assertNull( Autoloader::path_for( 'Foo\Bar' ) );
		$this->assertNull( Autoloader::path_for( 'WPUNX\Settings' ) );
		$this->assertNull( Autoloader::path_for( 'Settings' ) );
	}

	/**
	 * A WPUN class with no file is not an error.
	 */
	public function test_missing_class_is_quietly_skipped() {
		$this->assertNull( Autoloader::path_for( 'WPUN\Does_Not_Exist' ) );

		Autoloader::load( 'WPUN\Does_Not_Exist' );

		$this->assertFalse( class_exists( 'WPUN\Does_Not_Exist' ) );
	}

	/**
	 * The plugin registered it.
	 */
	public function test_autoloader_is_registered() {
		$this->assertContains( array( Autoloader::class, 'load' ), spl_autoload_functions() );
	}

	/**
	 * Every file in includes is found under the name it declares.
	 */
	public function test_every_includes_file_is_found_by_its_declared_name() {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->includes_dir(), FilesystemIterator::SKIP_DOTS ) );
		$count = 0;

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

			$this->assertSame( 1, preg_match( '/^namespace\s+([\w\\\\]+);/m', $source, $ns ), $file->getFilename() . ' declares a namespace' );
			$this->assertSame( 1, preg_match( '/^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $source, $decl ), $file->getFilename() . ' declares a class' );

			$this->assertSame(
				$file->getPathname(),
				Autoloader::path_for( $ns[1] . '\\' . $decl[1] ),
				$file->getFilename() . ' is not named after ' . $ns[1] . '\\' . $decl[1]
			);
			++$count;
		}

		$this->assertGreaterThan( 20, $count );
	}
}
