<?php
/**
 * A period between two moments.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates;

/**
 * Class Range
 *
 * The from/to pair every report and dashboard filter needs.
 *
 * **The end is exclusive.** A range of 1 May to 31 May written with an
 * inclusive end has to be queried as `< '2026-06-01'` or as
 * `<= '2026-05-31 23:59:59'`, and the second silently drops anything in
 * the final second of the month. Exclusive ends compose correctly, tile
 * without gaps or overlaps, and make `>= from AND < to` always right.
 *
 * @since 1.0.0
 */
final readonly class Range {

	/**
	 * @since 1.0.0
	 *
	 * @param \DateTimeImmutable $from Inclusive start.
	 * @param \DateTimeImmutable $to   Exclusive end.
	 */
	public function __construct(
		public \DateTimeImmutable $from,
		public \DateTimeImmutable $to,
	) {}

	/**
	 * Build from two stored timestamps.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Inclusive start.
	 * @param string $to   Exclusive end.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When either value is unparseable.
	 */
	public static function between( string $from, string $to ): self {
		$start = Timestamp::parse( $from );
		$end   = Timestamp::parse( $to );

		if ( null === $start || null === $end ) {
			throw new \InvalidArgumentException( 'Both ends of a range must be parseable timestamps.' );
		}

		return $start <= $end ? new self( $start, $end ) : new self( $end, $start );
	}

	/**
	 * The last few whole days, today included.
	 *
	 * The window behind a "last 30 days" chart: from midnight UTC at the
	 * start of the first day to midnight at the end of today, so the first
	 * bar is a whole day rather than whatever was left of it at this hour,
	 * and today's bar is today. Preset::Last30DaysToDate for any length.
	 *
	 * @since 1.1.0
	 *
	 * @param int        $days  How many days, at least one.
	 * @param Clock|null $clock Source of now.
	 *
	 * @return self
	 */
	public static function last_days( int $days, ?Clock $clock = null ): self {
		$tomorrow = ( $clock ?? new Clock() )->utc()->setTime( 0, 0 )->modify( '+1 day' );

		return new self( $tomorrow->modify( '-' . max( 1, $days ) . ' days' ), $tomorrow );
	}

	/**
	 * The start, in storage format.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function start(): string {
		return $this->from->format( Timestamp::SQL );
	}

	/**
	 * The end, in storage format.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function end(): string {
		return $this->to->format( Timestamp::SQL );
	}

	/**
	 * Length in seconds.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function seconds(): int {
		return $this->to->getTimestamp() - $this->from->getTimestamp();
	}

	/**
	 * Length in whole days.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function days(): int {
		return (int) floor( $this->seconds() / 86400 );
	}

	/**
	 * Whether a moment falls inside the range.
	 *
	 * Inclusive of the start, exclusive of the end.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Stored timestamp.
	 *
	 * @return bool
	 */
	public function contains( string $value ): bool {
		$unix = Timestamp::to_unix( $value );

		if ( null === $unix ) {
			return false;
		}

		return $unix >= $this->from->getTimestamp() && $unix < $this->to->getTimestamp();
	}

	/**
	 * The equivalent range immediately before this one.
	 *
	 * For period-on-period comparison. Same length, ending where this one
	 * begins, so the two tile exactly — comparing a 30-day window against
	 * "last month" is the usual way a dashboard reports a change that did
	 * not happen.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public function previous(): self {
		$length = $this->seconds();

		return new self(
			$this->from->modify( '-' . $length . ' seconds' ),
			$this->from
		);
	}

	/* ─── Querying ──────────────────────────────────────────────────── */

	/**
	 * A `WHERE` fragment and its bindings for this range.
	 *
	 * Written as `>= from AND < to`, which is correct because the end is
	 * exclusive. The common alternative — `BETWEEN from AND to` with the
	 * end set to 23:59:59 — silently drops anything in the final second
	 * of the period, and does so only on the busiest reports.
	 *
	 * The column is validated rather than escaped, because it is
	 * interpolated into SQL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column Column holding a UTC timestamp.
	 * @param string $prefix Placeholder prefix, so several ranges can
	 *                       appear in one statement without colliding.
	 *
	 * @return array{0: string, 1: array<string, string>} SQL and bindings.
	 *
	 * @throws \InvalidArgumentException On an unsafe column name.
	 */
	public function where( string $column, string $prefix = '' ): array {
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_.]*$/', $column ) ) {
			throw new \InvalidArgumentException( 'Unsafe column name: ' . $column );
		}

		$from = $prefix . 'from';
		$to   = $prefix . 'to';

		return array(
			$column . ' >= :' . $from . ' AND ' . $column . ' < :' . $to,
			array( $from => $this->start(), $to => $this->end() ),
		);
	}

	/**
	 * A `WHERE` fragment for a column of Unix seconds.
	 *
	 * The same exclusive-end comparison as where(), bound as integers.
	 * Comparing an integer column with the formatted strings where() binds
	 * is not an error in SQLite — it is a type-ordering rule under which
	 * every integer sorts before every string, so the query silently
	 * matches everything or nothing.
	 *
	 * @since 1.1.0
	 *
	 * @param string $column Column holding Unix seconds.
	 * @param string $prefix Placeholder prefix.
	 *
	 * @return array{0: string, 1: array<string, int>} SQL and bindings.
	 *
	 * @throws \InvalidArgumentException On an unsafe column name.
	 */
	public function where_epoch( string $column, string $prefix = '' ): array {
		[ $sql ] = $this->where( $column, $prefix );

		return array(
			$sql,
			array( $prefix . 'from' => $this->from->getTimestamp(), $prefix . 'to' => $this->to->getTimestamp() ),
		);
	}

	/**
	 * A complete `SELECT` for a grouped time series.
	 *
	 * Returns the statement and its bindings for "count and sum, bucketed
	 * by day" and similar — the query behind every dashboard chart.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 The `$epoch` flag.
	 *
	 * @param string   $table    Table name.
	 * @param string   $column   Timestamp column to group and filter on.
	 * @param Interval $interval Bucket size.
	 * @param string[] $selects  Extra aggregate expressions, e.g.
	 *                           `array( 'SUM(total) AS revenue' )`.
	 * @param string   $driver   `sqlite` or `mysql`.
	 * @param bool     $epoch    Whether the column holds Unix seconds.
	 *
	 * @return array{0: string, 1: array<string, string|int>}
	 *
	 * @throws \InvalidArgumentException On an unsafe identifier.
	 */
	public function series_query(
		string $table,
		string $column,
		Interval $interval,
		array $selects = array(),
		string $driver = 'sqlite',
		bool $epoch = false
	): array {
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $table ) ) {
			throw new \InvalidArgumentException( 'Unsafe table name: ' . $table );
		}

		[ $where, $bindings ] = $epoch ? $this->where_epoch( $column ) : $this->where( $column );

		$bucket  = $interval->group_expression( $column, $driver, $epoch );
		$columns = array_merge( array( $bucket . ' AS bucket', 'COUNT(*) AS count' ), $selects );

		$sql = 'SELECT ' . implode( ', ', $columns )
			. ' FROM ' . $table
			. ' WHERE ' . $where
			. ' GROUP BY bucket'
			. ' ORDER BY bucket';

		return array( $sql, $bindings );
	}

	/**
	 * Every bucket in the range, in order.
	 *
	 * @since 1.0.0
	 *
	 * @param Interval $interval Bucket size.
	 *
	 * @return Range[] Consecutive, non-overlapping sub-ranges.
	 */
	public function buckets( Interval $interval ): array {
		$buckets = array();
		$cursor  = $interval->floor( $this->from );

		// A guard against a pathological range and an hour-grain request.
		$limit = 20000;

		while ( $cursor < $this->to && count( $buckets ) < $limit ) {
			$next      = $cursor->modify( $interval->step() );
			$buckets[] = new self( $cursor, $next );
			$cursor    = $next;
		}

		return $buckets;
	}

	/**
	 * Fill gaps in a grouped result set with zeroes.
	 *
	 * `GROUP BY` returns only the buckets that had rows, so a chart drawn
	 * straight from a query has holes where nothing happened — and a line
	 * chart joins across them, which reads as a gradual decline rather
	 * than two days of no sales. This produces one entry per bucket,
	 * present or not.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $rows     Query results keyed by bucket.
	 * @param Interval             $interval Bucket size.
	 * @param mixed                $empty    Value for a missing bucket.
	 *
	 * @return array<int, array{key: string, label: string, start: string, value: mixed}>
	 */
	public function fill( array $rows, Interval $interval, mixed $empty = 0 ): array {
		$filled = array();

		foreach ( $this->buckets( $interval ) as $bucket ) {
			$key = $interval->key( $bucket->from );

			$filled[] = array(
				'key'   => $key,
				'label' => $interval->label( $bucket->from ),
				'start' => $bucket->start(),
				'value' => $rows[ $key ] ?? $empty,
			);
		}

		return $filled;
	}

	/**
	 * The range as a `[ from, to ]` pair of stored strings.
	 *
	 * @since 1.0.0
	 *
	 * @return array{0: string, 1: string}
	 */
	public function to_array(): array {
		return array( $this->start(), $this->end() );
	}
}
