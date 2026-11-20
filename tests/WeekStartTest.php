<?php
/**
 * The day a week starts on, worked out in one place.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\Buckets;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Models\Interval;
use SugarCommerce\Dates\Models\Preset;
use SugarCommerce\Dates\Site;

/**
 * Interval::Week->floor() is what Preset, Buckets and Range all cut weeks
 * with, so the week a period says it starts and the week a chart draws are
 * the same week.
 */
final class WeekStartTest extends TestCase {

	/**
	 * Put the stubbed options back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		du_reset_globals();
	}

	/**
	 * Every day of one week, against every first day of the week.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function days(): array {
		// Wednesday 11 November 2026.
		return array(
			'monday weeks, a wednesday'   => array( '2026-11-11 15:30:00', 1, '2026-11-09' ),
			'monday weeks, a monday'      => array( '2026-11-09 00:00:00', 1, '2026-11-09' ),
			'monday weeks, a sunday'      => array( '2026-11-15 23:59:59', 1, '2026-11-09' ),
			'sunday weeks, a wednesday'   => array( '2026-11-11 15:30:00', 0, '2026-11-08' ),
			'sunday weeks, a sunday'      => array( '2026-11-15 10:00:00', 0, '2026-11-15' ),
			'sunday weeks, a saturday'    => array( '2026-11-14 10:00:00', 0, '2026-11-08' ),
			'saturday weeks, a friday'    => array( '2026-11-13 10:00:00', 6, '2026-11-07' ),
			'saturday weeks, a saturday'  => array( '2026-11-14 10:00:00', 6, '2026-11-14' ),
			'seven is sunday'             => array( '2026-11-11 15:30:00', 7, '2026-11-08' ),
			'minus one is saturday'       => array( '2026-11-11 15:30:00', -1, '2026-11-07' ),
			'sixteen is tuesday'          => array( '2026-11-11 15:30:00', 16, '2026-11-10' ),
			'minus twelve is tuesday'     => array( '2026-11-09 15:30:00', -12, '2026-11-03' ),
			'across a year, monday weeks' => array( '2027-01-01 12:00:00', 1, '2026-12-28' ),
		);
	}

	/**
	 * The floor of a week is midnight on its first day.
	 *
	 * @param string $at          The moment.
	 * @param int    $week_starts The first day of the week.
	 * @param string $expected    The first day of its week.
	 */
	#[DataProvider( 'days' )]
	public function test_a_week_floors_to_its_first_day( string $at, int $week_starts, string $expected ): void {
		$floor = Interval::Week->floor( new DateTimeImmutable( $at, new DateTimeZone( 'UTC' ) ), $week_starts );

		$this->assertSame( $expected . ' 00:00:00', $floor->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Left alone a week is ISO's: Monday.
	 */
	public function test_a_week_is_monday_to_monday_unless_told(): void {
		$sunday = new DateTimeImmutable( '2026-11-15 23:00:00', new DateTimeZone( 'UTC' ) );

		$this->assertSame( '2026-11-09', Interval::Week->floor( $sunday )->format( 'Y-m-d' ) );
	}

	/**
	 * Midnight in the date's own zone, through a change of clocks.
	 */
	public function test_a_week_floors_to_the_zones_midnight(): void {
		// New York goes back an hour on Sunday 1 November 2026.
		$tuesday = new DateTimeImmutable( '2026-11-03 09:00:00', new DateTimeZone( 'America/New_York' ) );
		$floor   = Interval::Week->floor( $tuesday, 0 );

		$this->assertSame( '2026-11-01 00:00:00 EDT', $floor->format( 'Y-m-d H:i:s T' ) );
	}

	/**
	 * The site's first day, or one given, held to 0-6.
	 */
	public function test_the_sites_first_day_of_the_week(): void {
		$this->assertSame( 1, Site::week_starts() );

		$GLOBALS['du_options']['start_of_week'] = '0';
		$this->assertSame( 0, Site::week_starts() );

		$this->assertSame( 3, Site::week_starts( 3 ) );
		$this->assertSame( 0, Site::week_starts( 7 ) );
		$this->assertSame( 6, Site::week_starts( -1 ) );
		$this->assertSame( 2, Site::week_starts( 16 ) );
	}

	/**
	 * A preset's week and a chart's week are the same week.
	 */
	public function test_a_presets_week_is_the_charts_week(): void {
		$utc   = new DateTimeZone( 'UTC' );
		$clock = Clock::frozen( '2026-11-11 15:30:00' );

		foreach ( array( 0, 1, 6 ) as $starts ) {
			$week    = Preset::ThisWeek->range( $clock, $utc, $starts );
			$buckets = Buckets::for_range( $week, $utc, $starts, Interval::Week );

			$this->assertSame( array( $week->from->format( 'Y-m-d' ) ), $buckets['keys'], 'Week starting on day ' . $starts );
			$this->assertSame( 7, $week->days() );
		}
	}
}
