<?php
/**
 * Columns of Unix seconds, from the WHERE to the filled chart.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Models\Interval;
use SugarCommerce\Dates\Models\Range;

/**
 * SQLite's strftime() reads a bare number as a Julian day, so a column of
 * Unix integers grouped like a text one lands every bucket thousands of
 * years ago -- and compared with the strings where() binds, every integer
 * sorts before every string. Neither is an error. These run the real
 * expressions against a real table of integers.
 */
final class EpochTest extends TestCase {

	/**
	 * Unix seconds in, the same keys out.
	 */
	public function test_an_epoch_column_is_read_as_a_date_time_first(): void {
		$this->assertSame( "strftime('%Y-%m-%d', datetime(date_created, 'unixepoch'))", Interval::Day->group_expression( 'date_created', 'sqlite', true ) );
		$this->assertSame( "DATE_FORMAT(FROM_UNIXTIME(date_created), '%Y-%m')", Interval::Month->group_expression( 'date_created', 'mysql', true ) );
		$this->assertSame(
			"CONCAT(YEAR(FROM_UNIXTIME(created)), '-', LPAD((QUARTER(FROM_UNIXTIME(created)) - 1) * 3 + 1, 2, '0'))",
			Interval::Quarter->group_expression( 'created', 'MySQL', true )
		);

		// A text column is untouched.
		$this->assertSame( "strftime('%Y-%m-%d', date_created)", Interval::Day->group_expression( 'date_created' ) );
	}

	/**
	 * An unknown driver is refused with or without the flag.
	 */
	public function test_an_unknown_driver_is_refused_for_an_epoch_column(): void {
		$this->expectException( \InvalidArgumentException::class );

		Interval::Day->group_expression( 'created', 'postgres', true );
	}

	/**
	 * The column is still checked before anything is built round it.
	 */
	public function test_an_unsafe_epoch_column_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Interval::Day->group_expression( 'created) --', 'sqlite', true );
	}

	/**
	 * The WHERE binds integers, under the same names where() uses.
	 */
	public function test_an_epoch_range_binds_integers(): void {
		$range = Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' );

		[ $sql, $bindings ] = $range->where_epoch( 'o.date_created', 'r_' );

		$this->assertSame( 'o.date_created >= :r_from AND o.date_created < :r_to', $sql );
		$this->assertSame(
			array(
				'r_from' => strtotime( '2026-05-01 UTC' ),
				'r_to'   => strtotime( '2026-06-01 UTC' ),
			),
			$bindings
		);
	}

	/**
	 * An unsafe prefix is refused for an epoch column too.
	 */
	public function test_an_unsafe_prefix_is_refused_for_epoch_columns_too(): void {
		$this->expectException( \InvalidArgumentException::class );

		Range::between( '2026-05-01', '2026-06-01' )->where_epoch( 'created_at', '1=1 --' );
	}

	/**
	 * For $wpdb: `%d`, and the two moments as integers.
	 */
	public function test_a_prepared_epoch_range_uses_integer_placeholders(): void {
		$range = Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' );

		$this->assertSame(
			array( 'created >= %d AND created < %d', array( strtotime( '2026-05-01 UTC' ), strtotime( '2026-06-01 UTC' ) ) ),
			$range->where_prepared( 'created', true )
		);
		$this->assertSame(
			array( 'created >= %s AND created < %s', array( '2026-05-01 00:00:00', '2026-06-01 00:00:00' ) ),
			$range->where_prepared( 'created' )
		);
	}

	/**
	 * The series query groups and binds as Unix time.
	 */
	public function test_an_epoch_series_query_groups_and_binds_as_unix(): void {
		[ $sql, $bindings ] = Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' )
			->series_query( 'orders', 'date_created', Interval::Day, epoch: true );

		$this->assertStringContainsString( "strftime('%Y-%m-%d', datetime(date_created, 'unixepoch')) AS bucket", $sql );
		$this->assertSame( strtotime( '2026-05-01 UTC' ), $bindings['from'] );
		$this->assertSame( strtotime( '2026-06-01 UTC' ), $bindings['to'] );
	}

	/**
	 * Every grain.
	 *
	 * @return array<string, array{0: Interval, 1: array<int, string>, 2: array<int, int>}>
	 */
	public static function grains(): array {
		return array(
			'day'     => array( Interval::Day, array( '2026-07-13', '2026-07-14', '2026-07-15' ), array( 2, 0, 1 ) ),
			'week'    => array( Interval::Week, array( '2026-W29' ), array( 3 ) ),
			'month'   => array( Interval::Month, array( '2026-07' ), array( 3 ) ),
			'quarter' => array( Interval::Quarter, array( '2026-07' ), array( 3 ) ),
			'year'    => array( Interval::Year, array( '2026' ), array( 3 ) ),
		);
	}

	/**
	 * The point of the flag: keys from a real query on an integer column
	 * are the keys fill() looks for, at every grain.
	 *
	 * @param Interval           $interval The grain.
	 * @param array<int, string> $keys     The buckets fill() gives.
	 * @param array<int, int>    $counts   What each holds.
	 */
	#[DataProvider( 'grains' )]
	public function test_an_epoch_series_fills_from_a_real_query( Interval $interval, array $keys, array $counts ): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'pdo_sqlite is not loaded.' );
		}

		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->exec( 'CREATE TABLE orders ( date_created INTEGER )' );

		// The first of July is outside the three days asked for.
		foreach ( array( '2026-07-13 09:00', '2026-07-13 18:00', '2026-07-15 01:00', '2026-07-01 00:00' ) as $at ) {
			$pdo->exec( 'INSERT INTO orders VALUES ( ' . strtotime( $at . ' UTC' ) . ' )' );
		}

		$range = Range::last_days( 3, Clock::frozen( '2026-07-15 12:00:00' ) );

		[ $sql, $bindings ] = $range->series_query( 'orders', 'date_created', $interval, epoch: true );

		$statement = $pdo->prepare( $sql );
		$statement->execute( $bindings );

		$rows   = array_column( $statement->fetchAll( \PDO::FETCH_ASSOC ), 'count', 'bucket' );
		$filled = $range->fill( array_map( 'intval', $rows ), $interval );

		$this->assertSame( $keys, array_column( $filled, 'key' ) );
		$this->assertSame( $counts, array_column( $filled, 'value' ) );
	}
}
