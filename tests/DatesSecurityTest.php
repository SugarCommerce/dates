<?php
/**
 * Untrusted input reaching the date helpers.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\{Clock, Interval, Preset, Range, Relative, Timestamp};

/**
 * A dashboard's `?from=`, `?to=` and `?days=` arrive from the request,
 * and anything built into SQL must not be steerable by them.
 */
final class DatesSecurityTest extends TestCase {

	private function clock(): Clock {
		return Clock::frozen( '2026-07-15 14:30:00' );
	}

	private function range(): Range {
		return Range::between( '2026-05-01 00:00:00', '2026-06-01 00:00:00' );
	}

	/**
	 * The prefix is spliced into the SQL text as part of a placeholder
	 * name, and was not checked at all.
	 */
	#[DataProvider( 'hostile_prefixes' )]
	public function test_an_unsafe_placeholder_prefix_is_refused( string $prefix ): void {
		$this->expectException( InvalidArgumentException::class );

		$this->range()->where( 'created_at', $prefix );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function hostile_prefixes(): array {
		return array(
			'injection' => array( 'x OR 1=1; --' ),
			'space'     => array( 'a b' ),
			'colon'     => array( 'a:' ),
			'newline'   => array( "a\n" ),
		);
	}

	public function test_an_unsafe_prefix_is_refused_for_epoch_columns_too(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->range()->where_epoch( 'created_at', '1=1 --' );
	}

	public function test_an_ordinary_prefix_still_works(): void {
		[ $sql, $bindings ] = $this->range()->where( 'created_at', 'prev_2_' );

		$this->assertSame( 'created_at >= :prev_2_from AND created_at < :prev_2_to', $sql );
		$this->assertSame( array( 'prev_2_from', 'prev_2_to' ), array_keys( $bindings ) );
	}

	/**
	 * `$` matches before a trailing newline, so "created_at\n" passed the
	 * identifier check.
	 */
	public function test_a_trailing_newline_is_not_an_identifier(): void {
		foreach ( array(
			fn() => $this->range()->where( "created_at\n" ),
			fn() => Interval::Day->group_expression( "created_at\n" ),
			fn() => $this->range()->series_query( "orders\n", 'created_at', Interval::Day ),
		) as $call ) {
			try {
				$call();
				$this->fail( 'A trailing newline was accepted as an identifier.' );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * Custom dates the wrong way round made a range ending before it
	 * started: an empty chart, and a "previous period" that ran forwards.
	 */
	public function test_reversed_custom_dates_are_put_in_order(): void {
		$range = Preset::resolve( 'custom', '2026-05-31', '2026-05-01', $this->clock() );

		$this->assertSame( array( '2026-05-01 00:00:00', '2026-06-01 00:00:00' ), $range->to_array() );
		$this->assertCount( 31, $range->fill( array(), Interval::Day ) );
		$this->assertLessThan( $range->from, $range->previous()->from );
	}

	/**
	 * `?days=9223372036854775807` overflowed inside modify() and wrapped
	 * to a start date millions of years in the future.
	 */
	public function test_an_absurd_day_count_is_clamped(): void {
		$range = Range::last_days( PHP_INT_MAX, $this->clock() );

		$this->assertLessThan( $range->to, $range->from );
		$this->assertSame( Range::MAX_DAYS, $range->days() );
	}

	/**
	 * PHP reads MySQL's zero DATE as a real day in year -1, so a "never
	 * expires" column read as long expired.
	 */
	#[DataProvider( 'zero_dates' )]
	public function test_every_spelling_of_the_zero_date_is_no_date( string $value ): void {
		$this->assertNull( Timestamp::parse( $value ) );
		$this->assertFalse( Timestamp::is_past( $value, $this->clock() ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function zero_dates(): array {
		return array(
			'date'     => array( '0000-00-00' ),
			'datetime' => array( '0000-00-00 00:00:00' ),
			'iso'      => array( '0000-00-00T00:00:00Z' ),
		);
	}

	/**
	 * A stored value at DateTime's extreme made `now - then` overflow to a
	 * float, which phrase() refused with a TypeError.
	 */
	public function test_an_extreme_timestamp_does_not_fatal(): void {
		$this->assertSame( '292471208677 years ago', Relative::format( '@-9223372036854775807', $this->clock(), 0 ) );
		$this->assertStringStartsWith( 'in ', Relative::format( '@9223372036854775807', Clock::frozen( -9223372036854775807 ), 0 ) );
	}
}
