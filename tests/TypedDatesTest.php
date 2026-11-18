<?php
/**
 * The zero date, typed dates, and what a bare string is read as.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     2.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Timestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypedDatesTest extends TestCase {

	/**
	 * Every spelling of the zero date is no date, everywhere it is asked.
	 *
	 * Only the exact `0000-00-00 00:00:00` used to be. The date column's
	 * `0000-00-00` was read by PHP as 30 November of the year minus one --
	 * a real moment, long past -- so "never expires" was "expired".
	 *
	 * @param string $zero A zero date.
	 */
	#[DataProvider( 'zero_dates' )]
	public function test_the_zero_date_is_no_date( string $zero ): void {
		$this->assertNull( Timestamp::parse( $zero ) );
		$this->assertNull( Timestamp::to_unix( $zero ) );
		$this->assertNull( Timestamp::to_sql( $zero ) );
		$this->assertSame( '', Timestamp::format( $zero ) );
		$this->assertSame( '', Timestamp::iso( $zero ) );
		$this->assertTrue( Timestamp::is_empty( $zero ) );
		$this->assertFalse( Timestamp::is_past( $zero ), 'No date has not passed.' );
		$this->assertFalse( Timestamp::is_future( $zero ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function zero_dates(): array {
		return array(
			'datetime'       => array( '0000-00-00 00:00:00' ),
			'date'           => array( '0000-00-00' ),
			'with fractions' => array( '0000-00-00 00:00:00.000000' ),
			'iso'            => array( '0000-00-00T00:00:00+00:00' ),
			'padded'         => array( '  0000-00-00 00:00:00 ' ),
		);
	}

	/**
	 * A year that merely starts with noughts is a date.
	 */
	public function test_only_the_zero_date_is_the_zero_date(): void {
		$this->assertNotNull( Timestamp::parse( '2000-01-01' ) );
		$this->assertNotNull( Timestamp::parse( '0001-01-01 00:00:00' ) );
	}

	/**
	 * A string with no zone on it is UTC, whatever PHP's default zone is.
	 *
	 * The difference from strtotime(), which reads it in whatever
	 * date_default_timezone_set() was last given -- so one plugin setting
	 * that moved every expiry by the offset.
	 */
	public function test_a_bare_string_is_utc_whatever_the_default_zone(): void {
		$before = date_default_timezone_get();

		try {
			foreach ( array( 'UTC', 'America/New_York', 'Pacific/Auckland', 'Asia/Kolkata' ) as $zone ) {
				date_default_timezone_set( $zone ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- The point of the test.

				$this->assertSame( 1784125800, Timestamp::to_unix( '2026-07-15 14:30:00' ), $zone );
				$this->assertSame( 1784073600, Timestamp::to_unix( '2026-07-15' ), $zone );
				$this->assertSame( '2026-07-15 14:30:00', Timestamp::to_sql( '2026-07-15 14:30:00' ), $zone );
				$this->assertSame( '2026-07-15T14:30:00+00:00', Timestamp::iso( '2026-07-15 14:30:00' ), $zone );
				$this->assertSame( 1784073600, Timestamp::from_date( '2026-07-15' ), $zone );
				$this->assertTrue( Timestamp::is_past( '2026-07-15 14:30:00', Clock::frozen( 1784125801 ) ), $zone );
				$this->assertFalse( Timestamp::is_past( '2026-07-15 14:30:00', Clock::frozen( 1784125800 ) ), $zone );
			}
		} finally {
			date_default_timezone_set( $before ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Put back.
		}
	}

	/**
	 * A string that says its own zone keeps it.
	 */
	public function test_a_string_with_its_own_offset_keeps_it(): void {
		$this->assertSame( 1784125800, Timestamp::to_unix( '2026-07-15T15:30:00+01:00' ) );
		$this->assertSame( 1784125800, Timestamp::to_unix( '2026-07-15 10:30:00 America/New_York' ) );
	}

	/**
	 * An integer is a Unix timestamp where a method takes anything; digits
	 * in a string never are.
	 */
	public function test_what_an_integer_means(): void {
		// to_sql() and is_empty() take anything: an integer is a timestamp.
		$this->assertSame( '2026-07-15 14:30:00', Timestamp::to_sql( 1784125800 ) );
		$this->assertFalse( Timestamp::is_empty( 1784125800 ) );
		$this->assertFalse( Timestamp::is_empty( 1 ) );

		// Nought and below are "no date", not 1970.
		$this->assertNull( Timestamp::to_sql( 0 ) );
		$this->assertNull( Timestamp::to_sql( -1 ) );
		$this->assertTrue( Timestamp::is_empty( 0 ) );
		$this->assertTrue( Timestamp::is_empty( -1 ) );

		// at() takes a timestamp and nothing else, so it formats the epoch.
		$this->assertSame( '1970-01-01 00:00:00', Timestamp::at( 0 ) );

		// The same digits as a string are not a timestamp: they are not
		// read at all, so nothing that was stored as a number by mistake is
		// shown as a date somebody has to disbelieve.
		$this->assertNull( Timestamp::parse( '1784125800' ) );
		$this->assertNull( Timestamp::to_sql( '1784125800' ) );
		$this->assertFalse( Timestamp::is_empty( '1784125800' ), 'Not empty: wrong.' );

		// Eight digits are a date.
		$this->assertSame( '2026-07-15 00:00:00', Timestamp::to_sql( '20260715' ) );

		// A float is neither.
		$this->assertNull( Timestamp::to_sql( 1784125800.0 ) );
		$this->assertTrue( Timestamp::is_empty( 1784125800.5 ) );
	}

	/**
	 * A typed date is midnight in the zone asked for.
	 */
	public function test_from_date_is_midnight_in_a_zone(): void {
		$this->assertSame( 1784073600, Timestamp::from_date( '2026-07-15' ) );
		$this->assertSame( 1784073600, Timestamp::from_date( "  2026-07-15\n" ), 'Surrounding space is ignored.' );
		$this->assertSame( 1784073600 - 3600, Timestamp::from_date( '2026-07-15', 'Europe/Dublin' ) );
		$this->assertSame( 1784073600 + ( 4 * 3600 ), Timestamp::from_date( '2026-07-15', 'America/New_York' ) );
		$this->assertSame( 1784073600, Timestamp::from_date( '2026-07-15', 'Not/AZone' ), 'An unknown zone is UTC.' );
	}

	/**
	 * A date that is not on the calendar, or is not written as one, is no
	 * date -- never a different day.
	 *
	 * @param string $typed What was typed.
	 */
	#[DataProvider( 'not_dates' )]
	public function test_from_date_refuses_what_is_not_a_calendar_date( string $typed ): void {
		$this->assertNull( Timestamp::from_date( $typed ) );
		$this->assertNull( Timestamp::end_of_day( $typed ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function not_dates(): array {
		return array(
			'30 February'        => array( '2026-02-30' ),
			'29 Feb, not a leap' => array( '2026-02-29' ),
			'month 13'           => array( '2026-13-01' ),
			'day 0'              => array( '2026-07-00' ),
			'the zero date'      => array( '0000-00-00' ),
			'with a time'        => array( '2026-07-15 14:30:00' ),
			'unpadded'           => array( '2026-7-5' ),
			'a phrase'           => array( 'tomorrow' ),
			'the other order'    => array( '15-07-2026' ),
			'slashes'            => array( '2026/07/15' ),
			'empty'              => array( '' ),
			'more after it'      => array( "2026-07-15\n2026-07-16" ),
		);
	}

	/**
	 * 29 February exists when the year has one.
	 */
	public function test_from_date_knows_a_leap_year(): void {
		$this->assertNotNull( Timestamp::from_date( '2028-02-29' ) );
	}

	/**
	 * The end of a day is its last second, on a day of any length.
	 */
	public function test_end_of_day_is_the_last_second(): void {
		$this->assertSame( 1784073600 + 86399, Timestamp::end_of_day( '2026-07-15' ) );
		$this->assertSame( '2026-07-15 23:59:59', Timestamp::at( (int) Timestamp::end_of_day( '2026-07-15' ) ) );

		// Clocks go forward in New York on 8 March 2026: 23 hours.
		$start = (int) Timestamp::from_date( '2026-03-08', 'America/New_York' );
		$this->assertSame( $start + ( 23 * 3600 ) - 1, Timestamp::end_of_day( '2026-03-08', 'America/New_York' ) );

		// And back on 1 November: 25.
		$start = (int) Timestamp::from_date( '2026-11-01', 'America/New_York' );
		$this->assertSame( $start + ( 25 * 3600 ) - 1, Timestamp::end_of_day( '2026-11-01', 'America/New_York' ) );
		$this->assertSame( '23:59:59', Timestamp::at( (int) Timestamp::end_of_day( '2026-11-01', 'America/New_York' ), 'H:i:s', 'America/New_York' ) );
	}

	/**
	 * A typed date straight to what a column stores, at either edge.
	 */
	public function test_date_to_sql(): void {
		$this->assertSame( '2026-07-15 00:00:00', Timestamp::date_to_sql( '2026-07-15' ) );
		$this->assertSame( '2026-07-15 00:00:00', Timestamp::date_to_sql( ' 2026-07-15 ', 'start' ) );
		$this->assertSame( '2026-07-15 23:59:59', Timestamp::date_to_sql( '2026-07-15', 'end' ) );
		$this->assertSame( '2026-07-15 00:00:00', Timestamp::date_to_sql( '2026-07-15', 'sideways' ), 'An edge that is not one is the start.' );

		// A day somewhere else, stored in UTC all the same.
		$this->assertSame( '2026-07-15 04:00:00', Timestamp::date_to_sql( '2026-07-15', 'start', Timestamp::ZERO, 'America/New_York' ) );
		$this->assertSame( '2026-07-16 03:59:59', Timestamp::date_to_sql( '2026-07-15', 'end', Timestamp::ZERO, 'America/New_York' ) );

		// The day the clocks go back there is twenty-five hours long.
		$this->assertSame( '2026-11-02 04:59:59', Timestamp::date_to_sql( '2026-11-01', 'end', Timestamp::ZERO, 'America/New_York' ) );
	}

	/**
	 * What is not a day is the empty value, whichever edge was asked for.
	 */
	public function test_date_to_sql_answers_no_date_with_the_empty_value(): void {
		foreach ( array( '', '2026-02-30', '2026-07-15 10:00:00', 'tomorrow', '0000-00-00', null, false, 1784125800, 1.5, array( '2026-07-15' ), "2026-07-15\n2026-07-16" ) as $bad ) {
			$this->assertSame( Timestamp::ZERO, Timestamp::date_to_sql( $bad ), var_export( $bad, true ) );
			$this->assertSame( Timestamp::ZERO, Timestamp::date_to_sql( $bad, 'end' ), var_export( $bad, true ) );
			$this->assertSame( '', Timestamp::date_to_sql( $bad, 'end', '' ), var_export( $bad, true ) );
		}
	}

	/**
	 * A date in a language, and the plain one when the language is unknown.
	 */
	public function test_format_localized(): void {
		if ( class_exists( \IntlDateFormatter::class ) ) {
			$this->assertSame( '15. Juli 2026', Timestamp::format_localized( 1784125800, 'de-DE' ) );
			$this->assertSame( '15 July 2026', Timestamp::format_localized( 1784125800, 'en_GB' ) );

			// 23:30 UTC on the 15th is already the 16th in Auckland.
			$this->assertSame( '16 July 2026', Timestamp::format_localized( 1784158200, 'en_GB', 'Pacific/Auckland' ) );
		}

		$this->assertNotSame( '', Timestamp::format_localized( 1784125800, 'not a locale at all !!' ) );
	}
}
