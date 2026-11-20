<?php
/**
 * Zones and dates that arrive from a request.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\Timestamp;

/**
 * A zone name is often a visitor's own -- a profile field, `?tz=` -- and
 * an unknown one is documented to read as UTC. Nothing a visitor sends may
 * turn that into a fatal error.
 */
final class HostileInputTest extends TestCase {

	private const int NOW = 1784125800;

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function hostile_zones(): array {
		return array(
			'null byte'         => array( "UTC\0Europe/Dublin" ),
			'trailing null'     => array( "Europe/London\0" ),
			'unknown'           => array( 'Not/AZone' ),
			'path'              => array( '../../etc/passwd' ),
			'offset past range' => array( '+99:99' ),
		);
	}

	/**
	 * A null byte made DateTimeZone throw ValueError, which is not an
	 * Exception, so it passed every catch here: an uncaught fatal error
	 * where an unknown zone reads as UTC.
	 *
	 * @param string $zone A zone name nobody should have sent.
	 */
	#[DataProvider( 'hostile_zones' )]
	public function test_a_hostile_zone_reads_as_utc( string $zone ): void {
		$this->assertSame( '2026-07-15 14:30', Timestamp::at( self::NOW, 'Y-m-d H:i', $zone ) );
		$this->assertSame( '2026-07-15 14:30', Timestamp::format( '2026-07-15 14:30:00', 'Y-m-d H:i', $zone ) );
		$this->assertSame( 1784073600, Timestamp::from_date( '2026-07-15', $zone ) );
		$this->assertSame( 1784159999, Timestamp::end_of_day( '2026-07-15', $zone ) );
		$this->assertSame( '2026-07-15 00:00:00', Timestamp::date_to_sql( '2026-07-15', 'start', Timestamp::ZERO, $zone ) );
		$this->assertStringContainsString( '2026', Timestamp::format_localized( self::NOW, 'en_GB', $zone ) );
	}
}
