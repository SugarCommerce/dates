<?php
/**
 * How a period is cut up for a chart.
 *
 * @package SugarCommerce\Dates
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use SugarCommerce\Dates\Buckets;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Models\Interval;
use SugarCommerce\Dates\Models\Range;
use SugarCommerce\Dates\Site;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The x-axis of a chart.
 *
 * A wrong axis is not an error. The chart still draws, with a point for
 * every day of a year or one bar for a whole week, and what it shows is
 * true -- it just cannot be read. So what is pinned here is the reading:
 * which size a period gets, what each bucket is called, and that a day the
 * clocks change is still one day.
 */
final class BucketsTest extends TestCase {

	protected function tearDown(): void {
		du_reset_globals();
	}

	/**
	 * A period, cut up as a site in UTC with weeks from Monday would.
	 *
	 * @param string $start       Start, in UTC.
	 * @param string $end         End, in UTC.
	 * @param string $zone        The site's timezone.
	 * @param int    $week_starts 0 for Sunday to 6 for Saturday.
	 *
	 * @return array<string, mixed>
	 */
	private function cut( string $start, string $end, string $zone = 'UTC', int $week_starts = 1 ): array {
		return Buckets::for_range( Range::between( $start, $end ), new DateTimeZone( $zone ), $week_starts );
	}

	/**
	 * Today is read by the hour, as the site writes the time.
	 */
	public function test_a_day_is_cut_into_hours(): void {
		$day = $this->cut( '2026-11-16 00:00:00', '2026-11-16 23:59:59' );

		$this->assertSame( Interval::Hour, $day['size'] );
		$this->assertCount( 24, $day['keys'] );
		$this->assertSame( '2026-11-16 09', $day['keys'][9] );
		$this->assertSame( '12am', $day['labels'][0] );
		$this->assertSame( '9am', $day['labels'][9] );
		$this->assertSame( '12pm', $day['labels'][12] );
		$this->assertSame( '7pm', $day['labels'][19] );
		$this->assertSame( 'Mon 16 Nov, 9am', $day['titles'][9] );

		// Yesterday and today together are still hours.
		$this->assertCount( 48, $this->cut( '2026-11-15 00:00:00', '2026-11-16 23:59:59' )['keys'] );

		// A site on the 24-hour clock is not told the time in am and pm.
		$GLOBALS['du_options']['time_format'] = 'H:i';

		$this->assertSame( '19:00', Buckets::hour_label( 19 ) );
	}

	/**
	 * A week or two is read by the day, each with its name.
	 */
	public function test_a_week_is_cut_into_days(): void {
		$week = $this->cut( '2026-11-09 00:00:00', '2026-11-15 23:59:59' );

		$this->assertSame( Interval::Day, $week['size'] );
		$this->assertSame( array( 'Mon 9', 'Tue 10', 'Wed 11', 'Thu 12', 'Fri 13', 'Sat 14', 'Sun 15' ), $week['labels'] );
		$this->assertSame( '2026-11-09', $week['keys'][0] );
		$this->assertSame( 'Mon 9 Nov', $week['titles'][0] );

		// Fourteen days is the last of the days; fifteen is weeks.
		$this->assertSame( Interval::Day, $this->cut( '2026-11-02 00:00:00', '2026-11-15 23:59:59' )['size'] );
		$this->assertSame( Interval::Week, $this->cut( '2026-11-01 00:00:00', '2026-11-15 23:59:59' )['size'] );
	}

	/**
	 * A month is its weeks, numbered, each saying which days it is -- and
	 * only the days that are in the month.
	 */
	public function test_a_month_that_starts_mid_week_is_cut_into_numbered_weeks(): void {
		// October 2026 starts on a Thursday and ends on a Saturday.
		$month = $this->cut( '2026-10-01 00:00:00', '2026-10-31 23:59:59' );

		$this->assertSame( Interval::Week, $month['size'] );

		// Keyed by the Monday each week starts on, though the first is in
		// September.
		$this->assertSame( array( '2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26' ), $month['keys'] );

		$this->assertSame(
			[
				array( 'Week 1', '1–4 Oct' ),
				array( 'Week 2', '5–11 Oct' ),
				array( 'Week 3', '12–18 Oct' ),
				array( 'Week 4', '19–25 Oct' ),
				array( 'Week 5', '26–31 Oct' ),
			],
			$month['labels']
		);

		$this->assertSame( '1–4 Oct', $month['titles'][0] );

		// A period the screen gives as running up to the start of the next
		// one has no sixth week with one second in it.
		$this->assertCount( 5, $this->cut( '2026-10-01 00:00:00', '2026-11-01 00:00:00' )['keys'] );
	}

	/**
	 * The week starts where the site says it does.
	 */
	public function test_weeks_start_on_the_sites_first_day(): void {
		$month = $this->cut( '2026-10-01 00:00:00', '2026-10-31 23:59:59', 'UTC', 0 );

		$this->assertSame( '2026-09-27', $month['keys'][0] );
		$this->assertSame( array( 'Week 1', '1–3 Oct' ), $month['labels'][0] );

		$thursday = new DateTimeImmutable( '2026-10-01 10:00:00', new DateTimeZone( 'UTC' ) );

		$this->assertSame( '2026-09-28', Buckets::key( $thursday, Interval::Week, 1 ) );
		$this->assertSame( '2026-09-27', Buckets::key( $thursday, Interval::Week, 0 ) );
		$this->assertSame( '2026-09-26', Buckets::key( $thursday, Interval::Week, 6 ) );
	}

	/**
	 * A quarter is weeks named by the day they start; half a year is too
	 * many weeks, and is months.
	 */
	public function test_a_quarter_is_weeks_and_half_a_year_is_months(): void {
		$quarter = $this->cut( '2026-07-01 00:00:00', '2026-09-30 23:59:59' );

		$this->assertSame( Interval::Week, $quarter['size'] );
		$this->assertCount( 14, $quarter['keys'] );

		// The first week by the first day of the period, not by a Monday
		// in June nobody asked about.
		$this->assertSame( '1 Jul', $quarter['labels'][0] );
		$this->assertSame( '6 Jul', $quarter['labels'][1] );
		$this->assertSame( '1–5 Jul', $quarter['titles'][0] );
		$this->assertSame( '27 Jul – 2 Aug', $quarter['titles'][4] );

		$half = $this->cut( '2026-01-01 00:00:00', '2026-06-30 23:59:59' );

		$this->assertSame( Interval::Month, $half['size'] );
		$this->assertSame( array( 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun' ), $half['labels'] );
	}

	/**
	 * A year is its months, and says the year when it crosses one.
	 */
	public function test_a_year_is_months(): void {
		$year = $this->cut( '2026-01-01 00:00:00', '2027-01-01 00:00:00' );

		$this->assertSame( Interval::Month, $year['size'] );
		$this->assertCount( 12, $year['keys'] );
		$this->assertSame( 'Jan', $year['labels'][0] );
		$this->assertSame( 'Dec', $year['labels'][11] );
		$this->assertSame( '2026-01', $year['keys'][0] );
		$this->assertSame( 'January 2026', $year['titles'][0] );

		$across = $this->cut( '2025-11-17 00:00:00', '2026-11-16 23:59:59' );

		$this->assertSame( Interval::Month, $across['size'] );
		$this->assertCount( 13, $across['keys'] );
		$this->assertSame( 'Nov 2025', $across['labels'][0] );
		$this->assertSame( 'Jan 2026', $across['labels'][2] );
	}

	/**
	 * Longer than a couple of years is quarters, and longer still is years.
	 */
	public function test_several_years_are_quarters_then_years(): void {
		$three = $this->cut( '2024-01-01 00:00:00', '2026-12-31 23:59:59' );

		$this->assertSame( Interval::Quarter, $three['size'] );
		$this->assertCount( 12, $three['keys'] );
		$this->assertSame( 'Q1 2024', $three['labels'][0] );
		$this->assertSame( '2024-Q1', $three['keys'][0] );
		$this->assertSame( 'Q4 2026', $three['labels'][11] );

		$ten = $this->cut( '2017-01-01 00:00:00', '2026-12-31 23:59:59' );

		$this->assertSame( Interval::Year, $ten['size'] );
		$this->assertSame( '2017', $ten['labels'][0] );
		$this->assertCount( 10, $ten['keys'] );
	}

	/**
	 * The day the clocks go forward has twenty-three hours, and the hour
	 * that does not happen is not on the axis.
	 */
	public function test_the_day_the_clocks_go_forward(): void {
		// Sunday 29 March 2026 in London: 1am becomes 2am.
		$day = $this->cut( '2026-03-29 00:00:00', '2026-03-29 22:59:59', 'Europe/London' );

		$this->assertSame( Interval::Hour, $day['size'] );
		$this->assertCount( 23, $day['keys'] );
		$this->assertNotContains( '2026-03-29 01', $day['keys'] );
		$this->assertSame( array( '12am', '2am', '3am' ), array_slice( $day['labels'], 0, 3 ) );
	}

	/**
	 * The day the clocks go back has an hour twice, which is one hour on
	 * the axis.
	 */
	public function test_the_day_the_clocks_go_back(): void {
		// Sunday 25 October 2026 in London: 2am becomes 1am again.
		$day = $this->cut( '2026-10-24 23:00:00', '2026-10-25 23:59:59', 'Europe/London' );

		$this->assertCount( 24, $day['keys'] );
		$this->assertSame( array_unique( $day['keys'] ), $day['keys'] );
		$this->assertSame( '2026-10-25 23', end( $day['keys'] ) );
	}

	/**
	 * A week with a change of clocks in it is still seven days, each once.
	 */
	public function test_days_are_not_skipped_or_doubled_across_a_change_of_clocks(): void {
		// Friday 27 March to Thursday 2 April 2026, as London has them.
		$week = $this->cut( '2026-03-27 00:00:00', '2026-04-02 22:59:59', 'Europe/London' );

		$this->assertSame(
			array( '2026-03-27', '2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31', '2026-04-01', '2026-04-02' ),
			$week['keys']
		);
	}

	/**
	 * The days are the site's. A period that is one day in UTC is parts of
	 * two somewhere else.
	 */
	public function test_buckets_are_in_the_sites_timezone(): void {
		// The 9th to the 11th of June as Berlin has them, two hours ahead.
		$days = $this->cut( '2026-06-08 22:00:00', '2026-06-11 21:59:59', 'Europe/Berlin' );

		$this->assertSame( array( '2026-06-09', '2026-06-10', '2026-06-11' ), $days['keys'] );

		// Half past eleven at night UTC is the next day there.
		$late = Site::local( '2026-06-10 23:30:00', new DateTimeZone( 'Europe/Berlin' ) );

		$this->assertSame( '2026-06-11', Buckets::key( $late, Interval::Day ) );
		$this->assertSame( '2026-06-11 01', Buckets::key( $late, Interval::Hour ) );
		$this->assertSame( '2026-06', Buckets::key( $late, Interval::Month ) );
		$this->assertSame( '2026-Q2', Buckets::key( $late, Interval::Quarter ) );
		$this->assertSame( '2026', Buckets::key( $late, Interval::Year ) );
	}

	/**
	 * Another period can be cut the same way as this one, whatever its own
	 * length would have chosen, and every bucket says when it starts.
	 */
	public function test_a_size_can_be_forced_and_buckets_know_when_they_start(): void {
		$days = Buckets::for_range( Range::between( '2026-01-01 00:00:00', '2026-01-31 23:59:59' ), new DateTimeZone( 'UTC' ), 1, Interval::Day );

		$this->assertSame( Interval::Day, $days['size'] );
		$this->assertCount( 31, $days['keys'] );
		$this->assertCount( 31, $days['starts'] );
		$this->assertSame( strtotime( '2026-01-01 00:00:00 UTC' ), $days['starts'][0] );
		$this->assertSame( strtotime( '2026-01-31 00:00:00 UTC' ), $days['starts'][30] );
	}

	/**
	 * Spans read the way a date range is written: the axis's spans are
	 * Site::span() with every date given its year when there is one.
	 */
	public function test_spans(): void {
		$utc  = new DateTimeZone( 'UTC' );
		$day  = static fn( string $date ): DateTimeImmutable => new DateTimeImmutable( $date, $utc );
		$axis = array(
			'timezone'    => $utc,
			'repeat_year' => true,
		);

		$this->assertSame( '3–9 Nov', Site::span( $day( '2026-11-03' ), $day( '2026-11-09' ), false, $axis ) );
		$this->assertSame( '28 Oct – 3 Nov', Site::span( $day( '2026-10-28' ), $day( '2026-11-03' ), false, $axis ) );
		$this->assertSame( '9 Nov', Site::span( $day( '2026-11-09' ), $day( '2026-11-09' ), false, $axis ) );
		$this->assertSame( '29 Dec 2025 – 4 Jan 2026', Site::span( $day( '2025-12-29' ), $day( '2026-01-04' ), true, $axis ) );
		$this->assertSame( '28 Oct 2026 – 3 Nov 2026', Site::span( $day( '2026-10-28' ), $day( '2026-11-03' ), true, $axis ) );
	}

	/**
	 * A period across a new year says the year of every date in a week's
	 * title, even when both ends of that week are in the same one.
	 */
	public function test_weeks_across_a_new_year_say_every_year(): void {
		$weeks = $this->cut( '2025-10-27 00:00:00', '2026-01-11 23:59:59' );

		$this->assertSame( Interval::Week, $weeks['size'] );
		$this->assertSame( '27 Oct 2025 – 2 Nov 2025', $weeks['titles'][0] );
		$this->assertSame( '3–9 Nov 2025', $weeks['titles'][1] );
		$this->assertSame( '29 Dec 2025 – 4 Jan 2026', $weeks['titles'][9] );
	}

	/**
	 * Something that is not a date is no moment, and a slot that is not one
	 * is now: a screen that left the date out meant the present.
	 */
	public function test_an_unreadable_moment(): void {
		$berlin = new DateTimeZone( 'Europe/Berlin' );

		$this->assertNull( Site::local( 'not a date', $berlin ) );
		$this->assertNull( Site::local( '0000-00-00 00:00:00', $berlin ) );

		Clock::freeze( '2026-06-10 12:00:00' );

		try {
			$now = Buckets::slot_time( 'banana:13ch', $berlin );

			$this->assertSame( '2026-06-10 14:00:00', $now->format( 'Y-m-d H:i:s' ) );
			$this->assertSame( 'Europe/Berlin', $now->getTimezone()->getName() );
		} finally {
			Clock::reset();
		}
	}

	/**
	 * The zone and the first day of the week are the site's when nobody
	 * says.
	 */
	public function test_the_sites_zone_and_week_are_the_defaults(): void {
		$GLOBALS['du_options']['timezone']      = 'Pacific/Auckland';
		$GLOBALS['du_options']['start_of_week'] = 0;

		// Thursday 1 October to Saturday 31 October, as Auckland has them
		// (thirteen hours ahead in October).
		$month = Buckets::for_range( Range::between( '2026-09-30 11:00:00', '2026-10-31 11:00:00' ) );

		$this->assertSame( Interval::Week, $month['size'] );
		$this->assertSame( '2026-09-27', $month['keys'][0], 'the Sunday the week starts on' );
		$this->assertSame( array( 'Week 1', '1–3 Oct' ), $month['labels'][0] );
		$this->assertCount( 5, $month['keys'], 'ending at Auckland\'s midnight is not a sixth week' );
	}

	/**
	 * The size on its own, for cutting two periods the same way.
	 */
	public function test_size_for_says_what_for_range_would_choose(): void {
		$utc = new DateTimeZone( 'UTC' );

		$this->assertSame( Interval::Hour, Buckets::size_for( Range::between( '2026-11-16 00:00:00', '2026-11-17 00:00:00' ), $utc ) );
		$this->assertSame( Interval::Day, Buckets::size_for( Range::between( '2026-11-02 00:00:00', '2026-11-16 00:00:00' ), $utc ) );
		$this->assertSame( Interval::Week, Buckets::size_for( Range::between( '2026-11-01 00:00:00', '2026-11-16 00:00:00' ), $utc ) );
		$this->assertSame( Interval::Month, Buckets::size_for( Range::between( '2026-01-01 00:00:00', '2027-01-01 00:00:00' ), $utc ) );
		$this->assertSame( Interval::Quarter, Buckets::size_for( Range::between( '2024-01-01 00:00:00', '2027-01-01 00:00:00' ), $utc ) );
		$this->assertSame( Interval::Year, Buckets::size_for( Range::between( '2017-01-01 00:00:00', '2027-01-01 00:00:00' ), $utc ) );

		// A day in UTC that is parts of two in Auckland is still hours;
		// what changes is which hours.
		$this->assertSame( Interval::Hour, Buckets::size_for( Range::between( '2026-11-16 00:00:00', '2026-11-17 00:00:00' ), new DateTimeZone( 'Pacific/Auckland' ) ) );
	}

	/**
	 * A period handed over backwards is its start and nothing else.
	 */
	public function test_a_range_that_ends_before_it_starts_is_one_bucket(): void {
		$utc   = new DateTimeZone( 'UTC' );
		$range = new Range( new DateTimeImmutable( '2026-11-16 12:00:00', $utc ), new DateTimeImmutable( '2026-11-10 12:00:00', $utc ) );

		$this->assertSame( array( '2026-11-16 12' ), Buckets::for_range( $range, $utc )['keys'] );
	}

	/**
	 * A thousand years is a short loop.
	 */
	public function test_there_is_a_most(): void {
		$long = Buckets::for_range( Range::between( '1026-01-01 00:00:00', '2026-01-01 00:00:00' ), new DateTimeZone( 'UTC' ), 1, Interval::Day );

		$this->assertCount( 400, $long['keys'] );
	}

	/**
	 * Rows grouped by the first characters of a stored UTC datetime: the
	 * hour is enough unless the zone sits part-way through UTC's hours.
	 */
	public function test_slots_are_hours_unless_the_zone_is_off_the_hour(): void {
		$range = Range::between( '2026-06-01 00:00:00', '2026-07-01 00:00:00' );

		$this->assertSame( 13, Buckets::slot_length( $range, new DateTimeZone( 'UTC' ) ) );
		$this->assertSame( 13, Buckets::slot_length( $range, new DateTimeZone( 'America/New_York' ) ) );
		$this->assertSame( 16, Buckets::slot_length( $range, new DateTimeZone( 'Asia/Kolkata' ) ) );
		$this->assertSame( 16, Buckets::slot_length( $range, new DateTimeZone( 'Asia/Kathmandu' ) ) );
		$this->assertSame( 16, Buckets::slot_length( $range, new DateTimeZone( 'America/St_Johns' ) ) );

		// The site's, when nobody says.
		$GLOBALS['du_options']['timezone'] = 'Asia/Kolkata';

		$this->assertSame( 16, Buckets::slot_length( $range ) );
	}

	/**
	 * A slot is the moment it started, read in the zone.
	 */
	public function test_a_slot_is_read_back_as_a_moment(): void {
		$york = new DateTimeZone( 'America/New_York' );

		$this->assertSame( '2026-06-10 17:00:00', Buckets::slot_time( '2026-06-10 21', $york )->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-06-11 02:45:00', Buckets::slot_time( '2026-06-10 21:00', new DateTimeZone( 'Asia/Kathmandu' ) )->format( 'Y-m-d H:i:s' ) );

		// Half past eleven at night in New York on the 15th is the 16th in
		// UTC, and it is counted on the 15th.
		$this->assertSame( '2026-06-15', Buckets::key( Buckets::slot_time( '2026-06-16 03', $york ), Interval::Day ) );
	}

	/**
	 * Names come through wp_date(), which is where WordPress translates
	 * them and where the zone is applied once and not by hand.
	 */
	public function test_names_are_said_in_the_buckets_own_zone(): void {
		// Midnight on Monday 16 November in Auckland is Sunday morning in UTC.
		$day = Buckets::for_range( Range::between( '2026-11-15 11:00:00', '2026-11-16 11:00:00' ), new DateTimeZone( 'Pacific/Auckland' ) );

		$this->assertCount( 24, $day['keys'] );
		$this->assertSame( '2026-11-16 00', $day['keys'][0] );
		$this->assertSame( 'Mon 16 Nov, 12am', $day['titles'][0] );
		$this->assertSame( '2026-11-16 23', end( $day['keys'] ) );
	}
}
