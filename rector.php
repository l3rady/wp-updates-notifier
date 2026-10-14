<?php
/**
 * Rector: PHP 7.4 syntax upgrades only.
 *
 * `make rector` shows what is left to upgrade (changes nothing); `make rector-fix` applies it.
 * Run `make fix` and `make lint` afterwards: Rector's output must still pass the WordPress
 * Coding Standards (tabs, `array()`).
 *
 * @package wp-updates-notifier
 */

use Rector\Config\RectorConfig;
use Rector\Php54\Rector\Array_\LongArrayToShortArrayRector;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
	->withPaths(
		array(
			__DIR__ . '/class-sc-wp-updates-notifier.php',
			__DIR__ . '/includes',
			__DIR__ . '/uninstall.php',
		)
	)
	->withSets( array( LevelSetList::UP_TO_PHP_74 ) )
	// WordPress Coding Standards require array(), not [].
	->withSkip( array( LongArrayToShortArrayRector::class ) );
