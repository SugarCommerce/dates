<?php
/**
 * Timestamp parsing and formatting.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates;

/**
 * Class Timestamp
 *
 * Reading and writing the timestamps an application stores.
 *
 * **Everything here is UTC.** Storing local times is the single most
 * expensive date mistake available: the value is ambiguous twice a year
 * when clocks go back, unorderable across a daylight-saving boundary,
 * and meaningless once the server moves or the business opens in a
 * second country. Store UTC, convert on the way out.
 *
 * @since 1.0.0
 */
final readonly class Timestamp {

	/**
	 * The format SQL-ish databases use, and the one worth storing.
	 */
	public const SQL = 'Y-m-d H:i:s';

	/**
	 * ISO 8601, for APIs and JSON.
	 */
	public const ISO = 'Y-m-d\TH:i:sP';

	/**
	 * Date only.
	 */
	public const DATE = 'Y-m-d';

	/**
	 * Parse a stored timestamp as UTC.
	 *
	 * Bare date-time strings carry no zone, so a naive parse applies the
	 * server's — which silently shifts every value when the server moves
	 * or its configuration changes. UTC is assumed explicitly unless the
	 * string says otherwise.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Stored value.
	 *
	 * @return \DateTimeImmutable|null Null when unparseable.
	 */
	public static function parse( string $value ): ?\DateTimeImmutable {
		$value = trim( $value );

		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		try {
			// A string with its own offset or zone keeps it; a bare one
			// is read as UTC rather than as server-local.
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return null;
		}

		return $date->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Parse to a Unix timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Stored value.
	 *
	 * @return int|null
	 */
	public static function to_unix( string $value ): ?int {
		return self::parse( $value )?->getTimestamp();
	}

	/**
	 * The current UTC time in storage format.
	 *
	 * @since 1.0.0
	 *
	 * @param Clock|null $clock Source of now.
	 *
	 * @return string
	 */
	public static function now( ?Clock $clock = null ): string {
		return ( $clock ?? new Clock() )->utc()->format( self::SQL );
	}

	/**
	 * Format a stored timestamp for display in a timezone.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value    Stored UTC value.
	 * @param string $format   PHP date format.
	 * @param string $timezone Target timezone.
	 *
	 * @return string Empty when unparseable.
	 */
	public static function format( string $value, string $format = self::SQL, string $timezone = 'UTC' ): string {
		$date = self::parse( $value );

		if ( null === $date ) {
			return '';
		}

		try {
			return $date->setTimezone( new \DateTimeZone( $timezone ) )->format( $format );
		} catch ( \Exception ) {
			return $date->format( $format );
		}
	}

	/**
	 * Convert to ISO 8601, for an API response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Stored UTC value.
	 *
	 * @return string Empty when unparseable.
	 */
	public static function iso( string $value ): string {
		return self::format( $value, self::ISO );
	}

	/**
	 * Whether a stored timestamp is in the past.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $value Stored UTC value.
	 * @param Clock|null $clock Source of now.
	 *
	 * @return bool False when unparseable, so a bad value never reads as
	 *              expired.
	 */
	public static function is_past( string $value, ?Clock $clock = null ): bool {
		$unix = self::to_unix( $value );

		return null !== $unix && $unix < ( $clock ?? new Clock() )->now();
	}

	/**
	 * Whether a stored timestamp is in the future.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $value Stored UTC value.
	 * @param Clock|null $clock Source of now.
	 *
	 * @return bool
	 */
	public static function is_future( string $value, ?Clock $clock = null ): bool {
		$unix = self::to_unix( $value );

		return null !== $unix && $unix > ( $clock ?? new Clock() )->now();
	}
}
