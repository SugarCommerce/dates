<?php
/**
 * A period cut into the pieces a chart draws.
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
 * Class Buckets
 *
 * How a period is cut up, and what each piece is called.
 *
 * A chart of a year with a point for every day is a fence of slanted dates,
 * and a chart of today with one point is a dot. What a period should be
 * divided into follows from how long it is, and what each division is called
 * follows from that: hours are "9am", days are "Mon 9", the weeks of a month
 * are "Week 1", months are "Jan".
 *
 * **Everything here is in a timezone** -- the site's unless another is
 * given. A shop in Sydney closes its day at its own midnight, not at
 * Greenwich's, so the buckets are that shop's days and the range they are
 * cut from is still two UTC moments.
 *
 * This is not {@see Interval}, and does not replace it. Interval groups in
 * SQL, in UTC, with keys the database can produce. Buckets are for code that
 * has each moment in its hands -- rows grouped by the hour and turned into
 * local time in PHP -- and wants the axis a person would draw: weeks that
 * start on the day the site's week starts, the hour the clocks go back said
 * once, names in the site's language.
 *
 *   $buckets = Buckets::for_range( $range );
 *   $index   = array_flip( $buckets['keys'] );
 *
 *   foreach ( $rows as $row ) {
 *       $at = $index[ Buckets::key( Site::local( $row->created, $zone ), $buckets['size'] ) ] ?? null;
 *   }
 *
 * @since 1.0.0
 */
final class Buckets {

	/**
	 * More buckets than this is not a chart.
	 *
	 * Nothing below reaches it for a range the size rules were written for;
	 * it is here so a range of a thousand years is a short loop and not a
	 * long one.
	 *
	 * @since 1.0.0
	 */
	private const MOST = 400;

	/**
	 * A period, cut into buckets.
	 *
	 * @since 1.0.0
	 *
	 * @param Range             $range       The period. Its end is the first
	 *                                       moment after it, as a Range's is.
	 * @param DateTimeZone|null $zone        The site's, unless told otherwise.
	 * @param int|null          $week_starts 0 for Sunday to 6 for Saturday.
	 *                                       The site's, unless told otherwise.
	 * @param Interval|null     $size        Force a size. How the period
	 *                                       before this one is cut the same
	 *                                       way as this one, whatever its own
	 *                                       length says.
	 *
	 * @return array{size: Interval, keys: array<int, string>, labels: array<int, string|array<int, string>>, titles: array<int, string>, starts: array<int, int>}
	 *         `keys` are what self::key() gives for a moment in that bucket,
	 *         in order. `labels` are what goes under the axis -- a list of two
	 *         where there are two lines. `titles` say the same bucket in full,
	 *         for a sentence. `starts` are when each begins, as timestamps.
	 */
	public static function for_range( Range $range, ?DateTimeZone $zone = null, ?int $week_starts = null, ?Interval $size = null ): array {
		$week_starts = Site::week_starts( $week_starts );

		[ $from, $to ] = self::ends( $range, $zone );

		$days = self::days( $from, $to );
		$size = $size ?? self::size( $days, $from, $to, $week_starts );

		$crosses  = $from->format( 'Y' ) !== $to->format( 'Y' );
		$numbered = Interval::Week === $size && $days <= 45;

		$keys   = array();
		$labels = array();
		$titles = array();
		$starts = array();

		foreach ( self::starts( $from, $to, $size, $week_starts ) as $at ) {
			$key = self::key( $at, $size, $week_starts );

			// The hour the clocks go back happens twice, and both of them
			// are the same hour on the axis.
			if ( in_array( $key, $keys, true ) ) {
				continue;
			}

			$keys[] = $key;

			[ $label, $title ] = self::name( $at, $size, $from, $to, $crosses, $numbered ? count( $keys ) : 0 );

			$labels[] = $label;
			$titles[] = $title;
			$starts[] = $at->getTimestamp();
		}

		return array(
			'size'   => $size,
			'keys'   => $keys,
			'labels' => $labels,
			'titles' => $titles,
			'starts' => $starts,
		);
	}

	/**
	 * What size of bucket suits a period.
	 *
	 * Hours for a day or two, days up to a fortnight, weeks for a month or
	 * a quarter, then months, quarters and years.
	 *
	 * @since 1.0.0
	 *
	 * @param Range             $range       The period.
	 * @param DateTimeZone|null $zone        The site's, unless told otherwise.
	 * @param int|null          $week_starts 0 for Sunday to 6 for Saturday.
	 *
	 * @return Interval
	 */
	public static function size_for( Range $range, ?DateTimeZone $zone = null, ?int $week_starts = null ): Interval {
		[ $from, $to ] = self::ends( $range, $zone );

		return self::size( self::days( $from, $to ), $from, $to, Site::week_starts( $week_starts ) );
	}

	/**
	 * Which bucket a moment falls in.
	 *
	 * The keys of one size sort as text in the order they happened, so a
	 * bucket can be compared with another without parsing either.
	 *
	 * Not Interval::key(): a week here is the date it starts on, because
	 * which day that is depends on the site, and a quarter says which
	 * quarter it is.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $local       The moment, in the zone the
	 *                                       buckets are in.
	 * @param Interval          $size        The bucket size.
	 * @param int|null          $week_starts 0 for Sunday to 6 for Saturday.
	 *
	 * @return string
	 */
	public static function key( DateTimeImmutable $local, Interval $size, ?int $week_starts = null ): string {
		return match ( $size ) {
			Interval::Hour    => $local->format( 'Y-m-d H' ),
			Interval::Day     => $local->format( 'Y-m-d' ),
			Interval::Week    => Interval::Week->floor( $local, Site::week_starts( $week_starts ) )->format( 'Y-m-d' ),
			Interval::Month   => $local->format( 'Y-m' ),
			Interval::Quarter => $local->format( 'Y' ) . '-Q' . (int) ceil( (int) $local->format( 'n' ) / 3 ),
			Interval::Year    => $local->format( 'Y' ),
		};
	}

	/**
	 * How many characters of a stored UTC datetime make one slot.
	 *
	 * For a database that is not asked what hour a date is. Rows are
	 * grouped by the first characters of the stored value --
	 * `SUBSTR( created, 1, 13 )` is the date and the hour, the same in
	 * MySQL, MariaDB and SQLite -- and each group is turned into the zone's
	 * time with {@see self::slot_time()}. That is exact through a change of
	 * clocks, which one offset for the whole period would not be.
	 *
	 * A zone whose offset is not a whole number of hours -- India, Nepal,
	 * Newfoundland -- has its hours start part-way through UTC's, so there
	 * the grouping has to be by the minute.
	 *
	 * @since 1.0.0
	 *
	 * @param Range             $range The period.
	 * @param DateTimeZone|null $zone  The site's, unless told otherwise.
	 *
	 * @return int Thirteen for the hour, sixteen for the minute.
	 */
	public static function slot_length( Range $range, ?DateTimeZone $zone = null ): int {
		$zone = $zone ?? Site::timezone();

		foreach ( array( $range->from, $range->to ) as $moment ) {
			if ( 0 !== $moment->setTimezone( $zone )->getOffset() % 3600 ) {
				return 16;
			}
		}

		return 13;
	}

	/**
	 * The start of a slot, as a moment in a zone.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $slot The first thirteen or sixteen characters
	 *                                of a stored UTC datetime.
	 * @param DateTimeZone|null $zone The site's, unless told otherwise.
	 *
	 * @return DateTimeImmutable
	 */
	public static function slot_time( string $slot, ?DateTimeZone $zone = null ): DateTimeImmutable {
		$zone = $zone ?? Site::timezone();

		// Padded back out to a whole datetime: "2026-06-10 21" is the hour
		// that started at 21:00:00. Something that is not a date is now,
		// which is what a screen would have meant by leaving it out.
		return Site::local( $slot . substr( '0000-00-00 00:00:00', strlen( $slot ) ), $zone ) ?? ( new Clock() )->local( $zone );
	}

	/**
	 * An hour of the day, as the site writes the time.
	 *
	 * @since 1.0.0
	 *
	 * @param int $hour 0 to 23.
	 *
	 * @return string "9am", or "09:00" on a site that uses the 24-hour clock.
	 */
	public static function hour_label( int $hour ): string {
		$hour = ( ( $hour % 24 ) + 24 ) % 24;

		// The site's own time format says which clock it reads. An upper
		// case H or G is the 24-hour one.
		if ( preg_match( '/[HG]/', (string) get_option( 'time_format', 'g:i a' ) ) ) {
			return sprintf( '%02d:00', $hour );
		}

		$twelve = 0 === $hour % 12 ? 12 : $hour % 12;

		// "am" and "pm" as WordPress itself writes them in this language.
		return $twelve . wp_date( 'a', $hour * 3600, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Both ends of a period on the zone's clock, the last moment included.
	 *
	 * @since 1.0.0
	 *
	 * @param Range             $range The period.
	 * @param DateTimeZone|null $zone  The site's, unless told otherwise.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 */
	private static function ends( Range $range, ?DateTimeZone $zone ): array {
		$zone = $zone ?? Site::timezone();
		$from = $range->from->setTimezone( $zone );
		$to   = $range->to->setTimezone( $zone );

		if ( $to < $from ) {
			$to = $from;
		}

		// A period that ends on the stroke of midnight ends with the day
		// before. A Range runs up to the start of the next one, and taken
		// literally "this year" has a thirteenth month with one second in
		// it.
		if ( $to > $from && '00:00:00' === $to->format( 'H:i:s' ) ) {
			$to = $to->modify( '-1 second' );
		}

		return array( $from, $to );
	}

	/**
	 * What size of bucket suits a period.
	 *
	 * @since 1.0.0
	 *
	 * @param int               $days        Calendar days the period touches.
	 * @param DateTimeImmutable $from        Its start, in the zone.
	 * @param DateTimeImmutable $to          Its end, in the zone.
	 * @param int               $week_starts 0 for Sunday to 6 for Saturday.
	 *
	 * @return Interval
	 */
	private static function size( int $days, DateTimeImmutable $from, DateTimeImmutable $to, int $week_starts ): Interval {
		// Today, or yesterday and today: the only thing smaller than the
		// period is the hour.
		if ( $days <= 2 ) {
			return Interval::Hour;
		}

		// Up to a fortnight every day has room for its own name.
		if ( $days <= 14 ) {
			return Interval::Day;
		}

		// A month is four or five weeks, which is how anybody describes
		// how a month went.
		if ( $days <= 45 ) {
			return Interval::Week;
		}

		// A quarter or two is still read in weeks, until there are more of
		// them than fit along the axis level.
		if ( $days <= 200 ) {
			$weeks = (int) floor( self::days( Interval::Week->floor( $from, $week_starts ), $to ) / 7 ) + 1;

			return $weeks <= 26 ? Interval::Week : Interval::Month;
		}

		// A year or two, by month.
		if ( $days <= 750 ) {
			return Interval::Month;
		}

		// Up to five years of quarters is twenty points; past that the
		// question is about years.
		return $days <= 1830 ? Interval::Quarter : Interval::Year;
	}

	/**
	 * The first moment of every bucket a period touches.
	 *
	 * Days, weeks and months are stepped on the calendar, not by adding
	 * seconds: the day the clocks change is twenty-three or twenty-five
	 * hours long, and a day's worth of seconds added to its midnight lands
	 * on the wrong side of the next one.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $from        Start, in the zone.
	 * @param DateTimeImmutable $to          End, in the zone.
	 * @param Interval          $size        The bucket size.
	 * @param int               $week_starts 0 for Sunday to 6 for Saturday.
	 *
	 * @return array<int, DateTimeImmutable>
	 */
	private static function starts( DateTimeImmutable $from, DateTimeImmutable $to, Interval $size, int $week_starts ): array {
		$starts = array();

		if ( Interval::Hour === $size ) {
			// By the clock on the wall of somewhere that never changes it,
			// so the hour that does not exist is skipped and the hour that
			// happens twice is met twice.
			$first = $from->getTimestamp() - ( (int) $from->format( 'i' ) * 60 ) - (int) $from->format( 's' );
			$last  = $to->getTimestamp();

			for ( $at = $first; $at <= $last && count( $starts ) < self::MOST; $at += 3600 ) {
				$starts[] = ( new DateTimeImmutable( '@' . $at ) )->setTimezone( $from->getTimezone() );
			}

			return $starts;
		}

		$at   = $size->floor( $from, $week_starts );
		$step = $size->step();

		while ( $at <= $to && count( $starts ) < self::MOST ) {
			$starts[] = $at;
			$at       = $at->modify( $step );
		}

		return $starts;
	}

	/**
	 * What a bucket is called under the axis, and in a sentence.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $at      The bucket's first moment.
	 * @param Interval          $size    The bucket size.
	 * @param DateTimeImmutable $from    The period's start.
	 * @param DateTimeImmutable $to      The period's end.
	 * @param bool              $crosses Whether the period spans two years.
	 * @param int               $number  Which week of the period this is, or
	 *                                   0 when weeks are named by their date.
	 *
	 * @return array{0: string|array<int, string>, 1: string}
	 */
	private static function name( DateTimeImmutable $at, Interval $size, DateTimeImmutable $from, DateTimeImmutable $to, bool $crosses, int $number ): array {
		switch ( $size ) {
			case Interval::Hour:
				$hour = self::hour_label( (int) $at->format( 'G' ) );

				return array( $hour, self::date( $at, 'D j M' ) . ', ' . $hour );

			case Interval::Week:
				// Only the part of the week the period covers. A month that
				// starts on a Thursday has a first week of four days, and
				// calling it "27 Oct – 2 Nov" claims days nobody asked about.
				$first = $at < $from ? $from : $at;
				$last  = $at->modify( '+6 days' );
				$last  = $last > $to ? $to : $last;
				$span  = Site::span(
					$first,
					$last,
					$crosses,
					array(
						'timezone'    => $at->getTimezone(),
						'repeat_year' => true,
					)
				);

				if ( $number > 0 ) {
					/* translators: %d: which week of the period, counting from one */
					return array( array( sprintf( __( 'Week %d', 'arraypress' ), $number ), $span ), $span );
				}

				return array( self::date( $first, $crosses ? 'j M Y' : 'j M' ), $span );

			case Interval::Month:
				return array( self::date( $at, $crosses ? 'M Y' : 'M' ), self::date( $at, 'F Y' ) );

			case Interval::Quarter:
				/* translators: 1: the quarter, one to four, 2: the year */
				$quarter = sprintf( __( 'Q%1$d %2$s', 'arraypress' ), (int) ceil( (int) $at->format( 'n' ) / 3 ), $at->format( 'Y' ) );

				return array( $quarter, $quarter );

			case Interval::Year:
				return array( $at->format( 'Y' ), $at->format( 'Y' ) );

			default:
				return array( self::date( $at, 'D j' ), self::date( $at, $crosses ? 'D j M Y' : 'D j M' ) );
		}
	}

	/**
	 * How many calendar days a period touches.
	 *
	 * Counted on the calendar: from 11pm on Monday to 1am on Tuesday is two
	 * hours and two days, and it is the days that decide what the axis
	 * should say.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $from Start.
	 * @param DateTimeImmutable $to   End.
	 *
	 * @return int At least one.
	 */
	private static function days( DateTimeImmutable $from, DateTimeImmutable $to ): int {
		$utc = new DateTimeZone( 'UTC' );

		// Both dates read as if they were in UTC, where every day is the
		// same length and the difference divides exactly.
		$first = new DateTimeImmutable( $from->format( 'Y-m-d' ), $utc );
		$last  = new DateTimeImmutable( $to->format( 'Y-m-d' ), $utc );

		return max( 1, (int) round( ( $last->getTimestamp() - $first->getTimestamp() ) / 86400 ) + 1 );
	}

	/**
	 * A moment, formatted with the site's translated month and day names.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeImmutable $at     The moment.
	 * @param string            $format A date format.
	 *
	 * @return string
	 */
	private static function date( DateTimeImmutable $at, string $format ): string {
		return (string) wp_date( $format, $at->getTimestamp(), $at->getTimezone() );
	}
}
