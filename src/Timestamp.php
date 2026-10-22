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
	public const string SQL = 'Y-m-d H:i:s';

	/**
	 * ISO 8601, for APIs and JSON.
	 */
	public const string ISO = 'Y-m-d\TH:i:sP';

	/**
	 * Date only.
	 */
	public const string DATE = 'Y-m-d';

	/**
	 * Parse a stored timestamp as UTC.
	 *
	 * Bare date-time strings carry no zone, so a naive parse applies the
	 * server's — which silently shifts every value when the server moves
	 * or its configuration changes. UTC is assumed explicitly unless the
	 * string says otherwise.
	 *
	 * MySQL's zero date, in any of its spellings, means "no date" and is
	 * null — PHP reads `0000-00-00` as 30 November in year -1, which made
	 * a "never expires" column read as long expired.
	 *
	 * Lenient by design: anything `DateTimeImmutable` accepts is read,
	 * relative phrases such as `tomorrow` included. For a date typed by a
	 * person, use the strict {@see self::from_date()}.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Stored value.
	 *
	 * @return \DateTimeImmutable|null Null when unparseable.
	 */
	public static function parse( string $value ): ?\DateTimeImmutable {
		$value = trim( $value );

		if ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
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

	/* ─── Typed dates ───────────────────────────────────────────────── */

	/**
	 * Midnight at the start of a typed `Y-m-d` date, as Unix time.
	 *
	 * Strict, because a date typed into a form or a metadata field by hand
	 * is exactly where a lenient parse does damage. `DateTimeImmutable`
	 * reads `2026-02-30` as 2 March and "next friday" as whatever Friday
	 * comes next, and neither is an error a person would see — the value
	 * is quietly a different day. So the parse is round-tripped: a date
	 * that does not print back as what was typed is no date at all.
	 *
	 * @since 1.1.0
	 *
	 * @param string $value    A date as `YYYY-MM-DD`. Surrounding space is
	 *                         ignored.
	 * @param string $timezone The zone whose midnight is meant. An unknown
	 *                         zone is read as UTC, as format() does.
	 *
	 * @return int|null Null when the value is not a real calendar date.
	 */
	public static function from_date( string $value, string $timezone = 'UTC' ): ?int {
		return self::day( $value, $timezone )?->getTimestamp();
	}

	/**
	 * The last second of a typed `Y-m-d` date, as Unix time.
	 *
	 * For an inclusive "until 31 March": a deadline stored as midnight at
	 * the start of the day ends a day early. Computed as the next midnight
	 * less one second rather than as start + 86399, because a day in a
	 * zone with daylight saving is 23 or 25 hours long twice a year.
	 *
	 * @since 1.1.0
	 *
	 * @param string $value    A date as `YYYY-MM-DD`.
	 * @param string $timezone The zone whose day is meant.
	 *
	 * @return int|null Null when the value is not a real calendar date.
	 */
	public static function end_of_day( string $value, string $timezone = 'UTC' ): ?int {
		$day = self::day( $value, $timezone );

		return null === $day ? null : $day->modify( '+1 day' )->getTimestamp() - 1;
	}

	/**
	 * A date written the way a language writes one: "5. Oktober 2026".
	 *
	 * The long date style, without a time. A translated page with
	 * "5 October 2026" in the middle of it still reads as an English page,
	 * and month names are not something to keep a table of.
	 *
	 * Needs ext-intl. Without it — or for a locale ICU cannot format —
	 * the fallback format is used, which defaults to ISO 8601 because
	 * every reader can parse that, whatever language they read.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $locale    Locale tag, `pt-BR` or `pt_BR`.
	 * @param string $timezone  Zone to show the date in.
	 * @param string $fallback  PHP date format used without intl.
	 *
	 * @return string
	 */
	public static function format_localized( int $timestamp, string $locale, string $timezone = 'UTC', string $fallback = self::DATE ): string {
		$zone = self::zone( $timezone );

		if ( class_exists( \IntlDateFormatter::class ) ) {
			try {
				$formatter = new \IntlDateFormatter(
					str_replace( '-', '_', trim( $locale ) ),
					\IntlDateFormatter::LONG,
					\IntlDateFormatter::NONE,
					$zone->getName()
				);
				$formatted = $formatter->format( $timestamp );

				if ( false !== $formatted && '' !== $formatted ) {
					return $formatted;
				}
			} catch ( \Throwable ) {
				// An unusable locale falls through to the plain format.
			}
		}

		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone )->format( $fallback );
	}

	/**
	 * A typed date as midnight in a zone, or null.
	 *
	 * @since 1.1.0
	 *
	 * @param string $value    `YYYY-MM-DD`.
	 * @param string $timezone Zone name.
	 *
	 * @return \DateTimeImmutable|null
	 */
	private static function day( string $value, string $timezone ): ?\DateTimeImmutable {
		$value = trim( $value );
		$date  = \DateTimeImmutable::createFromFormat( '!' . self::DATE, $value, self::zone( $timezone ) );

		return false !== $date && $date->format( self::DATE ) === $value ? $date : null;
	}

	/**
	 * A timezone by name, UTC when the name is not one.
	 *
	 * @since 1.1.0
	 *
	 * @param string $timezone Zone name.
	 *
	 * @return \DateTimeZone
	 */
	private static function zone( string $timezone ): \DateTimeZone {
		try {
			return new \DateTimeZone( $timezone );
		} catch ( \Exception ) {
			return new \DateTimeZone( 'UTC' );
		}
	}
}
