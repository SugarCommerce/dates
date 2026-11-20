<?php
/**
 * The site's own clock and formats.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Class Site
 *
 * Everything else in this library works in UTC, which is the only sane way to
 * store a moment. This is the layer that turns one into what a person in the
 * admin should see.
 *
 * Two WordPress facts drive it. A site has a timezone that is not the
 * server's, and it has date and time formats an administrator chose. Reading
 * either from PHP's defaults gives the wrong answer on most installs.
 *
 * @since 1.0.0
 */
final class Site {

	/**
	 * The site's timezone.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeZone
	 */
	public static function timezone(): DateTimeZone {
		// wp_timezone() has been in core since 5.3 and handles both ways a
		// site can be configured: a named zone, or a bare UTC offset.
		return wp_timezone();
	}

	/**
	 * The day the site's week starts on.
	 *
	 * The administrator's choice under Settings, General, as a number a
	 * week can be counted from: 0 for Sunday to 6 for Saturday. A number
	 * given is used in its place, held to the same range, so a caller can
	 * pass its own setting straight through.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $day 0 for Sunday to 6 for Saturday, or null for the
	 *                      site's.
	 *
	 * @return int 0 to 6.
	 */
	public static function week_starts( ?int $day = null ): int {
		$day ??= (int) get_option( 'start_of_week', 1 );

		return ( ( $day % 7 ) + 7 ) % 7;
	}

	/**
	 * A stored UTC value as a DateTimeImmutable in the site's timezone.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $value A UTC datetime, as stored.
	 * @param DateTimeZone|null $zone  Another zone to read it in. Null is
	 *                                 the site's.
	 *
	 * @return DateTimeImmutable|null Null when the value cannot be read.
	 */
	public static function local( string $value, ?DateTimeZone $zone = null ): ?DateTimeImmutable {
		return Timestamp::parse( $value )?->setTimezone( $zone ?? self::timezone() );
	}

	/**
	 * A stored UTC value, rendered the way this site renders dates.
	 *
	 * Through wp_date(), which is given the real moment and the site's
	 * timezone and translates the month and day names. Not date_i18n()
	 * with the offset added to the timestamp by hand: the day and the hour
	 * come out the same that way, but anything in the format that names the
	 * zone or the offset -- `T`, `e`, `P`, `c`, `U` -- does not, because the
	 * moment it is handed is not the moment it was.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $value  A UTC datetime, as stored.
	 * @param string|null $format A date format, or null for the site's.
	 *
	 * @return string Empty when the value cannot be read.
	 */
	public static function format( string $value, ?string $format = null ): string {
		$unix = Timestamp::to_unix( $value );

		if ( null === $unix ) {
			return '';
		}

		$format ??= (string) get_option( 'date_format', 'F j, Y' );

		return (string) wp_date( $format, $unix, self::timezone() );
	}

	/**
	 * A stored UTC value as date and time together, in the site's formats.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value A UTC datetime, as stored.
	 *
	 * @return string
	 */
	public static function format_datetime( string $value ): string {
		$date = (string) get_option( 'date_format', 'F j, Y' );
		$time = (string) get_option( 'time_format', 'g:i a' );

		return self::format( $value, $date . ' ' . $time );
	}

	/**
	 * A stored UTC value as a date-and-time input shows it: `2026-06-15T13:00`
	 * in the site's timezone.
	 *
	 * What an `<input type="datetime-local">` is given, so a moment typed in
	 * the site's time is shown back in it. {@see self::from_input()} is the
	 * way back.
	 *
	 *   Site::input( '2026-06-15 12:00:00' ); // '2026-06-15T13:00' in London
	 *
	 * @since 1.0.0
	 *
	 * @param string $value A UTC datetime, as stored.
	 *
	 * @return string Empty for no date, or one that cannot be read.
	 */
	public static function input( string $value ): string {
		return self::local( $value )?->format( 'Y-m-d\TH:i' ) ?? '';
	}

	/**
	 * A date and time typed in the site's timezone, as the UTC datetime to
	 * store.
	 *
	 * Strict, as {@see Timestamp::from_date()} is: `Y-m-d` and a time to the
	 * minute or the second, with a `T` or a space between them -- what a
	 * datetime-local input posts -- and nothing that is not a real moment on
	 * the calendar. A time the clocks skipped when they went forward is read
	 * as PHP reads it, the same distance into the new hour.
	 *
	 *   Site::from_input( '2026-06-15T13:00' ); // '2026-06-15 12:00:00' in London
	 *   Site::from_input( '2026-02-30T09:00' ); // '0000-00-00 00:00:00'
	 *   Site::from_input( '', null );           // null
	 *
	 * @since 1.0.0
	 *
	 * @param mixed       $typed What was typed or submitted.
	 * @param string|null $empty What to answer for nothing, or for something
	 *                           that is not a moment. The zero date by
	 *                           default.
	 *
	 * @return string|null `Y-m-d H:i:s` in UTC, or `$empty`.
	 */
	public static function from_input( mixed $typed, ?string $empty = Timestamp::ZERO ): ?string {
		$typed = is_string( $typed ) ? trim( $typed ) : '';

		if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2})[T ]([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $typed, $parts ) ) {
			return $empty;
		}

		$text = sprintf( '%s %s:%s:%s', $parts[1], $parts[2], $parts[3], '' !== ( $parts[4] ?? '' ) ? $parts[4] : '00' );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $text, self::timezone() );

		// createFromFormat() rolls 30 February over into March; a real day
		// reads back as the day that was typed.
		if ( false === $date || $date->format( 'Y-m-d' ) !== $parts[1] ) {
			return $empty;
		}

		return Timestamp::at( $date->getTimestamp() );
	}

	/**
	 * A run of whole days, said as shortly as it can be: "9–15 Nov 2026".
	 *
	 * The month is written once when both days are in it and the year once
	 * when both are in that -- "30 Nov – 6 Dec 2026", "29 Dec 2025 – 4 Jan
	 * 2026" -- and one day is just that day. No spaces round the dash
	 * between two numbers; spaces round it between two dates. Month names
	 * come from wp_date(), so they are the site's language.
	 *
	 * Either end may be a stored UTC value or a DateTime. Which calendar
	 * day a moment falls on depends on where you stand, so both are read in
	 * the site's timezone unless `timezone` says otherwise -- pass UTC for
	 * days that are already calendar days (`2026-11-09`) and must not move.
	 * Given the wrong way round, they are swapped.
	 *
	 *   Site::span( $order->date_created, $order->date_completed );
	 *   Site::span( '2026-11-09', '2026-11-15', true, array( 'month' => 'F', 'timezone' => new DateTimeZone( 'UTC' ) ) );
	 *
	 * @since 1.0.0
	 *
	 * @param string|\DateTimeInterface $from      The first day.
	 * @param string|\DateTimeInterface $to        The last day, counted.
	 * @param bool                      $with_year Say the year.
	 * @param array<string, mixed>      $args      {
	 *     Optional.
	 *
	 *     @type string            $month       The month's format character: `M`
	 *                                          for "Nov" (the default), `F` for
	 *                                          "November".
	 *     @type DateTimeZone|null $timezone    The zone whose days are meant.
	 *                                          Null, the default, is the site's.
	 *     @type bool              $repeat_year Say the year after both dates
	 *                                          even when it is the same one:
	 *                                          "28 Oct 2026 – 3 Nov 2026".
	 *                                          Default false.
	 * }
	 *
	 * @return string Not escaped. Empty when either end cannot be read.
	 */
	public static function span( string|\DateTimeInterface $from, string|\DateTimeInterface $to, bool $with_year = true, array $args = array() ): string {
		$zone  = ( $args['timezone'] ?? null ) instanceof DateTimeZone ? $args['timezone'] : self::timezone();
		$month = 'F' === ( $args['month'] ?? 'M' ) ? 'F' : 'M';

		$first = self::moment( $from, $zone );
		$last  = self::moment( $to, $zone );

		if ( null === $first || null === $last ) {
			return '';
		}

		if ( $last < $first ) {
			list( $first, $last ) = array( $last, $first );
		}

		$say   = static fn( DateTimeImmutable $at, string $format ): string => (string) wp_date( $format, $at->getTimestamp(), $zone );
		$year  = $with_year ? ' Y' : '';
		$whole = $say( $last, 'j ' . $month . $year );

		if ( $first->format( 'Y-m-d' ) === $last->format( 'Y-m-d' ) ) {
			return $whole;
		}

		if ( $first->format( 'Y-m' ) === $last->format( 'Y-m' ) ) {
			// No spaces round the dash between two numbers.
			return $say( $first, 'j' ) . '–' . $whole;
		}

		// The year after the first date only when it is a different one,
		// or when the caller wants every date whole.
		$both = $with_year && ( ! empty( $args['repeat_year'] ) || $first->format( 'Y' ) !== $last->format( 'Y' ) );

		return $say( $first, 'j ' . $month . ( $both ? ' Y' : '' ) ) . ' – ' . $whole;
	}

	/**
	 * "3 hours ago", or "in 2 days", in the site's language.
	 *
	 * A whole phrase is chosen for each unit and each direction, with
	 * _n(), because "{count} {unit} ago" cannot be translated in pieces:
	 * the unit inflects with the count and the word order is not English's.
	 * The one relative-time renderer of the SugarCommerce packages: tables'
	 * list dates and flyouts' activity feed call it too.
	 *
	 * The defaults are this library's taste: whole units ("1 week" until a
	 * second one has passed), "just now" under a minute, and a date in
	 * place of anything more than thirty days off. WordPress's own
	 * human_time_diff() differs on all three, and a screen that has always
	 * used that can keep its wording:
	 *
	 *   Site::relative( $value, null, Site::LIKE_WORDPRESS ); // "2 weeks ago" at 13 days, "12 seconds ago"
	 *
	 * @since 1.0.0
	 *
	 * @param string               $value A UTC datetime, as stored.
	 * @param Clock|null           $clock Fixed time, for tests.
	 * @param array<string, mixed> $args  {
	 *     Optional.
	 *
	 *     @type int    $cutoff     Seconds past which a date is given instead.
	 *                              0 for never. Default thirty days.
	 *     @type string $fallback   The date format used past the cutoff, in
	 *                              UTC. Default `j M Y`.
	 *     @type bool   $round      Count to the nearest unit, not the last
	 *                              whole one. See {@see Relative::parts()}.
	 *                              Default false.
	 *     @type bool   $just_now   Say "just now" under a minute. False counts
	 *                              the seconds: "12 seconds ago". Default true.
	 *     @type bool   $abbreviate "min" and "mins" for minutes, as WordPress
	 *                              wrote them before 6.3. Default false.
	 * }
	 *
	 * @return string Empty when the value cannot be read.
	 */
	public static function relative( string $value, ?Clock $clock = null, array $args = array() ): string {
		$unix = Timestamp::to_unix( $value );

		if ( null === $unix ) {
			return '';
		}

		$args  = array_merge( self::RELATIVE_DEFAULTS, $args );
		$delta = Relative::delta( $unix, ( $clock ?? new Clock() )->now() );

		// Past the cutoff a date reads better than a large unit count.
		if ( (int) $args['cutoff'] > 0 && abs( $delta ) > (int) $args['cutoff'] ) {
			return Timestamp::format( $value, (string) $args['fallback'] );
		}

		$parts = Relative::parts( $delta, (bool) $args['round'], ! $args['just_now'] );

		if ( null === $parts ) {
			return __( 'just now', 'sugarcommerce' );
		}

		return self::phrase( $parts['unit'], $parts['count'], $parts['future'] ? 'future' : 'past', (bool) $args['abbreviate'] );
	}

	/**
	 * A relative phrase as markup, with the exact moment on hover.
	 *
	 * `<span title="June 15, 2026 1:00 pm">3 hours ago</span>`: relative()
	 * to read and format_datetime() for whoever needs the moment, both
	 * escaped. For a `<time>` element or anything else, take the two
	 * strings from {@see self::relative_with_title()}.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $value A UTC datetime, as stored.
	 * @param Clock|null           $clock Fixed time, for tests.
	 * @param array<string, mixed> $args  As relative() takes them.
	 *
	 * @return string Escaped markup. Empty when the value cannot be read.
	 */
	public static function relative_html( string $value, ?Clock $clock = null, array $args = array() ): string {
		$pair = self::relative_with_title( $value, $clock, $args );

		return '' === $pair['text'] ? '' : sprintf( '<span title="%s">%s</span>', esc_attr( $pair['title'] ), esc_html( $pair['text'] ) );
	}

	/**
	 * A length of time in words: "3 months", "5 minutes".
	 *
	 * The same counting as relative(), without "ago" or "in" -- how long
	 * something has been running, how old a file is.
	 *
	 *   Site::duration( time() - $started );                       // "3 months"
	 *   Site::duration( 13 * DAY_IN_SECONDS, Site::LIKE_WORDPRESS ); // "2 weeks"
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $seconds How long. The sign is ignored.
	 * @param array<string, mixed> $args    `round`, `just_now` and
	 *                                      `abbreviate`, as relative() takes
	 *                                      them. With `just_now` (the default)
	 *                                      under a minute is "less than a
	 *                                      minute".
	 *
	 * @return string
	 */
	public static function duration( int $seconds, array $args = array() ): string {
		$args  = array_merge( self::RELATIVE_DEFAULTS, $args );
		$parts = Relative::parts( $seconds, (bool) $args['round'], ! $args['just_now'] );

		if ( null === $parts ) {
			return __( 'less than a minute', 'sugarcommerce' );
		}

		return self::phrase( $parts['unit'], $parts['count'], 'length', (bool) $args['abbreviate'] );
	}

	/**
	 * What relative() and duration() do unless told otherwise.
	 *
	 * @since 1.0.0
	 */
	private const array RELATIVE_DEFAULTS = array(
		'cutoff'     => 2592000,
		'fallback'   => 'j M Y',
		'round'      => false,
		'just_now'   => true,
		'abbreviate' => false,
	);

	/**
	 * The `$args` that make relative() and duration() count and word a
	 * time the way WordPress's human_time_diff() does.
	 *
	 * To the nearest unit, seconds under a minute, and never a date however
	 * long ago it was. (WordPress before 6.3 also wrote "mins"; add
	 * `'abbreviate' => true` for a screen that still should.)
	 *
	 * @since 1.0.0
	 */
	public const array LIKE_WORDPRESS = array(
		'cutoff'   => 0,
		'round'    => true,
		'just_now' => false,
	);

	/**
	 * One translated phrase: a unit, a count, and which way it faces.
	 *
	 * Written out in full, every one, so each is a string a translator
	 * sees whole with its plural beside it. Building them from a table of
	 * unit names would be a third the length and untranslatable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $unit       second, minute, hour, day, week, month or year.
	 * @param int    $count      How many.
	 * @param string $facing     `past` ("3 days ago"), `future` ("in 3 days")
	 *                           or `length` ("3 days").
	 * @param bool   $abbreviate "mins" for minutes.
	 *
	 * @return string
	 */
	private static function phrase( string $unit, int $count, string $facing, bool $abbreviate ): string {
		if ( 'minute' === $unit && $abbreviate ) {
			$unit = 'min';
		}

		$text = match ( $facing . ' ' . $unit ) {
			/* translators: %s: a number of seconds. */
			'past second'   => _n( '%s second ago', '%s seconds ago', $count, 'sugarcommerce' ),
			/* translators: %s: a number of minutes. */
			'past minute'   => _n( '%s minute ago', '%s minutes ago', $count, 'sugarcommerce' ),
			/* translators: %s: a number of minutes, abbreviated. */
			'past min'      => _n( '%s min ago', '%s mins ago', $count, 'sugarcommerce' ),
			/* translators: %s: a number of hours. */
			'past hour'     => _n( '%s hour ago', '%s hours ago', $count, 'sugarcommerce' ),
			/* translators: %s: a number of days. */
			'past day'      => _n( '%s day ago', '%s days ago', $count, 'sugarcommerce' ),
			/* translators: %s: a number of weeks. */
			'past week'     => _n( '%s week ago', '%s weeks ago', $count, 'sugarcommerce' ),
			/* translators: %s: a number of months. */
			'past month'    => _n( '%s month ago', '%s months ago', $count, 'sugarcommerce' ),
			/* translators: %s: a number of years. */
			'past year'     => _n( '%s year ago', '%s years ago', $count, 'sugarcommerce' ),

			/* translators: %s: a number of seconds. */
			'future second' => _n( 'in %s second', 'in %s seconds', $count, 'sugarcommerce' ),
			/* translators: %s: a number of minutes. */
			'future minute' => _n( 'in %s minute', 'in %s minutes', $count, 'sugarcommerce' ),
			/* translators: %s: a number of minutes, abbreviated. */
			'future min'    => _n( 'in %s min', 'in %s mins', $count, 'sugarcommerce' ),
			/* translators: %s: a number of hours. */
			'future hour'   => _n( 'in %s hour', 'in %s hours', $count, 'sugarcommerce' ),
			/* translators: %s: a number of days. */
			'future day'    => _n( 'in %s day', 'in %s days', $count, 'sugarcommerce' ),
			/* translators: %s: a number of weeks. */
			'future week'   => _n( 'in %s week', 'in %s weeks', $count, 'sugarcommerce' ),
			/* translators: %s: a number of months. */
			'future month'  => _n( 'in %s month', 'in %s months', $count, 'sugarcommerce' ),
			/* translators: %s: a number of years. */
			'future year'   => _n( 'in %s year', 'in %s years', $count, 'sugarcommerce' ),

			/* translators: %s: a number of seconds. */
			'length second' => _n( '%s second', '%s seconds', $count, 'sugarcommerce' ),
			/* translators: %s: a number of minutes. */
			'length minute' => _n( '%s minute', '%s minutes', $count, 'sugarcommerce' ),
			/* translators: %s: a number of minutes, abbreviated. */
			'length min'    => _n( '%s min', '%s mins', $count, 'sugarcommerce' ),
			/* translators: %s: a number of hours. */
			'length hour'   => _n( '%s hour', '%s hours', $count, 'sugarcommerce' ),
			/* translators: %s: a number of days. */
			'length day'    => _n( '%s day', '%s days', $count, 'sugarcommerce' ),
			/* translators: %s: a number of weeks. */
			'length week'   => _n( '%s week', '%s weeks', $count, 'sugarcommerce' ),
			/* translators: %s: a number of months. */
			'length month'  => _n( '%s month', '%s months', $count, 'sugarcommerce' ),
			/* translators: %s: a number of years. */
			default         => _n( '%s year', '%s years', $count, 'sugarcommerce' ),
		};

		return sprintf( $text, number_format_i18n( $count ) );
	}

	/**
	 * Either kind of moment span() takes, in a timezone.
	 *
	 * @since 1.0.0
	 *
	 * @param string|\DateTimeInterface $value A stored UTC value or a DateTime.
	 * @param DateTimeZone              $zone  The zone to stand in.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment( string|\DateTimeInterface $value, DateTimeZone $zone ): ?DateTimeImmutable {
		if ( $value instanceof \DateTimeInterface ) {
			return DateTimeImmutable::createFromInterface( $value )->setTimezone( $zone );
		}

		return Timestamp::parse( $value )?->setTimezone( $zone );
	}

	/**
	 * A relative phrase with the exact moment as its title attribute.
	 *
	 * The pair a list table wants: "3 hours ago" to read, and the real
	 * timestamp on hover for anyone who needs it.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $value A UTC datetime, as stored.
	 * @param Clock|null           $clock Fixed time, for tests.
	 * @param array<string, mixed> $args  As relative() takes them.
	 *
	 * @return array{text: string, title: string}
	 */
	public static function relative_with_title( string $value, ?Clock $clock = null, array $args = array() ): array {
		return array(
			'text'  => self::relative( $value, $clock, $args ),
			'title' => self::format_datetime( $value ),
		);
	}
}
