<?php
/**
 * A period asked for by API: its schema, and reading it strictly.
 *
 * @package SugarCommerce\Dates
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Models\Preset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * A screen falls back quietly when a period is wrong; a client that asked
 * for the wrong thing is told, with a code that says which part was wrong.
 */
final class PresetInputTest extends TestCase {

	/**
	 * The schema offers every slug, and dates as YYYY-MM-DD.
	 */
	public function test_the_schema_offers_every_slug_and_a_date_pattern(): void {
		$schema = Preset::input_schema();

		$this->assertSame( array( 'period', 'start', 'end' ), array_keys( $schema ) );
		$this->assertSame( array_keys( Preset::options() ), $schema['period']['enum'] );
		$this->assertSame( '^\d{4}-\d{2}-\d{2}$', $schema['start']['pattern'] );
		$this->assertSame( 1, preg_match( '/' . $schema['end']['pattern'] . '/', '2026-05-31' ) );
		$this->assertSame( 0, preg_match( '/' . $schema['end']['pattern'] . '/', '2026-5-31' ) );
		$this->assertStringContainsString( 'Last 30 days', $schema['period']['description'] );
	}

	/**
	 * The fallback is named in the description, and wording can be replaced.
	 */
	public function test_the_schema_wording_can_be_replaced(): void {
		$schema = Preset::input_schema( 'this_month', array( 'start' => 'From.' ) );

		$this->assertStringContainsString( 'This month', $schema['period']['description'] );
		$this->assertSame( 'From.', $schema['start']['description'] );
		$this->assertStringContainsString( 'YYYY-MM-DD', $schema['end']['description'] );
	}

	/**
	 * Nothing asked for is the fallback.
	 */
	public function test_nothing_asked_for_is_the_fallback(): void {
		$this->assertSame( array( 'preset' => 'last_30', 'start' => '', 'end' => '' ), Preset::parse_input( array() ) );
		$this->assertSame( 'this_year', Preset::parse_input( array( 'period' => '' ), Preset::ThisYear )['preset'] );
		$this->assertSame( 'last_week', Preset::parse_input( array( 'start' => '  ' ), 'last_week' )['preset'] );
	}

	/**
	 * A named period is read the way a key is, then checked.
	 */
	public function test_a_named_period_is_read(): void {
		$this->assertSame( array( 'preset' => 'last_90', 'start' => '', 'end' => '' ), Preset::parse_input( array( 'period' => ' Last_90 ' ) ) );
		$this->assertSame( 'all_time', Preset::parse_input( array( 'period' => 'all_time' ) )['preset'] );
	}

	/**
	 * Two dates are a period of the caller's own, ready for resolve().
	 */
	public function test_two_dates_are_a_custom_period(): void {
		$asked = Preset::parse_input( array( 'start' => ' 2026-05-01', 'end' => '2026-05-31 ' ) );

		$this->assertSame( array( 'preset' => 'custom', 'start' => '2026-05-01', 'end' => '2026-05-31' ), $asked );

		// The whole of the last day is counted.
		$range = Preset::resolve( $asked['preset'], $asked['start'], $asked['end'] );
		$this->assertSame( '2026-05-01 00:00:00', $range->start() );
		$this->assertSame( '2026-06-01 00:00:00', $range->end() );

		// One day is a period too.
		$this->assertSame( 'custom', Preset::parse_input( array( 'start' => '2026-05-01', 'end' => '2026-05-01' ) )['preset'] );
	}

	/**
	 * A named period resolves the same as the screen's.
	 */
	public function test_a_named_period_resolves_the_same_as_the_screens(): void {
		$clock = Clock::frozen( '2026-07-15 12:00:00' );
		$asked = Preset::parse_input( array( 'period' => 'last_7' ) );

		$this->assertEquals(
			Preset::Last7Days->range( $clock ),
			Preset::resolve( $asked['preset'], $asked['start'], $asked['end'], $clock )
		);
	}

	/**
	 * What is wrong, and which code says so.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
	 */
	public static function wrong(): array {
		return array(
			'an unknown period'          => array( array( 'period' => 'last_31' ), 'myplugin_bad_period', 'Use one of: today, yesterday' ),
			'custom is not a slug'       => array( array( 'period' => 'custom' ), 'myplugin_bad_period', 'not a period' ),
			'a period and dates'         => array( array( 'period' => 'last_7', 'start' => '2026-05-01', 'end' => '2026-05-02' ), 'myplugin_bad_period', 'not both' ),
			'a period and only an end'   => array( array( 'period' => 'last_7', 'end' => '2026-05-02' ), 'myplugin_bad_period', 'not both' ),
			'a start and no end'         => array( array( 'start' => '2026-05-01' ), 'myplugin_bad_date', 'real date' ),
			'an end and no start'        => array( array( 'end' => '2026-05-01' ), 'myplugin_bad_date', 'real date' ),
			'a day that is not real'     => array( array( 'start' => '2026-02-30', 'end' => '2026-03-02' ), 'myplugin_bad_date', 'real date' ),
			'a date written another way' => array( array( 'start' => '2026-5-1', 'end' => '2026-05-02' ), 'myplugin_bad_date', 'real date' ),
			'words for a date'           => array( array( 'start' => 'yesterday', 'end' => 'today' ), 'myplugin_bad_date', 'real date' ),
			'a start after its end'      => array( array( 'start' => '2026-05-02', 'end' => '2026-05-01' ), 'myplugin_bad_date', 'after the end' ),
			'a list for a period'        => array( array( 'period' => array( 'x' ), 'start' => '2026-05-01' ), 'myplugin_bad_date', 'real date' ),
		);
	}

	/**
	 * A client that asked for the wrong thing is told what, with a 400.
	 *
	 * @param array<string, mixed> $input   The input.
	 * @param string               $code    The error code.
	 * @param string               $message Part of the message.
	 */
	#[DataProvider( 'wrong' )]
	public function test_a_wrong_period_is_refused( array $input, string $code, string $message ): void {
		$error = Preset::parse_input( $input, Preset::Last30Days, 'myplugin' );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( $code, $error->get_error_code() );
		$this->assertStringContainsString( $message, $error->get_error_message() );
		$this->assertSame( array( 'status' => 400 ), $error->get_error_data() );
	}

	/**
	 * The error codes have a prefix of their own unless told.
	 */
	public function test_the_codes_have_a_default_prefix(): void {
		$this->assertSame( 'dates_bad_period', Preset::parse_input( array( 'period' => 'nope' ) )->get_error_code() );
	}

	/**
	 * A fallback that is not a preset is a programming error, said loudly.
	 */
	public function test_a_fallback_that_is_not_a_preset_throws(): void {
		$this->expectException( \ValueError::class );
		Preset::parse_input( array(), 'last_31' );
	}
}
