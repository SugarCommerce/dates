<?php
/**
 * Weeks, from the SQL that groups them to the key a chart looks them up by.
 *
 * @package SugarCommerce\Dates
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use SugarCommerce\Dates\Models\Interval;
use PHPUnit\Framework\TestCase;

/**
 * Interval::Week grouped by SQLite's `%W` -- weeks counted from the year's
 * first Monday, starting at 00 -- while key() is the ISO week. The two
 * agree only in years that begin on a Monday, so in most years a chart
 * filled from SQLite looked each week up under a name the database had not
 * used. This runs the real expression in a real database against key().
 */
final class WeekTest extends TestCase {

	public function test_sqlite_names_a_week_as_key_does_on_every_day_of_six_years(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'pdo_sqlite is not loaded.' );
		}

		$pdo  = new \PDO( 'sqlite::memory:' );
		$utc  = new \DateTimeZone( 'UTC' );
		$read = $pdo->prepare( 'SELECT ' . Interval::Week->group_expression( 'd' ) . ' FROM ( SELECT ? AS d )' );

		// 2024 to 2029: a leap year, a 53-week year (2026), and years that
		// begin on each of several weekdays, with every year boundary.
		$day  = new \DateTimeImmutable( '2024-01-01 12:00:00', $utc );
		$last = new \DateTimeImmutable( '2029-12-31 12:00:00', $utc );
		$seen = 0;

		while ( $day <= $last ) {
			$read->execute( array( $day->format( 'Y-m-d H:i:s' ) ) );

			$this->assertSame(
				Interval::Week->key( $day ),
				(string) $read->fetchColumn(),
				$day->format( 'Y-m-d' )
			);

			$day = $day->modify( '+1 day' );
			++$seen;
		}

		$this->assertGreaterThan( 2000, $seen );
	}

	public function test_the_days_that_belong_to_another_years_week(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'pdo_sqlite is not loaded.' );
		}

		$pdo  = new \PDO( 'sqlite::memory:' );
		$read = $pdo->prepare( 'SELECT ' . Interval::Week->group_expression( 'd' ) . ' FROM ( SELECT ? AS d )' );

		$cases = array(
			'2027-01-01 00:00:00' => '2026-W53', // A Friday in the last week of 2026.
			'2024-12-30 09:00:00' => '2025-W01', // A Monday already in 2025's first week.
			'2026-01-01 00:00:00' => '2026-W01',
			'2026-12-31 23:59:59' => '2026-W53',
		);

		foreach ( $cases as $at => $week ) {
			$read->execute( array( $at ) );

			$this->assertSame( $week, (string) $read->fetchColumn(), $at );
		}
	}

	public function test_mysql_asks_for_the_iso_week(): void {
		$this->assertSame( "DATE_FORMAT(d, '%x-W%v')", Interval::Week->group_expression( 'd', 'mysql' ) );
	}
}
