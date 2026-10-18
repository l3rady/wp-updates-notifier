<?php
/**
 * The version constraint matcher.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Checks a version against a constraint written in Composer's syntax.
 *
 * Dependency-free and built on version_compare(). Add-ons use it through
 * sc_wpun_api_satisfies(), and can call it directly to check other versions.
 *
 * A constraint is one or more ranges separated by `||` (OR). A range is one or more
 * conditions separated by spaces or commas (AND). A condition is one of:
 *
 * | Condition                                  | Meaning                           |
 * | ------------------------------------------ | --------------------------------- |
 * | `1.2.0`, `=1.2.0`                          | exactly                           |
 * | `>=1.0`, `>1.0`, `<2.0`, `<=1.4`, `!=1.3.0` | comparison                        |
 * | `^1.2`                                     | `>=1.2.0 <2.0.0`                  |
 * | `~1.2`                                     | `>=1.2.0 <2.0.0`                  |
 * | `~1.2.3`                                   | `>=1.2.3 <1.3.0`                  |
 * | `1.2.*`                                    | `>=1.2.0 <1.3.0`                  |
 * | `>=1.0 <1.4`, `>=1.0, <1.4`                | both (AND)                        |
 * | `^1.0 || ^2.0`                             | either (OR)                       |
 *
 * For `^0.x` Composer's rules apply: `^0.3` is `>=0.3.0 <0.4.0` and `^0.0.3` is
 * `>=0.0.3 <0.0.4`. Missing minor and patch parts count as 0, and white space is
 * allowed between an operator and its version (`>= 1.0`). Nothing else is valid: not
 * Ruby's `~>`, hyphen ranges, a bare `*`, a `v` prefix or a pre-release suffix such as
 * `-beta`. The API version never has a suffix.
 *
 * An invalid constraint never matches, and satisfies() reports it with _doing_it_wrong()
 * once per constraint string per request. Use is_valid() to check one without a notice.
 *
 * @since 2.0.0
 */
final class Version_Constraint {

	/**
	 * Constraint strings already reported as invalid in this request.
	 *
	 * @var array<string, true>
	 */
	private static $reported = array();

	/**
	 * Whether a version satisfies a constraint.
	 *
	 * @since 2.0.0
	 *
	 * @param string $version    A version of one to three numeric parts, such as "1.2.0" or "1.2".
	 * @param string $constraint A constraint in Composer syntax, such as "^1.0" or ">=1.0 <2.0".
	 *
	 * @return bool False when the constraint or the version is invalid.
	 */
	public static function satisfies( string $version, string $constraint ): bool {
		$normalised = self::normalise_version( $version );

		if ( null === $normalised ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: the version. */
					esc_html__( 'The version "%s" could not be read. Use one to three numbers, such as "1.2.0".', 'wp-updates-notifier' ),
					esc_html( $version )
				),
				'2.0.0'
			);

			return false;
		}

		$ranges = self::parse( $constraint );

		if ( null === $ranges ) {
			if ( ! isset( self::$reported[ $constraint ] ) ) {
				self::$reported[ $constraint ] = true;

				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: the version constraint. */
						esc_html__( 'The version constraint "%s" could not be read. Use Composer syntax, such as "^1.0" or ">=1.0 <2.0".', 'wp-updates-notifier' ),
						esc_html( $constraint )
					),
					'2.0.0'
				);
			}

			return false;
		}

		foreach ( $ranges as $conditions ) {
			foreach ( $conditions as $condition ) {
				if ( ! version_compare( $normalised, $condition[1], $condition[0] ) ) {
					continue 2;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Whether a constraint can be read. Never triggers a notice.
	 *
	 * @since 2.0.0
	 *
	 * @param string $constraint A constraint in Composer syntax.
	 *
	 * @return bool
	 */
	public static function is_valid( string $constraint ): bool {
		return null !== self::parse( $constraint );
	}

	/**
	 * A version as "X.Y.Z", or null if it is not one to three numeric parts.
	 *
	 * @param string $version The version.
	 *
	 * @return string|null
	 */
	private static function normalise_version( string $version ): ?string {
		if ( 1 !== preg_match( '/^\d+(?:\.\d+){0,2}$/D', $version ) ) {
			return null;
		}

		return implode( '.', self::parts( $version ) );
	}

	/**
	 * The three numeric parts of a version, missing ones as 0.
	 *
	 * @param string $version A version of one to three numeric parts.
	 *
	 * @return int[]
	 */
	private static function parts( string $version ): array {
		$parts = array_map( 'intval', explode( '.', $version ) );

		return array_pad( $parts, 3, 0 );
	}

	/**
	 * Read a constraint into ranges of conditions.
	 *
	 * @param string $constraint A constraint in Composer syntax.
	 *
	 * @return array<int, array<int, array{0: string, 1: string}>>|null Ranges (OR) of conditions
	 *         (AND), each an operator for version_compare() and an "X.Y.Z" version. Null if invalid.
	 */
	private static function parse( string $constraint ): ?array {
		$ranges = array();

		foreach ( self::split( '/\s*\|\|\s*/', trim( $constraint ) ) as $range ) {
			// Join an operator to its version, so ">= 1.0" is one condition.
			$range      = (string) preg_replace( '/(>=|<=|!=|[<>=^~])\s+/', '$1', $range );
			$conditions = array();

			foreach ( self::split( '/\s*,\s*|\s+/', $range ) as $atom ) {
				$expanded = self::expand( $atom );

				if ( null === $expanded ) {
					return null;
				}

				$conditions = array_merge( $conditions, $expanded );
			}

			$ranges[] = $conditions;
		}

		return $ranges;
	}

	/**
	 * Split a string, keeping empty pieces so they can be rejected.
	 *
	 * @param string $pattern The separator pattern.
	 * @param string $subject The string.
	 *
	 * @return string[]
	 */
	private static function split( string $pattern, string $subject ): array {
		$pieces = preg_split( $pattern, $subject );

		return false === $pieces ? array( '' ) : $pieces;
	}

	/**
	 * Turn one condition into comparisons.
	 *
	 * @param string $atom A condition such as "^1.2", "~1.2.3", "1.*" or ">=1.0".
	 *
	 * @return array<int, array{0: string, 1: string}>|null Null if the condition is invalid.
	 */
	private static function expand( string $atom ): ?array {
		if ( 1 === preg_match( '/^(\d+)(?:\.(\d+))?\.\*$/D', $atom, $match ) ) {
			$major = (int) $match[1];

			if ( isset( $match[2] ) ) {
				$minor = (int) $match[2];

				return self::between( "$major.$minor.0", $major . '.' . ( $minor + 1 ) . '.0' );
			}

			return self::between( "$major.0.0", ( $major + 1 ) . '.0.0' );
		}

		if ( 1 !== preg_match( '/^(>=|<=|!=|[<>=^~])?(\d+(?:\.\d+){0,2})$/D', $atom, $match ) ) {
			return null;
		}

		$operator                = $match[1];
		$count                   = substr_count( $match[2], '.' ) + 1;
		[$major, $minor, $patch] = self::parts( $match[2] );
		$version                 = "$major.$minor.$patch";

		switch ( $operator ) {
			case '^':
				if ( $major > 0 || 1 === $count ) {
					$upper = ( $major + 1 ) . '.0.0';
				} elseif ( $minor > 0 || 2 === $count ) {
					$upper = '0.' . ( $minor + 1 ) . '.0';
				} else {
					$upper = '0.0.' . ( $patch + 1 );
				}

				return self::between( $version, $upper );

			case '~':
				$upper = $count < 3 ? ( $major + 1 ) . '.0.0' : "$major." . ( $minor + 1 ) . '.0';

				return self::between( $version, $upper );

			case '':
			case '=':
				return array( array( '==', $version ) );

			default:
				return array( array( $operator, $version ) );
		}
	}

	/**
	 * The conditions for at least one version and below another.
	 *
	 * @param string $lower The lowest version allowed.
	 * @param string $upper The lowest version not allowed.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function between( string $lower, string $upper ): array {
		return array(
			array( '>=', $lower ),
			array( '<', $upper ),
		);
	}
}
