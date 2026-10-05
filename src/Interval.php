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
			// %W is Monday-based in SQLite, matching ISO weeks.
			self::Week  => '%Y-W%W',
			self::Month => '%Y-%m',
			self::Year  => '%Y',
			// SQLite has no quarter token, so it is derived by the caller
			// from the month.
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
	 * A column of Unix integers needs `$epoch`: SQLite's strftime() reads
	 * a bare number as a Julian day, so without the `unixepoch` modifier
	 * every bucket lands thousands of years ago rather than raising an
	 * error. The keys come out the same as for a text column, so fill()
	 * works on either.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 The `$epoch` flag.
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
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $column ) ) {
			throw new \InvalidArgumentException( 'Unsafe column name: ' . $column );
		}

		return match ( strtolower( $driver ) ) {
			'sqlite' => "strftime('" . $this->sqlite_format() . "', " . $column . ( $epoch ? ", 'unixepoch'" : '' ) . ')',
			'mysql'  => "DATE_FORMAT(" . ( $epoch ? 'FROM_UNIXTIME(' . $column . ')' : $column ) . ", '" . $this->mysql_format() . "')",
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
	 * @since 1.0.0
	 *
	 * @param \DateTimeImmutable $date Moment.
	 *
	 * @return \DateTimeImmutable
	 */
	public function floor( \DateTimeImmutable $date ): \DateTimeImmutable {
		return match ( $this ) {
			self::Hour    => $date->setTime( (int) $date->format( 'G' ), 0 ),
			self::Day     => $date->setTime( 0, 0 ),
			self::Week    => $date->modify( 'monday this week' )->setTime( 0, 0 ),
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
