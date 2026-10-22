<?php
/**
 * Human-readable relative times.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates;

/**
 * Class Relative
 *
 * "3 minutes ago", "in 2 weeks".
 *
 * Worth being deliberate about two things. First, relative times stop
 * being useful past a few weeks — "43 weeks ago" is harder to read than
 * a date, so anything beyond the threshold falls back to one. Second,
 * they should always be paired with the absolute value in a `title`
 * attribute: relative time is friendly, but somebody reconciling a
 * payment needs the actual timestamp.
 *
 * @since 1.0.0
 */
final readonly class Relative {

	/**
	 * Seconds per unit, largest first.
	 */
	private const array UNITS = array(
		'year'   => 31536000,
		'month'  => 2592000,
		'week'   => 604800,
		'day'    => 86400,
		'hour'   => 3600,
		'minute' => 60,
	);

	/**
	 * Render a stored timestamp as a relative phrase.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $value    Stored UTC timestamp.
	 * @param Clock|null $clock    Source of now.
	 * @param int        $cutoff   Seconds past which an absolute date is
	 *                             returned instead. Zero disables the
	 *                             fallback.
	 * @param string     $fallback Date format used past the cutoff.
	 *
	 * @return string Empty when unparseable.
	 */
	public static function format(
		string $value,
		?Clock $clock = null,
		int $cutoff = 2592000,
		string $fallback = 'j M Y'
	): string {
		$unix = Timestamp::to_unix( $value );

		if ( null === $unix ) {
			return '';
		}

		$now = ( $clock ?? new Clock() )->now();

		// A stored value at DateTime's extremes ('@-9223372036854775807')
		// makes the subtraction overflow into a float, which phrase()'s
		// int parameter then refuses with a TypeError. Saturate instead.
		$delta = match ( true ) {
			$unix < 0 && $now > PHP_INT_MAX + $unix => PHP_INT_MAX,
			$unix > 0 && $now < PHP_INT_MIN + $unix => -PHP_INT_MAX,
			default                                 => $now - $unix,
		};

		// Past the cutoff a date reads better than a large unit count.
		if ( $cutoff > 0 && abs( $delta ) > $cutoff ) {
			return Timestamp::format( $value, $fallback );
		}

		return self::phrase( $delta );
	}

	/**
	 * Render a second count as a relative phrase.
	 *
	 * @since 1.0.0
	 *
	 * @param int $delta Seconds elapsed. Negative means the future.
	 *
	 * @return string
	 */
	public static function phrase( int $delta ): string {
		$parts = self::parts( $delta );

		if ( null === $parts ) {
			return 'just now';
		}

		$label = $parts['count'] . ' ' . $parts['unit'] . ( 1 === $parts['count'] ? '' : 's' );

		return $parts['future'] ? 'in ' . $label : $label . ' ago';
	}

	/**
	 * The unit and count a second count is described in, without the words.
	 *
	 * For an application that translates. "{count} {unit} ago" cannot be
	 * translated as a template — the unit inflects with the count in most
	 * languages and the word order differs in the rest — so a translated
	 * phrase is chosen per unit and per count, and this is the choice
	 * phrase() makes, so the two never disagree about where "3 weeks"
	 * becomes "1 month".
	 *
	 * @since 1.1.0
	 *
	 * @param int $delta Seconds elapsed. Negative means the future.
	 *
	 * @return array{unit: string, count: int, future: bool}|null Null under
	 *         a minute, which reads as "just now".
	 */
	public static function parts( int $delta ): ?array {
		$absolute = abs( $delta );

		// Anything under a minute is "just now". The threshold has to be
		// 60 rather than 45: the smallest unit below is the minute, so a
		// 45-second delta fell through every unit and came back out of
		// the fallback as "0 minutes ago".
		if ( $absolute < 60 ) {
			return null;
		}

		foreach ( self::UNITS as $unit => $seconds ) {
			if ( $absolute >= $seconds ) {
				return array( 'unit' => $unit, 'count' => (int) floor( $absolute / $seconds ), 'future' => $delta < 0 );
			}
		}

		// Unreachable while the smallest unit is the minute and anything
		// below one is handled above, but a count of zero must never be
		// printable.
		return array( 'unit' => 'minute', 'count' => max( 1, (int) floor( $absolute / 60 ) ), 'future' => $delta < 0 );
	}

	/**
	 * A relative phrase with the absolute value for a tooltip.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $value Stored UTC timestamp.
	 * @param Clock|null $clock Source of now.
	 *
	 * @return array{text: string, title: string} Empty strings when
	 *                                            unparseable.
	 */
	public static function with_title( string $value, ?Clock $clock = null ): array {
		if ( null === Timestamp::to_unix( $value ) ) {
			return array( 'text' => '', 'title' => '' );
		}

		return array(
			'text'  => self::format( $value, $clock ),
			'title' => Timestamp::format( $value, 'j F Y, H:i' ) . ' UTC',
		);
	}
}
