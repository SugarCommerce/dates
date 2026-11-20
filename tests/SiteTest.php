<?php
/**
 * Site timezone and format tests.
 *
 * @package SugarCommerce\Dates
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Site;
use PHPUnit\Framework\TestCase;

/**
 * Everything else in this library works in UTC, which is the only sane way to
 * store a moment. This is the layer that turns one into what a person in the
 * admin should see, and it is where the WordPress-specific mistakes live.
 *
 * A site has a timezone that is not the server's, and formats an administrator
 * chose. Reading either from PHP's defaults gives the wrong answer on most
 * installs, and the symptom is an order that appears to have been placed an
 * hour before it was.
 */
final class SiteTest extends TestCase {

	/**
	 * Put the stubbed site back.
	 */
	protected function setUp(): void {
		du_reset_globals();
	}

	/**
	 * And again.
	 */
	protected function tearDown(): void {
		du_reset_globals();
	}

	/**
	 * A stored UTC value is shown in the site's timezone.
	 *
	 * Midsummer in London is BST, an hour ahead of UTC. A site reading the
	 * value as UTC would show 12:00 for an order placed at 13:00 local.
	 */
	public function test_a_stored_value_is_shown_in_the_site_timezone(): void {
		$this->assertSame( '2026-06-15 13:00', Site::format( '2026-06-15 12:00:00', 'Y-m-d H:i' ) );

		// And in winter, when London is on UTC, the same value is 12:00.
		$this->assertSame( '2026-01-15 12:00', Site::format( '2026-01-15 12:00:00', 'Y-m-d H:i' ) );
	}

	/**
	 * A different site timezone gives a different answer for the same value.
	 */
	public function test_the_timezone_comes_from_the_site(): void {
		$GLOBALS['du_options']['timezone'] = 'Australia/Sydney';

		$this->assertSame( '2026-06-15 22:00', Site::format( '2026-06-15 12:00:00', 'Y-m-d H:i' ) );

		$GLOBALS['du_options']['timezone'] = 'America/New_York';

		$this->assertSame( '2026-06-15 08:00', Site::format( '2026-06-15 12:00:00', 'Y-m-d H:i' ) );
	}

	/**
	 * With no format given, the site's own is used.
	 */
	public function test_the_format_comes_from_the_site(): void {
		$this->assertSame( 'June 15, 2026', Site::format( '2026-06-15 12:00:00' ) );

		$GLOBALS['du_options']['date_format'] = 'd/m/Y';

		$this->assertSame( '15/06/2026', Site::format( '2026-06-15 12:00:00' ) );
	}

	/**
	 * Date and time together use both of the site's formats.
	 */
	public function test_date_and_time_use_both_site_formats(): void {
		$this->assertSame( 'June 15, 2026 1:00 pm', Site::format_datetime( '2026-06-15 12:00:00' ) );

		$GLOBALS['du_options']['time_format'] = 'H:i';

		$this->assertSame( 'June 15, 2026 13:00', Site::format_datetime( '2026-06-15 12:00:00' ) );
	}

	/**
	 * A value that cannot be read gives an empty string, not a date.
	 *
	 * The failure that matters: a null or malformed column rendered through
	 * PHP's own date handling becomes 1st January 1970, which looks like data
	 * rather than an absence.
	 */
	public function test_an_unreadable_value_gives_nothing(): void {
		foreach ( array( '', 'not a date', '0000-00-00 00:00:00' ) as $bad ) {
			$this->assertSame( '', Site::format( $bad ), sprintf( '"%s" rendered as a date.', $bad ) );
			$this->assertNull( Site::local( $bad ) );
		}
	}

	/**
	 * A moment goes into a date-and-time input in the site's time, and
	 * comes back out as the same UTC moment.
	 */
	public function test_a_moment_round_trips_through_an_input_in_site_time(): void {
		$this->assertSame( '2026-06-15T13:00', Site::input( '2026-06-15 12:00:00' ) );
		$this->assertSame( '2026-06-15 12:00:00', Site::from_input( '2026-06-15T13:00' ) );
		$this->assertSame( '2026-01-15 12:00:00', Site::from_input( '2026-01-15 12:00' ) );
		$this->assertSame( '2026-06-15 12:00:30', Site::from_input( '2026-06-15T13:00:30' ) );

		$GLOBALS['du_options']['timezone'] = 'Australia/Sydney';

		$this->assertSame( '2026-06-15 22:00:00', Site::from_input( '2026-06-16T08:00' ) );
		$this->assertSame( '2026-06-16T08:00', Site::input( '2026-06-15 22:00:00' ) );
	}

	/**
	 * Nothing, or something that is not a real moment, is the empty answer.
	 */
	public function test_an_input_that_is_not_a_moment_is_empty(): void {
		foreach ( array( '', 'banana', '2026-02-30T09:00', '2026-06-15T24:00', '2026-06-15T09:60', '2026-06-15', 12345, null, array( '2026-06-15T09:00' ) ) as $bad ) {
			$this->assertSame( '0000-00-00 00:00:00', Site::from_input( $bad ), var_export( $bad, true ) );
			$this->assertNull( Site::from_input( $bad, null ) );
		}

		$this->assertSame( '', Site::input( '0000-00-00 00:00:00' ) );
		$this->assertSame( '', Site::input( '' ) );
	}

	/**
	 * A local value carries the site's timezone, not UTC.
	 */
	public function test_a_local_value_carries_the_site_timezone(): void {
		$local = Site::local( '2026-06-15 12:00:00' );

		$this->assertNotNull( $local );
		$this->assertSame( 'Europe/London', $local->getTimezone()->getName() );
		$this->assertSame( 3600, $local->getOffset() );
	}

	/**
	 * A relative phrase comes with the exact moment for its title.
	 *
	 * What a list table wants: something to read, and the real timestamp on
	 * hover for anyone who needs it.
	 */
	public function test_a_relative_phrase_comes_with_the_exact_moment(): void {
		$clock = Clock::frozen( '2026-06-15 15:00:00' );
		$pair  = Site::relative_with_title( '2026-06-15 12:00:00', $clock );

		$this->assertSame( array( 'text', 'title' ), array_keys( $pair ) );
		$this->assertNotSame( '', $pair['text'] );
		$this->assertSame( 'June 15, 2026 1:00 pm', $pair['title'] );

		// The title is the site's rendering, so it is not the raw stored value.
		$this->assertStringNotContainsString( '2026-06-15 12:00:00', $pair['title'] );
	}
}
