<?php
/**
 * Entry points for the dead code detector (make quality-dead).
 *
 * Marks as used what the plugin's own code does not call, but WordPress, WP-CLI or an add-on does:
 *  - the public API: a public method, property or constant of a class in includes/ unless it, or its class,
 *    has an `@internal` tag. This is how bin/check-public-api.php defines the API, so the two agree.
 *  - the legacy SC_WP_Updates_Notifier facade in wp-updates-notifier.php: not API for add-ons, but kept for
 *    backwards compatibility (and its hooks are registered by name in the old settings code).
 *  - hook callbacks and other callables written as an array: array( $this, 'method' ), array( self::class, 'method' ),
 *    array( __CLASS__, 'method' ), array( Some_Class::class, 'method' ).
 *  - WP-CLI commands: every public method of a class in includes/cli/.
 * Magic methods are handled by the detector's own builtin provider.
 *
 * @package wp-updates-notifier
 */

declare(strict_types=1);

namespace Wpun\Quality;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

final class PublicApiUsageProvider extends ReflectionBasedMemberUsageProvider {

	/**
	 * Callables written as an array.
	 *
	 * @param Node  $node  Any node.
	 * @param Scope $scope Where it is.
	 * @return list<\ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage>
	 */
	public function getUsages( Node $node, Scope $scope ): array {
		$usages = parent::getUsages( $node, $scope );

		if ( ! $node instanceof Array_ || 2 !== count( $node->items ) ) {
			return $usages;
		}
		$target = $node->items[0]->value ?? null;
		$method = $node->items[1]->value ?? null;
		if ( ! $method instanceof String_ ) {
			return $usages;
		}

		$class = null;
		if ( $target instanceof Variable && 'this' === $target->name ) {
			$class = $scope->getClassReflection()?->getName();
		} elseif ( $target instanceof ConstFetch && '__CLASS__' === $target->name->toString() ) {
			$class = $scope->getClassReflection()?->getName();
		} elseif ( $target instanceof ClassConstFetch && $target->name instanceof Node\Identifier && 'class' === $target->name->toString() && $target->class instanceof Node\Name ) {
			$name  = $target->class->toString();
			$class = in_array( $name, array( 'self', 'static' ), true ) ? $scope->getClassReflection()?->getName() : $scope->resolveName( $target->class );
		}
		if ( null === $class ) {
			return $usages;
		}

		$usages[] = new ClassMethodUsage(
			UsageOrigin::createVirtual( $this, VirtualUsageData::withNote( 'Callable written as an array' ) ),
			new ClassMethodRef( $class, $method->value, possibleDescendant: true )
		);
		return $usages;
	}

	protected function shouldMarkMethodAsUsed( ReflectionMethod $method ): ?VirtualUsageData {
		if ( ! $method->isPublic() ) {
			return null;
		}
		if ( $this->isCli( $method->getDeclaringClass() ) ) {
			return VirtualUsageData::withNote( 'WP-CLI command' );
		}
		return $this->isPublicApi( $method->getDeclaringClass(), (string) $method->getDocComment() );
	}

	protected function shouldMarkConstantAsUsed( ReflectionClassConstant $constant ): ?VirtualUsageData {
		if ( ! $constant->isPublic() ) {
			return null;
		}
		return $this->isPublicApi( $constant->getDeclaringClass(), (string) $constant->getDocComment() );
	}

	protected function shouldMarkPropertyAsRead( ReflectionProperty $property ): ?VirtualUsageData {
		return $this->property( $property );
	}

	protected function shouldMarkPropertyAsWritten( ReflectionProperty $property ): ?VirtualUsageData {
		return $this->property( $property );
	}

	private function property( ReflectionProperty $property ): ?VirtualUsageData {
		if ( ! $property->isPublic() ) {
			return null;
		}
		return $this->isPublicApi( $property->getDeclaringClass(), (string) $property->getDocComment() );
	}

	private function isCli( \ReflectionClass $class ): bool {
		return str_contains( (string) $class->getFileName(), '/includes/cli/' );
	}

	private function isPublicApi( \ReflectionClass $class, string $member_doc ): ?VirtualUsageData {
		$file = (string) $class->getFileName();
		if ( ! str_contains( $file, '/app/includes/' ) && '/app/wp-updates-notifier.php' !== $file ) {
			return null;
		}
		if ( str_contains( $member_doc, '@internal' ) || str_contains( (string) $class->getDocComment(), '@internal' ) ) {
			return null;
		}
		return VirtualUsageData::withNote( 'Public API: no @internal tag' );
	}
}
