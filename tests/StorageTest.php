<?php
/**
 * Turning whatever code has into the datetime to store.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use SugarCommerce\Dates\Timestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase {

	/** Wednesday, 15 July 2026 at 14:30 UTC. */
	private const NOW = 1784125800;

	public function test_at_formats_a_unix_timestamp(): void {
		$this->assertSame( '2026-07-15 14:30:00', Timestamp::at( self::NOW ) );
		$this->assertSame( '15:30', Timestamp::at( self::NOW, 'H:i', 'Europe/Dublin' ) );
		$this->assertSame( '14:30', Timestamp::at( self::NOW, 'H:i', 'Not/AZone' ), 'An unknown zone is UTC.' );
	}

	/**
	 * @param mixed $value  The value.
	 * @param bool  $expect Whether it holds no date.
	 */
	#[DataProvider( 'emptiness' )]
	public function test_is_empty( mixed $value, bool $expect ): void {
		$this->assertSame( $expect, Timestamp::is_empty( $value ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function emptiness(): array {
		return [
			'null'               => [ null, true ],
			'empty string'       => [ '', true ],
			'spaces'             => [ '  ', true ],
			'zero datetime'      => [ '0000-00-00 00:00:00', true ],
			'zero date'          => [ '0000-00-00', true ],
			'nought'             => [ 0, true ],
			'false'              => [ false, true ],
			'an array'           => [ [ '2026-07-15' ], true ],
			'a datetime'         => [ '2026-07-15 14:30:00', false ],
			'a timestamp'        => [ self::NOW, false ],
			'a DateTime'         => [ new \DateTimeImmutable( '@0' ), false ],
			'not a date at all'  => [ 'banana', false ],
		];
	}

	public function test_to_sql_reads_a_string(): void {
		$this->assertSame( '2026-07-15 14:30:00', Timestamp::to_sql( '2026-07-15 14:30:00' ) );
		$this->assertSame( '2026-07-15 14:30:00', Timestamp::to_sql( ' 2026-07-15T15:30:00+01:00 ' ) );
		$this->assertSame( '2026-07-15 00:00:00', Timestamp::to_sql( '2026-07-15' ) );
		$this->assertSame( '2026-07-15 00:00:00', Timestamp::to_sql( '20260715' ), 'Digits in a string are a date, not a timestamp.' );
	}

	public function test_to_sql_reads_a_timestamp_and_a_datetime(): void {
		$this->assertSame( '2026-07-15 14:30:00', Timestamp::to_sql( self::NOW ) );

		$dublin = new \DateTime( '2026-07-15 15:30:00', new \DateTimeZone( 'Europe/Dublin' ) );

		$this->assertSame( '2026-07-15 14:30:00', Timestamp::to_sql( $dublin ) );
		$this->assertSame( '2026-07-15 14:30:00', Timestamp::to_sql( \DateTimeImmutable::createFromMutable( $dublin ) ) );
	}

	public function test_to_sql_is_null_for_no_date_and_for_rubbish(): void {
		foreach ( [ null, '', '0000-00-00 00:00:00', '0000-00-00', 0, -5, false, [ 'x' ], 'banana', 1.5 ] as $value ) {
			$this->assertNull( Timestamp::to_sql( $value ), var_export( $value, true ) );
		}

		$this->assertSame( Timestamp::ZERO, Timestamp::to_sql( '' ) ?? Timestamp::ZERO );
	}
}
