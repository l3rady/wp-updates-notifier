<?php
/**
 * Rector for `make quality-rector`: a dry run that lists dead code and code quality suggestions for a refactor audit.
 *
 * A dry run, separate from rector.php (the PHP 7.4 syntax upgrades). Stays on PHP 7.4 and the WordPress Coding
 * Standards: Rector's output is a hint to read, not a patch to apply blindly.
 *
 * `make quality-rector` reports everything. `make quality-check` (the gate) sets WPUN_QUALITY_BASELINE and also skips
 * what docker/quality/rector-baseline.php lists, so only new suggestions fail. `make quality-baseline` regenerates it.
 *
 * @package wp-updates-notifier
 */

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\Identical\SimplifyBoolIdenticalTrueRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

// The gate skips the findings that are already known. The report does not, so it stays complete.
$baseline = false === getenv( 'WPUN_QUALITY_BASELINE' ) ? array() : require __DIR__ . '/docker/quality/rector-baseline.php';

return RectorConfig::configure()
	->withPaths(
		array(
			__DIR__ . '/wp-updates-notifier.php',
			__DIR__ . '/includes',
			__DIR__ . '/modules',
			__DIR__ . '/uninstall.php',
		)
	)
	// Nothing newer than the plugin's minimum, whatever PHP runs Rector.
	->withPhpVersion( PhpVersion::PHP_74 )
	// Tabs, as the WordPress Coding Standards require (the sets do not contain LongArrayToShortArrayRector, so array() stays).
	->withIndent( "\t", 1 )
	->withSets( array( SetList::DEAD_CODE, SetList::CODE_QUALITY ) )
	->withSkip(
		array_merge(
			$baseline,
			array(
				// The standards require Yoda conditions (`null === $x`); these rules flip them back or rewrite the comparison.
				FlipTypeControlToUseExclusiveTypeRector::class,
				SimplifyBoolIdenticalTrueRector::class,
				// The standards require @param and @return tags even where the native type says the same.
				RemoveUselessParamTagRector::class,
				RemoveUselessReturnTagRector::class,
				RemoveUselessVarTagRector::class,
			)
		)
	);
