<?php
/**
 * Bucket sizes for grouping a range.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates;

/**
 * Enum Interval
 *
 * The grain a report is grouped by.
 *
 * Each case knows how to express itself as a SQL date format, because
 * the alternative — writing `strftime('%Y-%m', …)` inline in one query
 * and `DATE_FORMAT(…, '%Y-%m')` in another — is how a report ends up
 * grouping by different things on different pages.
 *
 * @since 1.0.0
 */
enum Interval: string {

	case Hour    = 'hour';
	case Day     = 'day';
	case Week    = 'week';
	case Month   = 'month';
	case Quarter = 'quarter';
	case Year    = 'year';

	/**
	 * A SQLite `strftime` pattern for this grain.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function sqlite_format(): string {
		return match ( $this ) {
			self::Hour  => '%Y-%m-%d %H:00:00',
			self::Day   => '%Y-%m-%d',
			// Not the ISO week: %W counts from the year's first Monday and
			// starts at 00. Kept as the plain pattern it has always been;
			// group_expression() is what gives the ISO week key() expects.
			self::Week  => '%Y-W%W',
			self::Month => '%Y-%m',
			self::Year  => '%Y',
			// SQLite has no quarter token. This pattern alone groups by
			// month; group_expression() is what folds the three months of
			// a quarter into one.
			self::Quarter => '%Y-%m',
		};
	}

	/**
	 * A MySQL `DATE_FORMAT` pattern for this grain.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function mysql_format(): string {
		return match ( $this ) {
			self::Hour    => '%Y-%m-%d %H:00:00',
			self::Day     => '%Y-%m-%d',
			self::Week    => '%x-W%v',
			self::Month   => '%Y-%m',
			self::Year    => '%Y',
			self::Quarter => '%Y-%m',
		};
	}

	/**
	 * A `GROUP BY` expression for a column.
	 *
	 * What it answers is what {@see self::key()} gives for the same
	 * bucket, which is the whole point: Range::fill() looks each bucket up
	 * by that key.
	 *
	 * A quarter is not a date pattern in either database, so it is
	 * arithmetic on the month: the year and the quarter's FIRST month,
	 * `2026-04` for anything from April to June. The month pattern alone
	 * would send the second and third months of every quarter back under
	 * keys fill() never asks for -- counted by the database and missing
	 * from the chart.
	 *
	 * A column of Unix integers needs `$epoch`: SQLite's strftime() reads a
	 * bare number as a Julian day, so without it every bucket lands
	 * thousands of years ago rather than raising an error. The keys come
	 * out the same as for a text column, so fill() works on either.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column Column holding a UTC timestamp.
	 * @param string $driver `sqlite` or `mysql`.
	 * @param bool   $epoch  Whether the column holds Unix seconds rather
	 *                       than a date-time string. On MySQL this goes
	 *                       through FROM_UNIXTIME(), which uses the session
	 *                       time zone: set it to UTC.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException On an unsafe column name or an
	 *                                   unknown driver.
	 */
	public function group_expression( string $column, string $driver = 'sqlite', bool $epoch = false ): string {
		// Interpolated into SQL, so validated rather than escaped.
		// \z and not $: a dollar also matches before a trailing newline.
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*\z/', $column ) ) {
			throw new \InvalidArgumentException( 'Unsafe column name: ' . $column );
		}

		// Unix seconds become a date-time first, and every expression below
		// reads that in place of the column.
		if ( $epoch ) {
			$column = match ( strtolower( $driver ) ) {
				'sqlite' => 'datetime(' . $column . ", 'unixepoch')",
				'mysql'  => 'FROM_UNIXTIME(' . $column . ')',
				default  => throw new \InvalidArgumentException( 'Unknown driver: ' . $driver ),
			};
		}

		if ( self::Quarter === $this ) {
			return match ( strtolower( $driver ) ) {
				// Integer division: months 4, 5 and 6 are all ( n - 1 ) / 3 = 1.
				'sqlite' => "strftime('%Y-', " . $column . ") || printf('%02d', ((CAST(strftime('%m', " . $column . ') AS INTEGER) - 1) / 3) * 3 + 1)',
				'mysql'  => 'CONCAT(YEAR(' . $column . "), '-', LPAD((QUARTER(" . $column . ") - 1) * 3 + 1, 2, '0'))",
				default  => throw new \InvalidArgumentException( 'Unknown driver: ' . $driver ),
			};
		}

		if ( self::Week === $this && 'sqlite' === strtolower( $driver ) ) {
			// The ISO week, worked out from the week's Thursday: 'weekday 0'
			// moves on to the Sunday that ends the week and three days back
			// from there is its Thursday, whose year is the week's year and
			// whose day of the year says which week it is. SQLite's own %W
			// counts from the year's first Monday and starts at 00, so it
			// disagreed with key() -- which is ISO -- in most years, and
			// filled charts lost those weeks. %G and %V would say this
			// directly, but only SQLite 3.46 and later has them.
			$thursday = 'date(' . $column . ", 'weekday 0', '-3 days')";

			return "strftime('%Y', " . $thursday . ") || '-W' || printf('%02d', (CAST(strftime('%j', " . $thursday . ') AS INTEGER) - 1) / 7 + 1)';
		}

		return match ( strtolower( $driver ) ) {
			'sqlite' => "strftime('" . $this->sqlite_format() . "', " . $column . ')',
			'mysql'  => 'DATE_FORMAT(' . $column . ", '" . $this->mysql_format() . "')",
			default  => throw new \InvalidArgumentException( 'Unknown driver: ' . $driver ),
		};
	}

	/**
	 * The step to advance by.
	 *
	 * @since 1.0.0
	 *
	 * @return string A `DateTimeImmutable::modify()` expression.
	 */
	public function step(): string {
		return match ( $this ) {
			self::Hour    => '+1 hour',
			self::Day     => '+1 day',
			self::Week    => '+1 week',
			self::Month   => '+1 month',
			self::Quarter => '+3 months',
			self::Year    => '+1 year',
		};
	}

	/**
	 * Snap a moment back to the start of its bucket.
	 *
	 * On the moment's own calendar: a week, a month or a year starts at
	 * midnight in whatever zone the date is in.
	 *
	 * @since 1.0.0
	 *
	 * @param \DateTimeImmutable $date        Moment.
	 * @param int                $week_starts The day a week starts on: 0 for
	 *                                        Sunday to 6 for Saturday. Monday,
	 *                                        the ISO week, unless told.
	 *
	 * @return \DateTimeImmutable
	 */
	public function floor( \DateTimeImmutable $date, int $week_starts = 1 ): \DateTimeImmutable {
		return match ( $this ) {
			self::Hour    => $date->setTime( (int) $date->format( 'G' ), 0 ),
			self::Day     => $date->setTime( 0, 0 ),
			self::Week    => $date->setTime( 0, 0 )->modify( '-' . ( ( (int) $date->format( 'w' ) - ( ( $week_starts % 7 ) + 7 ) % 7 + 7 ) % 7 ) . ' days' ),
			self::Month   => $date->modify( 'first day of this month' )->setTime( 0, 0 ),
			self::Quarter => $date->setDate(
				(int) $date->format( 'Y' ),
				( intdiv( (int) $date->format( 'n' ) - 1, 3 ) * 3 ) + 1,
				1
			)->setTime( 0, 0 ),
			self::Year    => $date->setDate( (int) $date->format( 'Y' ), 1, 1 )->setTime( 0, 0 ),
		};
	}

	/**
	 * A label for this bucket.
	 *
	 * @since 1.0.0
	 *
	 * @param \DateTimeImmutable $date Bucket start.
	 *
	 * @return string
	 */
	public function label( \DateTimeImmutable $date ): string {
		return match ( $this ) {
			self::Hour    => $date->format( 'j M H:00' ),
			self::Day     => $date->format( 'j M' ),
			self::Week    => 'w/c ' . $date->format( 'j M' ),
			self::Month   => $date->format( 'M Y' ),
			self::Quarter => 'Q' . ( intdiv( (int) $date->format( 'n' ) - 1, 3 ) + 1 ) . ' ' . $date->format( 'Y' ),
			self::Year    => $date->format( 'Y' ),
		};
	}

	/**
	 * The key this bucket groups under, matching the SQL expression.
	 *
	 * @since 1.0.0
	 *
	 * @param \DateTimeImmutable $date Bucket start.
	 *
	 * @return string
	 */
	public function key( \DateTimeImmutable $date ): string {
		return match ( $this ) {
			self::Hour    => $date->format( 'Y-m-d H:00:00' ),
			self::Day     => $date->format( 'Y-m-d' ),
			self::Week    => $date->format( 'o-\WW' ),
			self::Month   => $date->format( 'Y-m' ),
			self::Quarter => $date->format( 'Y-m' ),
			self::Year    => $date->format( 'Y' ),
		};
	}
}
