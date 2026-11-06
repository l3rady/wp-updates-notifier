<?php
/**
 * Rector for `make quality-rector`: a dry run that lists dead code and code quality suggestions for a refactor audit.
 *
 * Report-only and separate from rector.php (the PHP 7.4 syntax upgrades). Stays on PHP 7.4 and the WordPress Coding
 * Standards: Rector's output is a hint to read, not a patch to apply blindly.
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
		array(
			// The standards require Yoda conditions (`null === $x`); these rules flip them back or rewrite the comparison.
			FlipTypeControlToUseExclusiveTypeRector::class,
			SimplifyBoolIdenticalTrueRector::class,
			// The standards require @param and @return tags even where the native type says the same.
			RemoveUselessParamTagRector::class,
			RemoveUselessReturnTagRector::class,
			RemoveUselessVarTagRector::class,
		)
	);
