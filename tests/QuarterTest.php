<?php
/**
 * Quarters, from the SQL that groups them to the chart that shows them.
 *
 * @package SugarCommerce\Dates
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use SugarCommerce\Dates\Models\Interval;
use SugarCommerce\Dates\Models\Range;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Interval::Quarter once grouped by month in SQL (`%Y-%m`) while fill()
 * looked each quarter up by its first month only -- so the second and third
 * months of every quarter were counted by the database and then dropped on
 * the way to the chart. These run the real expression in a real database
 * and pass what it returns straight to fill(), which is what a caller does.
 */
final class QuarterTest extends TestCase {

	/**
	 * One order a month for the first half of 2026, by amount.
	 *
	 * @var array<string, int>
	 */
	private const ORDERS = array(
		'2026-01-15 10:00:00' => 1,
		'2026-02-15 10:00:00' => 2,
		'2026-03-31 23:59:59' => 4,
		'2026-04-01 00:00:00' => 8,
		'2026-05-15 10:00:00' => 16,
		'2026-06-30 23:59:59' => 32,
	);

	public function test_the_key_of_every_month_in_a_quarter_is_the_quarters_own(): void {
		$utc = new \DateTimeZone( 'UTC' );

		foreach ( array( '2026-01-15', '2026-02-15', '2026-03-31' ) as $day ) {
			$this->assertSame( '2026-01', Interval::Quarter->key( Interval::Quarter->floor( new \DateTimeImmutable( $day, $utc ) ) ) );
		}
	}

	public function test_sqlite_groups_a_quarter_under_the_key_fill_looks_up(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'pdo_sqlite is not loaded.' );
		}

		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->exec( 'CREATE TABLE orders ( created_at TEXT, total INTEGER )' );

		$insert = $pdo->prepare( 'INSERT INTO orders VALUES ( ?, ? )' );

		foreach ( self::ORDERS as $at => $total ) {
			$insert->execute( array( $at, $total ) );
		}

		$range          = Range::between( '2026-01-01 00:00:00', '2026-07-01 00:00:00' );
		[ $sql, $bind ] = $range->series_query( 'orders', 'created_at', Interval::Quarter, array( 'SUM(total) AS revenue' ) );

		$statement = $pdo->prepare( $sql );
		$statement->execute( $bind );

		$rows = array();

		foreach ( $statement->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {
			$rows[ $row['bucket'] ] = (int) $row['revenue'];
		}

		$this->assertSame(
			array(
				'2026-01' => 7,
				'2026-04' => 56,
			),
			$rows,
			'one row a quarter, keyed by its first month'
		);

		$filled = $range->fill( $rows, Interval::Quarter );

		$this->assertSame( array( 7, 56 ), array_column( $filled, 'value' ), 'nothing from months two and three is lost' );
		$this->assertSame( array( 'Q1 2026', 'Q2 2026' ), array_column( $filled, 'label' ) );
	}

	/**
	 * What MySQL's expression answers, worked out here the way MySQL does.
	 *
	 * No MySQL in a unit suite, so the expression is checked as text and its
	 * arithmetic -- QUARTER() is ceil( month / 3 ) -- is done in PHP.
	 */
	#[DataProvider( 'months' )]
	public function test_mysql_arithmetic_lands_on_the_first_month( int $month, string $expected ): void {
		$quarter = (int) ceil( $month / 3 );

		$this->assertSame( $expected, '2026-' . str_pad( (string) ( ( $quarter - 1 ) * 3 + 1 ), 2, '0', STR_PAD_LEFT ) );
	}

	/**
	 * Every month, and the key its quarter has.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function months(): array {
		$months = array();

		foreach ( range( 1, 12 ) as $month ) {
			$months[ 'month ' . $month ] = array( $month, '2026-' . array( '01', '04', '07', '10' )[ intdiv( $month - 1, 3 ) ] );
		}

		return $months;
	}

	public function test_the_mysql_expression_asks_for_the_quarter_and_not_the_month(): void {
		$this->assertSame(
			"CONCAT(YEAR(created_at), '-', LPAD((QUARTER(created_at) - 1) * 3 + 1, 2, '0'))",
			Interval::Quarter->group_expression( 'created_at', 'mysql' )
		);
	}

	public function test_other_grains_are_grouped_as_they_were(): void {
		$this->assertSame( "DATE_FORMAT(created_at, '%Y-%m')", Interval::Month->group_expression( 'created_at', 'mysql' ) );
		$this->assertSame( "strftime('%Y-%m-%d', created_at)", Interval::Day->group_expression( 'created_at' ) );
	}

	public function test_a_column_name_with_a_newline_after_it_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Interval::Day->group_expression( "created_at\n" );
	}
}
