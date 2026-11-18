<?php
/**
 * Periods whose days are somebody's, stored as the UTC moments they are.
 *
 * @package SugarCommerce\Dates
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Preset;
use SugarCommerce\Dates\Range;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Today" is a day in a place. The moments are stored in UTC and a range
 * prints UTC, but where the day starts and ends is the shop's business --
 * and a day the clocks change is not twenty-four hours long.
 */
final class PresetZoneTest extends TestCase {

	/**
	 * A range as the two UTC strings it is queried with.
	 *
	 * @param Range $range The range.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function utc( Range $range ): array {
		return array( $range->start(), $range->end() );
	}

	/**
	 * @param string $now   The moment, in UTC.
	 * @param string $zone  The shop's timezone.
	 * @param string $from  Start of its today, in UTC.
	 * @param string $to    End of its today, in UTC.
	 * @param int    $hours How long that day is.
	 */
	#[DataProvider( 'todays' )]
	public function test_today_is_the_zones_midnight_to_midnight( string $now, string $zone, string $from, string $to, int $hours ): void {
		$range = Preset::Today->range( Clock::frozen( $now ), new DateTimeZone( $zone ) );

		$this->assertSame( array( $from, $to ), $this->utc( $range ) );
		$this->assertSame( $hours * 3600, $range->seconds() );
		$this->assertTrue( $range->contains( $now ), 'now is in today' );
		$this->assertSame( 'UTC', $range->from->getTimezone()->getName(), 'the moments themselves are handed back in UTC' );
		$this->assertSame( 'UTC', $range->to->getTimezone()->getName() );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: int}>
	 */
	public static function todays(): array {
		return array(
			'UTC'                               => array( '2026-07-15 14:30:00', 'UTC', '2026-07-15 00:00:00', '2026-07-16 00:00:00', 24 ),
			'New York, mid-morning'             => array( '2026-07-15 14:30:00', 'America/New_York', '2026-07-15 04:00:00', '2026-07-16 04:00:00', 24 ),
			// Half past eleven at night on the 15th, which is already the 16th in UTC.
			'New York, late on the 15th'        => array( '2026-07-16 03:30:00', 'America/New_York', '2026-07-15 04:00:00', '2026-07-16 04:00:00', 24 ),
			'New York in winter'                => array( '2026-01-15 14:30:00', 'America/New_York', '2026-01-15 05:00:00', '2026-01-16 05:00:00', 24 ),
			// Half past two in the morning of the 16th there.
			'Auckland, already tomorrow'        => array( '2026-07-15 14:30:00', 'Pacific/Auckland', '2026-07-15 12:00:00', '2026-07-16 12:00:00', 24 ),
			'New York, the clocks go forward'   => array( '2026-03-08 12:00:00', 'America/New_York', '2026-03-08 05:00:00', '2026-03-09 04:00:00', 23 ),
			'New York, the clocks go back'      => array( '2026-11-01 12:00:00', 'America/New_York', '2026-11-01 04:00:00', '2026-11-02 05:00:00', 25 ),
			'Auckland, the clocks go forward'   => array( '2026-09-26 20:00:00', 'Pacific/Auckland', '2026-09-26 12:00:00', '2026-09-27 11:00:00', 23 ),
			'Kathmandu, off the hour'           => array( '2026-07-15 14:30:00', 'Asia/Kathmandu', '2026-07-14 18:15:00', '2026-07-15 18:15:00', 24 ),
		);
	}

	public function test_an_order_late_at_night_counts_on_the_day_it_was_placed_there(): void {
		$york = new DateTimeZone( 'America/New_York' );

		// 23:30 on the 15th in New York is 03:30 on the 16th in UTC.
		$order = '2026-07-16 03:30:00';

		// Asked the next morning, New York time.
		$clock = Clock::frozen( '2026-07-16 14:00:00' );

		$this->assertTrue( Preset::Yesterday->range( $clock, $york )->contains( $order ), 'the 15th there' );
		$this->assertFalse( Preset::Today->range( $clock, $york )->contains( $order ) );

		// Left to UTC it is the other way round, which is what a shop in
		// New York saw before it could say where it was.
		$this->assertFalse( Preset::Yesterday->range( $clock )->contains( $order ) );
		$this->assertTrue( Preset::Today->range( $clock )->contains( $order ) );
	}

	public function test_with_no_zone_nothing_has_changed(): void {
		$clock = Clock::frozen( '2026-07-15 14:30:00' );
		$utc   = new DateTimeZone( 'UTC' );

		foreach ( Preset::cases() as $preset ) {
			$this->assertSame( $this->utc( $preset->range( $clock ) ), $this->utc( $preset->range( $clock, $utc, 1 ) ), $preset->value );
		}

		$this->assertSame( array( '2026-07-13 00:00:00', '2026-07-20 00:00:00' ), $this->utc( Preset::ThisWeek->range( $clock ) ), 'Monday to Monday' );
	}

	/**
	 * @param int    $week_starts 0 for Sunday to 6 for Saturday.
	 * @param string $this_week   The day this week starts on.
	 * @param string $last_week   The day last week started on.
	 */
	#[DataProvider( 'weeks' )]
	public function test_a_week_starts_on_the_day_it_is_told( int $week_starts, string $this_week, string $last_week ): void {
		// Wednesday 15 July 2026.
		$clock = Clock::frozen( '2026-07-15 14:30:00' );

		$now  = Preset::ThisWeek->range( $clock, null, $week_starts );
		$last = Preset::LastWeek->range( $clock, null, $week_starts );

		$this->assertSame( $this_week . ' 00:00:00', $now->start() );
		$this->assertSame( 7, $now->days() );
		$this->assertSame( $last_week . ' 00:00:00', $last->start() );
		$this->assertSame( $now->start(), $last->end(), 'the two sit side by side' );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public static function weeks(): array {
		return array(
			'Monday'             => array( 1, '2026-07-13', '2026-07-06' ),
			'Sunday'             => array( 0, '2026-07-12', '2026-07-05' ),
			'Saturday'           => array( 6, '2026-07-11', '2026-07-04' ),
			'Wednesday, today'   => array( 3, '2026-07-15', '2026-07-08' ),
			'Thursday, tomorrow' => array( 4, '2026-07-09', '2026-07-02' ),
			'seven is Sunday'    => array( 7, '2026-07-12', '2026-07-05' ),
		);
	}

	public function test_a_week_from_sunday_on_a_sunday_starts_that_day(): void {
		// Sunday 19 July 2026.
		$clock = Clock::frozen( '2026-07-19 23:00:00' );

		$this->assertSame( '2026-07-19 00:00:00', Preset::ThisWeek->range( $clock, null, 0 )->start() );
		$this->assertSame( '2026-07-13 00:00:00', Preset::ThisWeek->range( $clock, null, 1 )->start(), 'and from Monday it is the last day' );
	}

	public function test_longer_periods_are_the_zones_calendar(): void {
		$york  = new DateTimeZone( 'America/New_York' );
		$clock = Clock::frozen( '2026-03-20 14:30:00' );

		// March has the change of clocks in it: it starts in winter time
		// and ends in summer time, an hour short.
		$this->assertSame( array( '2026-03-01 05:00:00', '2026-04-01 04:00:00' ), $this->utc( Preset::ThisMonth->range( $clock, $york ) ) );
		$this->assertSame( array( '2026-02-01 05:00:00', '2026-03-01 05:00:00' ), $this->utc( Preset::LastMonth->range( $clock, $york ) ) );
		$this->assertSame( array( '2026-01-01 05:00:00', '2026-04-01 04:00:00' ), $this->utc( Preset::ThisQuarter->range( $clock, $york ) ) );
		$this->assertSame( array( '2025-10-01 04:00:00', '2026-01-01 05:00:00' ), $this->utc( Preset::LastQuarter->range( $clock, $york ) ) );
		$this->assertSame( array( '2026-01-01 05:00:00', '2027-01-01 05:00:00' ), $this->utc( Preset::ThisYear->range( $clock, $york ) ) );
		$this->assertSame( array( '2025-01-01 05:00:00', '2026-01-01 05:00:00' ), $this->utc( Preset::LastYear->range( $clock, $york ) ) );

		// Seven whole days ending this morning. The clocks went forward on
		// the 8th, so both ends are summer time; thirty days reach back
		// past it, and are an hour short of thirty times twenty-four.
		$this->assertSame( array( '2026-03-13 04:00:00', '2026-03-20 04:00:00' ), $this->utc( Preset::Last7Days->range( $clock, $york ) ) );
		$this->assertSame( array( '2026-02-18 05:00:00', '2026-03-20 04:00:00' ), $this->utc( Preset::Last30Days->range( $clock, $york ) ) );
		$this->assertSame( '2026-03-21 04:00:00', Preset::AllTime->range( $clock, $york )->end() );
		$this->assertSame( '2026-03-21 04:00:00', Preset::Last30DaysToDate->range( $clock, $york )->end() );
	}

	public function test_this_month_is_already_next_month_in_auckland(): void {
		// 11:00 UTC on 30 June is 23:00 in Auckland; 13:00 UTC is 1 July there.
		$zone = new DateTimeZone( 'Pacific/Auckland' );

		$this->assertSame( '2026-05-31 12:00:00', Preset::ThisMonth->range( Clock::frozen( '2026-06-30 11:00:00' ), $zone )->start() );
		$this->assertSame( '2026-06-30 12:00:00', Preset::ThisMonth->range( Clock::frozen( '2026-06-30 13:00:00' ), $zone )->start() );
	}

	public function test_two_picked_dates_are_days_in_the_zone(): void {
		$york = new DateTimeZone( 'America/New_York' );

		$this->assertSame(
			array( '2026-05-01 04:00:00', '2026-06-01 04:00:00' ),
			$this->utc( Preset::resolve( 'custom', '2026-05-01', '2026-05-31', null, $york ) ),
			'the whole of the 31st, as New York has it'
		);

		$this->assertSame(
			array( '2026-05-01 00:00:00', '2026-06-01 00:00:00' ),
			$this->utc( Preset::resolve( 'custom', '2026-05-01', '2026-05-31' ) ),
			'and UTC days when nobody says'
		);

		// A date that says where it is keeps its own offset; the day it
		// falls on is still read on the zone's calendar.
		$this->assertSame(
			array( '2026-05-01 04:00:00', '2026-05-02 10:00:00' ),
			$this->utc( Preset::resolve( '', '2026-05-01', '2026-05-02T12:00:00+02:00', null, $york ) )
		);
	}

	public function test_a_preset_slug_is_resolved_in_the_zone(): void {
		$clock = Clock::frozen( '2026-07-16 03:30:00' );
		$york  = new DateTimeZone( 'America/New_York' );

		$this->assertSame( array( '2026-07-15 04:00:00', '2026-07-16 04:00:00' ), $this->utc( Preset::resolve( 'today', '', '', $clock, $york ) ) );
		$this->assertSame( '2026-07-12 04:00:00', Preset::resolve( 'this_week', '', '', $clock, $york, 0 )->start(), 'Sunday the 12th there' );

		// Nothing usable falls back to thirty days, in the zone as well.
		$this->assertSame( array( '2026-06-15 04:00:00', '2026-07-15 04:00:00' ), $this->utc( Preset::resolve( 'nonsense', '', '', $clock, $york ) ) );
	}

	public function test_two_dates_the_wrong_way_round_are_put_right(): void {
		$backwards = Preset::resolve( 'custom', '2026-05-31', '2026-05-01' );

		$this->assertSame( array( '2026-05-01 00:00:00', '2026-06-01 00:00:00' ), $this->utc( $backwards ) );
		$this->assertGreaterThan( 0, $backwards->seconds() );
		$this->assertSame( $backwards->start(), $backwards->previous()->end(), 'the period before runs up to it, not away from it' );

		$this->assertSame(
			array( '2026-05-01 04:00:00', '2026-06-01 04:00:00' ),
			$this->utc( Preset::resolve( 'custom', '2026-05-31', '2026-05-01', null, new DateTimeZone( 'America/New_York' ) ) )
		);
	}

	public function test_a_zero_date_is_no_date_in_a_zone_either(): void {
		$clock = Clock::frozen( '2026-07-15 14:30:00' );
		$york  = new DateTimeZone( 'America/New_York' );

		$this->assertSame(
			$this->utc( Preset::Last30Days->range( $clock, $york ) ),
			$this->utc( Preset::resolve( 'custom', '0000-00-00', '2026-05-31', $clock, $york ) )
		);
	}

	public function test_a_range_prints_utc_whatever_zone_it_was_built_in(): void {
		$york  = new DateTimeZone( 'America/New_York' );
		$range = new Range( new DateTimeImmutable( '2026-07-15 00:00:00', $york ), new DateTimeImmutable( '2026-07-16 00:00:00', $york ) );

		$this->assertSame( array( '2026-07-15 04:00:00', '2026-07-16 04:00:00' ), $range->to_array() );
		$this->assertSame(
			array(
				'from' => '2026-07-15 04:00:00',
				'to'   => '2026-07-16 04:00:00',
			),
			$range->where( 'created' )[1],
			'and is queried in UTC'
		);
	}

	public function test_last_days_includes_today(): void {
		$clock = Clock::frozen( '2026-07-16 03:30:00' );

		$this->assertSame( array( '2026-06-17 00:00:00', '2026-07-17 00:00:00' ), $this->utc( Range::last_days( 30, $clock ) ) );
		$this->assertSame( array( '2026-07-16 00:00:00', '2026-07-17 00:00:00' ), $this->utc( Range::last_days( 1, $clock ) ) );

		// Still the 15th in New York.
		$this->assertSame(
			array( '2026-07-09 04:00:00', '2026-07-16 04:00:00' ),
			$this->utc( Range::last_days( 7, $clock, new DateTimeZone( 'America/New_York' ) ) )
		);
	}

	public function test_last_days_is_clamped(): void {
		$clock = Clock::frozen( '2026-07-15 14:30:00' );

		$this->assertSame( 1, Range::last_days( 0, $clock )->days() );
		$this->assertSame( 1, Range::last_days( -5, $clock )->days() );
		$this->assertSame( Range::MAX_DAYS, Range::last_days( PHP_INT_MAX, $clock )->days() );
		$this->assertLessThan( $clock->now(), Range::last_days( PHP_INT_MAX, $clock )->from->getTimestamp(), 'not wrapped into the future' );
	}

	public function test_where_prepared_is_what_wpdb_reads(): void {
		$range = Range::between( '2026-06-01 00:00:00', '2026-07-01 00:00:00' );

		$this->assertSame(
			array(
				'o.date_created >= %s AND o.date_created < %s',
				array( '2026-06-01 00:00:00', '2026-07-01 00:00:00' ),
			),
			$range->where_prepared( 'o.date_created' )
		);
	}

	/**
	 * @param string $column Something that is not a column name.
	 */
	#[DataProvider( 'unsafe' )]
	public function test_where_prepared_refuses_anything_but_a_name( string $column ): void {
		$this->expectException( \InvalidArgumentException::class );

		Range::between( '2026-06-01 00:00:00', '2026-07-01 00:00:00' )->where_prepared( $column );
	}

	/**
	 * @param string $column Something that is not a column name.
	 */
	#[DataProvider( 'unsafe' )]
	public function test_where_refuses_the_same( string $column ): void {
		$this->expectException( \InvalidArgumentException::class );

		Range::between( '2026-06-01 00:00:00', '2026-07-01 00:00:00' )->where( $column );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unsafe(): array {
		return array(
			'empty'              => array( '' ),
			'a statement'        => array( 'created; DROP TABLE orders' ),
			'an expression'      => array( 'COALESCE( a, b )' ),
			'a placeholder'      => array( 'created %s' ),
			'a trailing newline' => array( "created\n" ),
			'a leading digit'    => array( '1created' ),
		);
	}

	public function test_a_placeholder_prefix_is_held_to_a_names_letters(): void {
		$range = Range::between( '2026-06-01 00:00:00', '2026-07-01 00:00:00' );

		$this->assertSame( 'created >= :a_from AND created < :a_to', $range->where( 'created', 'a_' )[0] );

		$this->expectException( \InvalidArgumentException::class );

		$range->where( 'created', "a\n" );
	}

	public function test_a_series_query_refuses_a_table_with_a_newline_after_it(): void {
		$this->expectException( \InvalidArgumentException::class );

		Range::between( '2026-06-01 00:00:00', '2026-07-01 00:00:00' )->series_query( "orders\n", 'created', \SugarCommerce\Dates\Interval::Day );
	}
}
