<?php
/**
 * Dates test suite.
 *
 * Every test runs against a frozen clock. Date logic fails at
 * boundaries — month ends, leap days, daylight-saving transitions — and
 * those cannot be reached from a suite that waits for the real clock.
 *
 * @package   Sugar\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace Sugar\Dates\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sugar\Dates\{Clock, Interval, Preset, Range, Relative, Timestamp};

final class DatesTest extends TestCase {

	/** Wednesday, 15 July 2026 at 14:30 UTC. */
	private const NOW = '2026-07-15 14:30:00';

	private function clock( string $at = self::NOW ): Clock {
		return Clock::frozen( $at );
	}

	/* ─── Clock ─────────────────────────────────────────────────────── */

	/**
	 * The 45-59 second window used to fall through every unit — the
	 * smallest is the minute — and come back out of the fallback as
	 * "0 minutes ago".
	 */
	public function test_no_delta_ever_reads_as_zero_of_a_unit(): void {
		for ( $seconds = 0; $seconds <= 3700; $seconds += 7 ) {
			// Anchored: "10 minutes ago" legitimately contains "0 minute".
			$this->assertSame(
				0,
				preg_match( '/\b0 (second|minute|hour|day|week|month|year)/', Relative::phrase( $seconds ) ),
				$seconds . ' seconds rendered a zero count.'
			);
		}
	}

	public function test_under_a_minute_is_just_now(): void {
		foreach ( array( 0, 1, 30, 44, 45, 59 ) as $seconds ) {
			$this->assertSame( 'just now', Relative::phrase( $seconds ) );
		}

		$this->assertSame( '1 minute ago', Relative::phrase( 60 ) );
	}

	public function test_a_frozen_clock_does_not_move(): void {
		$clock = $this->clock();

		$this->assertSame( $clock->now(), $clock->now() );
		$this->assertTrue( $clock->is_frozen() );
		$this->assertSame( self::NOW, $clock->utc()->format( Timestamp::SQL ) );
	}

	public function test_a_real_clock_reports_now(): void {
		$clock = new Clock();

		$this->assertFalse( $clock->is_frozen() );
		$this->assertEqualsWithDelta( time(), $clock->now(), 2 );
	}

	public function test_freezing_accepts_a_unix_timestamp(): void {
		$this->assertSame( 1700000000, Clock::frozen( 1700000000 )->now() );
	}

	public function test_freezing_rejects_nonsense(): void {
		$this->expectException( \InvalidArgumentException::class );
		Clock::frozen( 'not a date' );
	}

	/* ─── Parsing ───────────────────────────────────────────────────── */

	/**
	 * A bare date-time carries no zone, so a naive parse applies the
	 * server's — and every stored value silently shifts when the server
	 * moves or its config changes. UTC is assumed explicitly.
	 */
	public function test_bare_timestamps_are_read_as_utc(): void {
		$original = date_default_timezone_get();

		try {
			date_default_timezone_set( 'America/New_York' );

			$this->assertSame( 1784125800, Timestamp::to_unix( self::NOW ) );
		} finally {
			date_default_timezone_set( $original );
		}
	}

	public function test_timestamps_with_a_zone_keep_it(): void {
		// 14:30 in Berlin is 12:30 UTC in July.
		$this->assertSame( '2026-07-15 12:30:00', Timestamp::format( '2026-07-15 14:30:00 +02:00' ) );
	}

	#[DataProvider( 'unparseable' )]
	public function test_unparseable_values_return_null( string $value ): void {
		$this->assertNull( Timestamp::parse( $value ) );
		$this->assertNull( Timestamp::to_unix( $value ) );
		$this->assertSame( '', Timestamp::format( $value ) );
	}

	/** @return array<string, array{0: string}> */
	public static function unparseable(): array {
		return array(
			'empty'      => array( '' ),
			'whitespace' => array( '   ' ),
			'garbage'    => array( 'not a date' ),
			// The MySQL zero date, which reaches PHP as a real string and
			// parses to something absurd if you let it.
			'zero date'  => array( '0000-00-00 00:00:00' ),
		);
	}

	public function test_formatting_converts_timezone(): void {
		$this->assertSame( '2026-07-15 15:30:00', Timestamp::format( self::NOW, Timestamp::SQL, 'Europe/London' ) );
		$this->assertSame( '2026-07-15 10:30:00', Timestamp::format( self::NOW, Timestamp::SQL, 'America/New_York' ) );
	}

	public function test_an_invalid_timezone_falls_back_to_utc(): void {
		$this->assertSame( self::NOW, Timestamp::format( self::NOW, Timestamp::SQL, 'Not/AZone' ) );
	}

	public function test_iso_formatting(): void {
		$this->assertSame( '2026-07-15T14:30:00+00:00', Timestamp::iso( self::NOW ) );
	}

	public function test_past_and_future(): void {
		$clock = $this->clock();

		$this->assertTrue( Timestamp::is_past( '2026-07-15 14:29:59', $clock ) );
		$this->assertFalse( Timestamp::is_past( '2026-07-15 14:30:01', $clock ) );
		$this->assertTrue( Timestamp::is_future( '2026-07-15 14:30:01', $clock ) );
	}

	/**
	 * A corrupt value must never read as expired — that would silently
	 * revoke access rather than surfacing the bad data.
	 */
	public function test_an_unparseable_value_is_neither_past_nor_future(): void {
		$this->assertFalse( Timestamp::is_past( 'garbage', $this->clock() ) );
		$this->assertFalse( Timestamp::is_future( 'garbage', $this->clock() ) );
	}

	/* ─── Relative ──────────────────────────────────────────────────── */

	#[DataProvider( 'relative_phrases' )]
	public function test_relative_phrases( int $delta, string $expected ): void {
		$this->assertSame( $expected, Relative::phrase( $delta ) );
	}

	/** @return array<string, array{0: int, 1: string}> */
	public static function relative_phrases(): array {
		return array(
			'now'           => array( 0, 'just now' ),
			'seconds'       => array( 30, 'just now' ),
			'one minute'    => array( 60, '1 minute ago' ),
			'minutes'       => array( 300, '5 minutes ago' ),
			'one hour'      => array( 3600, '1 hour ago' ),
			'hours'         => array( 7200, '2 hours ago' ),
			'one day'       => array( 86400, '1 day ago' ),
			'days'          => array( 259200, '3 days ago' ),
			'one week'      => array( 604800, '1 week ago' ),
			'weeks'         => array( 1209600, '2 weeks ago' ),
			'future minute' => array( -60, 'in 1 minute' ),
			'future days'   => array( -259200, 'in 3 days' ),
		);
	}

	public function test_singular_and_plural_are_correct(): void {
		$this->assertStringContainsString( '1 hour ago', Relative::phrase( 3600 ) );
		$this->assertStringContainsString( '2 hours ago', Relative::phrase( 7200 ) );
	}

	/**
	 * "43 weeks ago" is harder to read than a date, so past the cutoff a
	 * date is returned instead.
	 */
	public function test_distant_times_fall_back_to_a_date(): void {
		$this->assertSame( '1 Jan 2025', Relative::format( '2025-01-01 00:00:00', $this->clock() ) );
	}

	public function test_the_cutoff_can_be_disabled(): void {
		$this->assertStringContainsString( 'ago', Relative::format( '2025-01-01 00:00:00', $this->clock(), 0 ) );
	}

	public function test_relative_output_pairs_with_an_absolute_title(): void {
		$result = Relative::with_title( '2026-07-15 12:30:00', $this->clock() );

		$this->assertSame( '2 hours ago', $result['text'] );
		$this->assertStringContainsString( 'UTC', $result['title'] );
		$this->assertStringContainsString( '15 July 2026', $result['title'] );
	}

	public function test_unparseable_values_render_as_nothing(): void {
		$this->assertSame( '', Relative::format( 'garbage', $this->clock() ) );
		$this->assertSame( array( 'text' => '', 'title' => '' ), Relative::with_title( 'garbage' ) );
	}

	/* ─── Ranges ────────────────────────────────────────────────────── */

	/**
	 * The whole reason ends are exclusive: an inclusive end has to be
	 * written as 23:59:59, which drops anything in the final second.
	 */
	public function test_the_end_is_exclusive(): void {
		$range = Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' );

		$this->assertTrue( $range->contains( '2026-05-01 00:00:00' ), 'start is inclusive' );
		$this->assertTrue( $range->contains( '2026-05-31 23:59:59' ), 'the last second of May' );
		$this->assertFalse( $range->contains( '2026-06-01 00:00:00' ), 'end is exclusive' );
	}

	public function test_ranges_tile_without_gaps(): void {
		$may  = Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' );
		$june = Range::between( '2026-06-01 00:00:00', '2026-07-01 00:00:00' );

		// Every instant belongs to exactly one of them.
		foreach ( array( '2026-05-31 23:59:59', '2026-06-01 00:00:00', '2026-06-30 23:59:59' ) as $moment ) {
			$this->assertSame( 1, (int) $may->contains( $moment ) + (int) $june->contains( $moment ), $moment );
		}
	}

	public function test_reversed_ranges_are_corrected(): void {
		$range = Range::between( '2026-06-01 00:00:00', '2026-05-01 00:00:00' );

		$this->assertSame( '2026-05-01 00:00:00', $range->start() );
		$this->assertSame( '2026-06-01 00:00:00', $range->end() );
	}

	public function test_range_length(): void {
		$range = Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' );

		$this->assertSame( 31, $range->days() );
		$this->assertSame( 2678400, $range->seconds() );
	}

	/**
	 * Comparing a 30-day window against "last month" is how a dashboard
	 * reports a change that did not happen. The previous period is the
	 * same length, ending where this one starts.
	 */
	public function test_the_previous_period_is_the_same_length_and_abuts(): void {
		$range    = Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' );
		$previous = $range->previous();

		$this->assertSame( $range->seconds(), $previous->seconds() );
		$this->assertSame( $range->start(), $previous->end() );
	}

	public function test_unparseable_range_ends_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Range::between( 'garbage', '2026-06-01 00:00:00' );
	}

	/* ─── Querying ──────────────────────────────────────────────────── */

	/**
	 * `BETWEEN from AND to` with a 23:59:59 end silently drops anything
	 * in the final second — and only on the busiest reports.
	 */
	public function test_where_uses_an_exclusive_upper_bound(): void {
		[ $sql, $bindings ] = Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' )->where( 'created_at' );

		$this->assertSame( 'created_at >= :from AND created_at < :to', $sql );
		$this->assertStringNotContainsString( 'BETWEEN', $sql );
		$this->assertSame( '2026-05-01 00:00:00', $bindings['from'] );
		$this->assertSame( '2026-06-01 00:00:00', $bindings['to'] );
	}

	public function test_placeholders_can_be_prefixed_to_avoid_collisions(): void {
		[ $sql, $bindings ] = Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' )->where( 'created_at', 'a_' );

		$this->assertStringContainsString( ':a_from', $sql );
		$this->assertArrayHasKey( 'a_from', $bindings );
	}

	public function test_unsafe_column_names_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' )->where( 'created_at; DROP TABLE orders' );
	}

	public function test_a_series_query_is_built(): void {
		[ $sql, $bindings ] = Preset::Last30Days->range( $this->clock() )->series_query(
			'orders',
			'created_at',
			Interval::Day,
			array( 'SUM(total) AS revenue' )
		);

		$this->assertStringContainsString( "strftime('%Y-%m-%d', created_at) AS bucket", $sql );
		$this->assertStringContainsString( 'SUM(total) AS revenue', $sql );
		$this->assertStringContainsString( 'GROUP BY bucket', $sql );
		$this->assertStringContainsString( 'ORDER BY bucket', $sql );
		$this->assertArrayHasKey( 'from', $bindings );
	}

	public function test_a_series_query_supports_mysql(): void {
		[ $sql ] = Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' )
			->series_query( 'orders', 'created_at', Interval::Month, array(), 'mysql' );

		$this->assertStringContainsString( "DATE_FORMAT(created_at, '%Y-%m')", $sql );
	}

	public function test_unsafe_table_names_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' )
			->series_query( 'orders; DROP TABLE x', 'created_at', Interval::Day );
	}

	/* ─── Buckets ───────────────────────────────────────────────────── */

	#[DataProvider( 'bucket_counts' )]
	public function test_bucket_counts( Interval $interval, string $from, string $to, int $expected ): void {
		$this->assertCount( $expected, Range::between( $from, $to )->buckets( $interval ) );
	}

	/** @return array<string, array{0: Interval, 1: string, 2: string, 3: int}> */
	public static function bucket_counts(): array {
		return array(
			'days in May'      => array( Interval::Day, '2026-05-01 00:00:00', '2026-06-01 00:00:00', 31 ),
			'days in Feb 2028' => array( Interval::Day, '2028-02-01 00:00:00', '2028-03-01 00:00:00', 29 ),
			'months in a year' => array( Interval::Month, '2026-01-01 00:00:00', '2027-01-01 00:00:00', 12 ),
			'quarters'         => array( Interval::Quarter, '2026-01-01 00:00:00', '2027-01-01 00:00:00', 4 ),
			'hours in a day'   => array( Interval::Hour, '2026-05-01 00:00:00', '2026-05-02 00:00:00', 24 ),
		);
	}

	public function test_buckets_tile_the_range_exactly(): void {
		$buckets = Range::between( '2026-05-01 00:00:00', '2026-05-08 00:00:00' )->buckets( Interval::Day );

		foreach ( $buckets as $index => $bucket ) {
			if ( isset( $buckets[ $index + 1 ] ) ) {
				$this->assertSame( $bucket->end(), $buckets[ $index + 1 ]->start(), 'no gap at bucket ' . $index );
			}
		}
	}

	/**
	 * GROUP BY returns only buckets that had rows, so a line chart drawn
	 * from raw results joins across the empty days — which reads as a
	 * gradual decline rather than two days of no sales.
	 */
	public function test_missing_buckets_are_filled_with_zero(): void {
		$range = Range::between( '2026-05-01 00:00:00', '2026-05-08 00:00:00' );

		// Only three days had orders.
		$rows = array(
			'2026-05-01' => 10,
			'2026-05-04' => 7,
			'2026-05-07' => 3,
		);

		$filled = $range->fill( $rows, Interval::Day );

		$this->assertCount( 7, $filled, 'one entry per day, present or not' );
		$this->assertSame( 10, $filled[0]['value'] );
		$this->assertSame( 0, $filled[1]['value'], 'the 2nd had no orders' );
		$this->assertSame( 7, $filled[3]['value'] );
		$this->assertSame( 3, $filled[6]['value'] );
	}

	public function test_filled_buckets_carry_labels(): void {
		$filled = Range::between( '2026-05-01 00:00:00', '2026-05-03 00:00:00' )->fill( array(), Interval::Day );

		$this->assertSame( '1 May', $filled[0]['label'] );
		$this->assertSame( '2026-05-01', $filled[0]['key'] );
		$this->assertSame( '2026-05-01 00:00:00', $filled[0]['start'] );
	}

	public function test_bucket_keys_match_the_sql_grouping(): void {
		// The fill key must equal what strftime produces, or every bucket
		// reads as empty.
		foreach ( array( Interval::Day, Interval::Month, Interval::Year ) as $interval ) {
			$date = new \DateTimeImmutable( '2026-05-04 00:00:00', new \DateTimeZone( 'UTC' ) );

			$this->assertSame(
				gmdate( str_replace( array( '%Y', '%m', '%d' ), array( 'Y', 'm', 'd' ), $interval->sqlite_format() ), $date->getTimestamp() ),
				$interval->key( $date ),
				$interval->value
			);
		}
	}

	public function test_interval_labels(): void {
		$date = new \DateTimeImmutable( '2026-05-04 09:00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '4 May', Interval::Day->label( $date ) );
		$this->assertSame( 'May 2026', Interval::Month->label( $date ) );
		$this->assertSame( 'Q2 2026', Interval::Quarter->label( $date ) );
		$this->assertSame( '2026', Interval::Year->label( $date ) );
		$this->assertSame( 'w/c 4 May', Interval::Week->label( $date ) );
	}

	public function test_intervals_floor_to_their_bucket_start(): void {
		$date = new \DateTimeImmutable( '2026-05-14 15:47:23', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '2026-05-14 15:00:00', Interval::Hour->floor( $date )->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-05-14 00:00:00', Interval::Day->floor( $date )->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-05-11 00:00:00', Interval::Week->floor( $date )->format( 'Y-m-d H:i:s' ), 'Monday' );
		$this->assertSame( '2026-05-01 00:00:00', Interval::Month->floor( $date )->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-04-01 00:00:00', Interval::Quarter->floor( $date )->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-01-01 00:00:00', Interval::Year->floor( $date )->format( 'Y-m-d H:i:s' ) );
	}

	public function test_unknown_drivers_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Interval::Day->group_expression( 'created_at', 'oracle' );
	}

	/* ─── Presets ───────────────────────────────────────────────────── */

	#[DataProvider( 'preset_ranges' )]
	public function test_presets_resolve( Preset $preset, string $from, string $to ): void {
		$range = $preset->range( $this->clock() );

		$this->assertSame( $from, $range->start(), $preset->value . ' start' );
		$this->assertSame( $to, $range->end(), $preset->value . ' end' );
	}

	/** @return array<string, array{0: Preset, 1: string, 2: string}> */
	public static function preset_ranges(): array {
		// Frozen at Wednesday 15 July 2026, 14:30 UTC.
		return array(
			'today'        => array( Preset::Today, '2026-07-15 00:00:00', '2026-07-16 00:00:00' ),
			'yesterday'    => array( Preset::Yesterday, '2026-07-14 00:00:00', '2026-07-15 00:00:00' ),
			'last 7'       => array( Preset::Last7Days, '2026-07-08 00:00:00', '2026-07-15 00:00:00' ),
			'last 30'      => array( Preset::Last30Days, '2026-06-15 00:00:00', '2026-07-15 00:00:00' ),
			'this week'    => array( Preset::ThisWeek, '2026-07-13 00:00:00', '2026-07-20 00:00:00' ),
			'last week'    => array( Preset::LastWeek, '2026-07-06 00:00:00', '2026-07-13 00:00:00' ),
			'this month'   => array( Preset::ThisMonth, '2026-07-01 00:00:00', '2026-08-01 00:00:00' ),
			'last month'   => array( Preset::LastMonth, '2026-06-01 00:00:00', '2026-07-01 00:00:00' ),
			'this quarter' => array( Preset::ThisQuarter, '2026-07-01 00:00:00', '2026-10-01 00:00:00' ),
			'last quarter' => array( Preset::LastQuarter, '2026-04-01 00:00:00', '2026-07-01 00:00:00' ),
			'this year'    => array( Preset::ThisYear, '2026-01-01 00:00:00', '2027-01-01 00:00:00' ),
			'last year'    => array( Preset::LastYear, '2025-01-01 00:00:00', '2026-01-01 00:00:00' ),
		);
	}

	/**
	 * A partial day dragged into a total makes every comparison against
	 * a complete period look like a decline.
	 */
	public function test_last_30_days_excludes_today(): void {
		$this->assertFalse( Preset::Last30Days->range( $this->clock() )->contains( self::NOW ) );
	}

	public function test_a_to_date_variant_includes_today(): void {
		$this->assertTrue( Preset::Last30DaysToDate->range( $this->clock() )->contains( self::NOW ) );
	}

	/**
	 * Monday-start weeks, which is what European businesses report on.
	 */
	public function test_weeks_start_on_monday(): void {
		$this->assertSame( 'Monday', Preset::ThisWeek->range( $this->clock() )->from->format( 'l' ) );
	}

	public function test_week_boundaries_hold_on_a_sunday(): void {
		// Sunday 19 July belongs to the week starting Monday the 13th.
		$range = Preset::ThisWeek->range( $this->clock( '2026-07-19 23:00:00' ) );

		$this->assertSame( '2026-07-13 00:00:00', $range->start() );
	}

	/**
	 * "first day of last month" from 31 March is a classic overflow —
	 * naive month arithmetic lands in March again.
	 */
	public function test_month_arithmetic_survives_the_end_of_a_month(): void {
		$range = Preset::LastMonth->range( $this->clock( '2026-03-31 12:00:00' ) );

		$this->assertSame( '2026-02-01 00:00:00', $range->start() );
		$this->assertSame( '2026-03-01 00:00:00', $range->end() );
	}

	public function test_february_in_a_leap_year(): void {
		$range = Preset::ThisMonth->range( $this->clock( '2028-02-29 12:00:00' ) );

		$this->assertSame( 29, $range->days() );
	}

	#[DataProvider( 'quarters' )]
	public function test_quarter_boundaries( string $now, string $from, string $to ): void {
		$range = Preset::ThisQuarter->range( $this->clock( $now ) );

		$this->assertSame( $from, $range->start() );
		$this->assertSame( $to, $range->end() );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public static function quarters(): array {
		return array(
			'Q1' => array( '2026-02-10 00:00:00', '2026-01-01 00:00:00', '2026-04-01 00:00:00' ),
			'Q2' => array( '2026-05-10 00:00:00', '2026-04-01 00:00:00', '2026-07-01 00:00:00' ),
			'Q3' => array( '2026-08-10 00:00:00', '2026-07-01 00:00:00', '2026-10-01 00:00:00' ),
			'Q4' => array( '2026-11-10 00:00:00', '2026-10-01 00:00:00', '2027-01-01 00:00:00' ),
		);
	}

	public function test_last_quarter_crosses_the_year(): void {
		$range = Preset::LastQuarter->range( $this->clock( '2026-01-15 00:00:00' ) );

		$this->assertSame( '2025-10-01 00:00:00', $range->start() );
		$this->assertSame( '2026-01-01 00:00:00', $range->end() );
	}

	/* ─── Resolution ────────────────────────────────────────────────── */

	public function test_a_known_slug_resolves(): void {
		$this->assertSame(
			Preset::ThisMonth->range( $this->clock() )->to_array(),
			Preset::resolve( 'this_month', '', '', $this->clock() )->to_array()
		);
	}

	/**
	 * A person choosing "1 May to 31 May" means the whole of the 31st,
	 * but the range end is exclusive — so it advances to midnight.
	 */
	public function test_custom_dates_include_the_final_day(): void {
		$range = Preset::resolve( 'custom', '2026-05-01', '2026-05-31', $this->clock() );

		$this->assertTrue( $range->contains( '2026-05-31 23:59:59' ) );
		$this->assertFalse( $range->contains( '2026-06-01 00:00:00' ) );
	}

	public function test_an_unknown_slug_falls_back_sensibly(): void {
		$this->assertSame(
			Preset::Last30Days->range( $this->clock() )->to_array(),
			Preset::resolve( 'nonsense', '', '', $this->clock() )->to_array()
		);
	}

	public function test_every_preset_has_a_label_and_resolves(): void {
		foreach ( Preset::cases() as $preset ) {
			$this->assertNotSame( '', $preset->label(), $preset->value );

			$range = $preset->range( $this->clock() );

			$this->assertLessThan( $range->to, $range->from, $preset->value . ' must be non-empty' );
		}
	}

	public function test_options_cover_every_case(): void {
		$this->assertCount( count( Preset::cases() ), Preset::options() );
		$this->assertSame( 'Last 30 days', Preset::options()['last_30'] );
	}
}
