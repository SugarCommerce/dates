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
	 * string says otherwise -- whatever `date_default_timezone_set()` was
	 * last called with, which is the difference from `strtotime()`.
	 *
	 * The zero date, in any of its spellings (`0000-00-00`, with a time,
	 * with fractions of a second), means "no date" and is null. PHP reads
	 * `0000-00-00` as 30 November of the year minus one, which made a
	 * "never expires" column read as long expired.
	 *
	 * Lenient by design: anything `DateTimeImmutable` accepts is read,
	 * relative phrases such as `tomorrow` included. For a date typed by a
	 * person, use the strict {@see self::from_date()}.
	 *
	 * A string only. A Unix timestamp is an integer and goes through
	 * {@see self::at()} or {@see self::to_sql()}; digits in a string are
	 * read as a date (`'20260715'`) or not at all.
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
	 * Strict, because a date typed into a form by hand is exactly where a
	 * lenient parse does damage. `DateTimeImmutable` reads `2026-02-30` as
	 * 2 March and "next friday" as whatever Friday comes next, and neither
	 * is an error a person would see -- the value is quietly a different
	 * day. So the parse is round-tripped: a date that does not print back
	 * as what was typed is no date at all.
	 *
	 *   Timestamp::from_date( '2026-07-15' );                  // 1784073600
	 *   Timestamp::from_date( '2026-07-15', 'Europe/Dublin' ); // an hour earlier
	 *   Timestamp::from_date( '2026-02-30' );                  // null
	 *
	 * @since 1.0.0
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
	 * less one second and not as start + 86399, because a day in a zone
	 * with daylight saving is 23 or 25 hours long twice a year.
	 *
	 * @since 1.0.0
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
	 * A typed `Y-m-d` date as the UTC datetime to store, in one step.
	 *
	 * What a date input's value becomes on its way into a datetime column:
	 * the first second of the day, or -- for an inclusive "until the 5th"
	 * -- the last. Strict as {@see self::from_date()} is, so anything that
	 * is not a real day, or not a string or number at all, is `$empty`.
	 *
	 *   Timestamp::date_to_sql( '2026-07-15' );          // '2026-07-15 00:00:00'
	 *   Timestamp::date_to_sql( '2026-07-15', 'end' );   // '2026-07-15 23:59:59'
	 *   Timestamp::date_to_sql( '2026-02-30' );          // '0000-00-00 00:00:00'
	 *   Timestamp::date_to_sql( $typed, 'end', '', 'Europe/Dublin' );
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $date     What was typed or submitted.
	 * @param string $edge     `start` for the first second of the day, `end`
	 *                         for the last. Anything else is `start`.
	 * @param string $empty    What to answer for no date. The zero date by
	 *                         default, which is what a datetime column that
	 *                         cannot be null holds for one.
	 * @param string $timezone The zone whose day is meant.
	 *
	 * @return string `Y-m-d H:i:s` in UTC, or `$empty`.
	 */
	public static function date_to_sql( mixed $date, string $edge = 'start', string $empty = self::ZERO, string $timezone = 'UTC' ): string {
		$date = is_scalar( $date ) ? (string) $date : '';
		$unix = 'end' === $edge ? self::end_of_day( $date, $timezone ) : self::from_date( $date, $timezone );

		return null === $unix ? $empty : self::at( $unix );
	}

	/**
	 * A date written the way a language writes one: "5. Oktober 2026".
	 *
	 * The long date style, without a time, from ICU. For a WordPress
	 * screen {@see Site::format()} is the one to use -- it follows the
	 * site's own format and translations; this is for a locale that is not
	 * the site's, such as a buyer's.
	 *
	 * Needs ext-intl. Without it -- or for a locale ICU cannot format --
	 * the fallback format is used, which defaults to ISO 8601 because
	 * every reader can parse that, whatever language they read.
	 *
	 * @since 1.0.0
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
				unset( $formatter );
			}
		}

		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone )->format( $fallback );
	}

	/**
	 * A typed date as midnight in a zone, or null.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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

	/**
	 * The zero date: what MySQL and WordPress write for "no date".
	 *
	 * @since 1.0.0
	 */
	public const ZERO = '0000-00-00 00:00:00';

	/**
	 * A Unix timestamp, formatted.
	 *
	 * For the timestamps code has as integers -- `time() + $ttl`, a token's
	 * `exp`, what an API sent -- where format() would want a string first.
	 *
	 *   Timestamp::at( time() + 3600 );                          // '2026-08-01 14:00:00'
	 *   Timestamp::at( $expires, 'j M Y H:i T', 'Europe/Dublin' );
	 *
	 * @since 1.0.0
	 *
	 * @param int    $unix     Seconds since the epoch.
	 * @param string $format   PHP date format.
	 * @param string $timezone Target timezone; an unknown one falls back to UTC.
	 *
	 * @return string
	 */
	public static function at( int $unix, string $format = self::SQL, string $timezone = 'UTC' ): string {
		$date = new \DateTimeImmutable( '@' . $unix );

		try {
			return $date->setTimezone( new \DateTimeZone( $timezone ) )->format( $format );
		} catch ( \Exception ) {
			return $date->format( $format );
		}
	}

	/**
	 * Whether a value holds no date.
	 *
	 * Null, an empty string and the zero date -- with or without its time,
	 * because a date column's is `0000-00-00` -- are all "no date". The
	 * zero date is the one that catches people: it is a non-empty string,
	 * so `empty()` says there is a date, and strtotime() turns it into
	 * November of the year minus one.
	 *
	 * By type: a string is a stored or typed date, and holds one unless it
	 * is blank or the zero date (whether it can be READ is parse()'s
	 * question -- `'banana'` is not empty, it is wrong). An integer is a
	 * Unix timestamp, and nought or less is no date: nothing this is for
	 * happened in 1970. A DateTime always holds a date. Anything else --
	 * null, a boolean, a float, an array -- holds none.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value A stored or submitted value.
	 *
	 * @return bool
	 */
	public static function is_empty( mixed $value ): bool {
		if ( $value instanceof \DateTimeInterface ) {
			return false;
		}

		if ( is_int( $value ) ) {
			return $value <= 0;
		}

		if ( ! is_string( $value ) ) {
			return true;
		}

		$value = trim( $value );

		return '' === $value || str_starts_with( $value, '0000-00-00' );
	}

	/**
	 * Whatever code has to hand, as the UTC datetime to store.
	 *
	 * A DateTime of any zone, a Unix timestamp as an integer, or a string
	 * parse() can read -- `2026-07-15 14:30:00`, an ISO 8601 date with its
	 * offset, `tomorrow`. Null for anything that holds no date
	 * ({@see self::is_empty()}) and for anything that is not one; what
	 * "no date" is written as -- null, the zero date -- is the caller's to
	 * say.
	 *
	 * Only an integer is a timestamp. Digits in a string are read as a date,
	 * as they always were: `'20260715'` is the fifteenth of July.
	 *
	 *   Timestamp::to_sql( new DateTime( '2026-07-15 15:30', $dublin ) ); // '2026-07-15 14:30:00'
	 *   Timestamp::to_sql( 1784125800 );                                  // '2026-07-15 14:30:00'
	 *   Timestamp::to_sql( '0000-00-00 00:00:00' ) ?? Timestamp::ZERO;
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The moment.
	 *
	 * @return string|null `Y-m-d H:i:s` in UTC, or null.
	 */
	public static function to_sql( mixed $value ): ?string {
		if ( self::is_empty( $value ) ) {
			return null;
		}

		if ( $value instanceof \DateTimeInterface ) {
			return self::at( $value->getTimestamp() );
		}

		if ( is_int( $value ) ) {
			return self::at( $value );
		}

		return self::parse( (string) $value )?->format( self::SQL );
	}
}
