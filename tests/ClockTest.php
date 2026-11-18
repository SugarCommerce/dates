<?php
/**
 * The clock: one passed in, and the one everybody reads.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Preset;
use SugarCommerce\Dates\Range;
use SugarCommerce\Dates\Site;
use SugarCommerce\Dates\Timestamp;

/**
 * Class ClockTest
 */
final class ClockTest extends TestCase {

	/**
	 * 2027-01-15 08:00:00 UTC.
	 */
	private const AT = 1_800_000_000;

	/**
	 * Every test starts on the real clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Clock::reset();
		du_reset_globals();
	}

	/**
	 * And leaves it there.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Clock::reset();
	}

	/**
	 * Left alone it is time().
	 */
	public function test_left_alone_it_is_the_real_clock(): void {
		$this->assertFalse( ( new Clock() )->is_frozen() );
		$this->assertEqualsWithDelta( time(), Clock::time(), 1 );
		$this->assertEqualsWithDelta( time(), ( new Clock() )->now(), 1 );
	}

	/**
	 * Frozen, it stays put until it is reset.
	 */
	public function test_frozen_it_stays_put(): void {
		Clock::freeze( self::AT );

		$this->assertTrue( ( new Clock() )->is_frozen() );
		$this->assertSame( self::AT, Clock::time() );
		$this->assertSame( self::AT, ( new Clock() )->now() );

		Clock::reset();

		$this->assertFalse( ( new Clock() )->is_frozen() );
		$this->assertEqualsWithDelta( time(), Clock::time(), 1 );
	}

	/**
	 * A date string freezes it too, read as UTC unless it says otherwise.
	 */
	public function test_it_freezes_at_a_date(): void {
		Clock::freeze( '2027-01-15 08:00:00' );
		$this->assertSame( self::AT, Clock::time() );

		Clock::freeze( '2027-01-15T09:00:00+01:00' );
		$this->assertSame( self::AT, Clock::time() );

		$this->assertSame( self::AT, Clock::frozen( '2027-01-15T03:00:00-05:00' )->now() );
	}

	/**
	 * A date it cannot read is refused, not taken as now or as 1970.
	 */
	public function test_a_date_it_cannot_read_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Clock::freeze( '0000-00-00 00:00:00' );
	}

	/**
	 * Nor is a frozen clock built from one.
	 */
	public function test_a_frozen_clock_refuses_nonsense(): void {
		$this->expectException( \InvalidArgumentException::class );

		Clock::frozen( 'not a time' );
	}

	/**
	 * Advanced, it moves and stops there: from a frozen clock or a running one.
	 */
	public function test_advanced_it_moves_and_stops(): void {
		Clock::freeze( self::AT );
		Clock::advance( 61 );
		$this->assertSame( self::AT + 61, Clock::time() );

		Clock::advance( -1 );
		$this->assertSame( self::AT + 60, Clock::time() );

		Clock::reset();
		Clock::advance( 3600 );
		$this->assertTrue( ( new Clock() )->is_frozen() );
		$this->assertEqualsWithDelta( time() + 3600, Clock::time(), 1 );
	}

	/**
	 * Another source is asked every time, so it can move.
	 */
	public function test_another_clock_can_be_read(): void {
		$at = self::AT;

		Clock::use(
			static function () use ( &$at ): int {
				return $at;
			}
		);

		$this->assertSame( self::AT, Clock::time() );

		$at += 5;

		$this->assertSame( self::AT + 5, Clock::time() );
	}

	/**
	 * A source that reads the real clock does not ask itself forever.
	 */
	public function test_a_source_reading_the_real_clock_does_not_loop(): void {
		Clock::use( static fn(): int => Clock::time() + 10 );

		$this->assertEqualsWithDelta( time() + 10, Clock::time(), 1 );
		$this->assertEqualsWithDelta( time() + 10, Clock::time(), 1 );
	}

	/**
	 * A clock given a moment of its own wins over a frozen real one.
	 */
	public function test_a_clock_of_its_own_wins(): void {
		Clock::freeze( self::AT );

		$own = Clock::frozen( self::AT + 999 );

		$this->assertSame( self::AT + 999, $own->now() );
		$this->assertTrue( $own->is_frozen() );

		Clock::reset();

		$this->assertTrue( $own->is_frozen() );
		$this->assertSame( self::AT + 999, $own->now() );
	}

	/**
	 * The moment, in UTC and on a zone's clock: the site's unless told.
	 */
	public function test_utc_and_local(): void {
		$clock = Clock::frozen( self::AT );

		$this->assertSame( '2027-01-15 08:00:00 UTC', $clock->utc()->format( 'Y-m-d H:i:s T' ) );

		// The stubbed site is in London, which is on UTC in January.
		$this->assertSame( 'Europe/London', $clock->local()->getTimezone()->getName() );
		$this->assertSame( '2027-01-15 08:00', $clock->local()->format( 'Y-m-d H:i' ) );
		$this->assertSame( '2027-01-15 19:00', $clock->local( new DateTimeZone( 'Australia/Sydney' ) )->format( 'Y-m-d H:i' ) );
		$this->assertSame( self::AT, $clock->local( new DateTimeZone( 'Australia/Sydney' ) )->getTimestamp() );
	}

	/**
	 * The point of freezing the real clock: code handed no Clock reads it.
	 */
	public function test_everything_handed_no_clock_reads_the_real_one(): void {
		Clock::freeze( '2026-07-15 12:00:00' );

		$this->assertSame( '2026-07-15 12:00:00', Timestamp::now() );
		$this->assertTrue( Timestamp::is_past( '2026-07-15 11:59:59' ) );
		$this->assertFalse( Timestamp::is_past( '2026-07-15 12:00:01' ) );
		$this->assertTrue( Timestamp::is_future( '2026-07-15 12:00:01' ) );
		$this->assertSame( array( '2026-07-15 00:00:00', '2026-07-16 00:00:00' ), Preset::Today->range()->to_array() );
		$this->assertSame( array( '2026-07-13 00:00:00', '2026-07-16 00:00:00' ), Range::last_days( 3 )->to_array() );
		$this->assertSame( '3 hours ago', Site::relative( '2026-07-15 09:00:00' ) );
	}
}
