<?php
/**
 * What the site says: dates through wp_date(), spans, and relative time.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     2.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Relative;
use SugarCommerce\Dates\Site;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteWordsTest extends TestCase {

	/** Wednesday, 15 July 2026 at 14:30 UTC. */
	private const NOW = 1784125800;

	protected function setUp(): void {
		du_reset_globals();
	}

	protected function tearDown(): void {
		du_reset_globals();
	}

	/**
	 * A stored moment `$ago` seconds before NOW.
	 *
	 * @param int $ago Seconds; negative for the future.
	 */
	private static function stored( int $ago ): string {
		return gmdate( 'Y-m-d H:i:s', self::NOW - $ago );
	}

	/* ─── format ────────────────────────────────────────────────────── */

	/**
	 * The format's own idea of the zone is the site's, and the moment is
	 * the real one.
	 *
	 * What adding the offset to the timestamp and calling date_i18n() got
	 * wrong: the day and hour were right and `T`, `P` and `U` were not.
	 */
	public function test_format_knows_the_zone_and_the_moment(): void {
		$GLOBALS['du_options']['timezone'] = 'America/New_York';

		$this->assertSame( '2026-07-15 10:30 EDT -04:00', Site::format( '2026-07-15 14:30:00', 'Y-m-d H:i T P' ) );
		$this->assertSame( (string) self::NOW, Site::format( '2026-07-15 14:30:00', 'U' ) );
	}

	/**
	 * Late in the evening UTC is tomorrow in Auckland.
	 */
	public function test_format_crosses_the_date_line(): void {
		$GLOBALS['du_options']['timezone'] = 'Pacific/Auckland';

		$this->assertSame( 'July 16, 2026', Site::format( '2026-07-15 14:30:00' ) );
		$this->assertSame( 'July 16, 2026 2:30 am', Site::format_datetime( '2026-07-15 14:30:00' ) );
	}

	/**
	 * No date is nothing, in every spelling.
	 */
	public function test_no_date_is_nothing(): void {
		foreach ( array( '', '0000-00-00', '0000-00-00 00:00:00', 'banana' ) as $bad ) {
			$this->assertSame( '', Site::format( $bad ), $bad );
			$this->assertSame( '', Site::format_datetime( $bad ), $bad );
			$this->assertSame( '', Site::relative( $bad ), $bad );
			$this->assertSame( '', Site::span( $bad, '2026-07-15' ), $bad );
			$this->assertSame( '', Site::span( '2026-07-15', $bad ), $bad );
		}
	}

	/* ─── span ──────────────────────────────────────────────────────── */

	/**
	 * @param string $from     First day.
	 * @param string $to       Last day.
	 * @param bool   $year     With the year.
	 * @param array  $args     Options.
	 * @param string $expected What it says.
	 */
	#[DataProvider( 'spans' )]
	public function test_span( string $from, string $to, bool $year, array $args, string $expected ): void {
		$this->assertSame( $expected, Site::span( $from, $to, $year, $args + array( 'timezone' => new DateTimeZone( 'UTC' ) ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: array<string, mixed>, 4: string}>
	 */
	public static function spans(): array {
		$long   = array( 'month' => 'F' );
		$repeat = array( 'repeat_year' => true );

		return array(
			'one day'                   => array( '2026-11-09', '2026-11-09', true, array(), '9 Nov 2026' ),
			'one day, no year'          => array( '2026-11-09', '2026-11-09', false, array(), '9 Nov' ),
			'one month'                 => array( '2026-11-09', '2026-11-15', true, array(), '9–15 Nov 2026' ),
			'one month, no year'        => array( '2026-11-09', '2026-11-15', false, array(), '9–15 Nov' ),
			'two months'                => array( '2026-11-30', '2026-12-06', true, array(), '30 Nov – 6 Dec 2026' ),
			'two months, no year'       => array( '2026-11-30', '2026-12-06', false, array(), '30 Nov – 6 Dec' ),
			'two years'                 => array( '2025-12-29', '2026-01-04', true, array(), '29 Dec 2025 – 4 Jan 2026' ),
			'two years, no year'        => array( '2025-12-29', '2026-01-04', false, array(), '29 Dec – 4 Jan' ),
			'long months'               => array( '2026-11-09', '2026-11-15', true, $long, '9–15 November 2026' ),
			'long, two months'          => array( '2026-11-30', '2026-12-06', true, $long, '30 November – 6 December 2026' ),
			'long, two years'           => array( '2025-12-29', '2026-01-04', true, $long, '29 December 2025 – 4 January 2026' ),
			'long, one day'             => array( '2026-11-09', '2026-11-09', true, $long, '9 November 2026' ),
			'every date whole'          => array( '2026-10-28', '2026-11-03', true, $repeat, '28 Oct 2026 – 3 Nov 2026' ),
			'whole, one month'          => array( '2026-11-03', '2026-11-09', true, $repeat, '3–9 Nov 2026' ),
			'whole, but no year'        => array( '2026-10-28', '2026-11-03', false, $repeat, '28 Oct – 3 Nov' ),
			'stored datetimes'          => array( '2026-11-09 00:00:00', '2026-11-15 23:59:59', true, array(), '9–15 Nov 2026' ),
			'the wrong way round'       => array( '2026-11-15', '2026-11-09', true, array(), '9–15 Nov 2026' ),
			'same day number, two months' => array( '2026-10-09', '2026-11-09', true, array(), '9 Oct – 9 Nov 2026' ),
		);
	}

	/**
	 * Which day a moment is on is the site's to say, unless told.
	 *
	 * 23:30 UTC on the 14th is the 15th in Auckland and still the 14th in
	 * New York.
	 */
	public function test_span_stands_in_the_site_timezone(): void {
		$from = '2026-07-14 23:30:00';
		$to   = '2026-07-16 23:30:00';

		$GLOBALS['du_options']['timezone'] = 'Pacific/Auckland';
		$this->assertSame( '15–17 Jul 2026', Site::span( $from, $to ) );

		$GLOBALS['du_options']['timezone'] = 'America/New_York';
		$this->assertSame( '14–16 Jul 2026', Site::span( $from, $to ) );

		// And a zone passed in wins over the site's.
		$this->assertSame( '14–16 Jul 2026', Site::span( $from, $to, true, array( 'timezone' => new DateTimeZone( 'UTC' ) ) ) );
		$this->assertSame( '15–17 Jul 2026', Site::span( $from, $to, true, array( 'timezone' => new DateTimeZone( 'Pacific/Auckland' ) ) ) );
	}

	/**
	 * A DateTime is a moment like any other, whatever zone it came in.
	 */
	public function test_span_takes_datetimes(): void {
		$auckland = new DateTimeZone( 'Pacific/Auckland' );
		$from     = new DateTimeImmutable( '2026-07-15 08:00:00', $auckland );
		$to       = new \DateTime( '2026-07-20 08:00:00', $auckland );

		$this->assertSame( '15–20 Jul 2026', Site::span( $from, $to, true, array( 'timezone' => $auckland ) ) );

		// 08:00 in Auckland is the evening before in London.
		$this->assertSame( '14–19 Jul 2026', Site::span( $from, $to ) );
	}

	/* ─── relative ──────────────────────────────────────────────────── */

	/**
	 * With nothing asked for, relative() says what Relative::format() says.
	 *
	 * Lists that were already showing it must not change by a word.
	 *
	 * @param int $ago Seconds ago; negative for the future.
	 */
	#[DataProvider( 'deltas' )]
	public function test_relative_by_default_is_what_it_always_said( int $ago ): void {
		$clock = Clock::frozen( self::NOW );

		$this->assertSame( Relative::format( self::stored( $ago ), $clock ), Site::relative( self::stored( $ago ), $clock ), (string) $ago );
		$this->assertSame( Relative::format( self::stored( $ago ), $clock ), Site::relative_with_title( self::stored( $ago ), $clock )['text'], (string) $ago );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function deltas(): array {
		$deltas = array();

		foreach ( array( 0, 1, 44, 45, 59, 60, 61, 89, 90, 119, 120, 2700, 3570, 3599, 3600, 5400, 84600, 86399, 86400, 561600, 604799, 604800, 1123200, 2505600, 2591999, 2592000, 2592001, 2678400, 31449600, 31536000, 34560000 ) as $seconds ) {
			$deltas[ $seconds . 's ago' ] = array( $seconds );
			$deltas[ 'in ' . $seconds . 's' ] = array( -$seconds );
		}

		return $deltas;
	}

	/**
	 * Counted as WordPress counts: every unit, both directions.
	 *
	 * The expectations are human_time_diff()'s own answers (wp-includes/
	 * formatting.php), with "ago" after or "in" before.
	 *
	 * @param int    $ago      Seconds.
	 * @param string $expected The length, as human_time_diff() gives it.
	 */
	#[DataProvider( 'wordpress_lengths' )]
	public function test_relative_like_wordpress( int $ago, string $expected ): void {
		$clock = Clock::frozen( self::NOW );

		$this->assertSame( $expected . ' ago', Site::relative( self::stored( $ago ), $clock, Site::LIKE_WORDPRESS ) );
		$this->assertSame( 'in ' . $expected, Site::relative( self::stored( -$ago ), $clock, Site::LIKE_WORDPRESS ) );
		$this->assertSame( $expected, Site::duration( $ago, Site::LIKE_WORDPRESS ) );
		$this->assertSame( $expected, Site::duration( -$ago, Site::LIKE_WORDPRESS ), 'The sign of a length is ignored.' );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function wordpress_lengths(): array {
		return array(
			'1s'                => array( 1, '1 second' ),
			'2s'                => array( 2, '2 seconds' ),
			'59s'               => array( 59, '59 seconds' ),
			'60s'               => array( 60, '1 minute' ),
			'89s'               => array( 89, '1 minute' ),
			'90s rounds up'     => array( 90, '2 minutes' ),
			'45 min'            => array( 2700, '45 minutes' ),
			'59.5 min is 60'    => array( 3570, '60 minutes' ),
			'1 hour'            => array( 3600, '1 hour' ),
			'89 min'            => array( 5340, '1 hour' ),
			'90 min rounds up'  => array( 5400, '2 hours' ),
			'23.5 hours is 24'  => array( 84600, '24 hours' ),
			'1 day'             => array( 86400, '1 day' ),
			'6.5 days is 7'     => array( 561600, '7 days' ),
			'1 week'            => array( 604800, '1 week' ),
			'13 days is 2 weeks' => array( 1123200, '2 weeks' ),
			'29 days is 4 weeks' => array( 2505600, '4 weeks' ),
			'30 days'           => array( 2592000, '1 month' ),
			'31 days'           => array( 2678400, '1 month' ),
			'364 days is 12'    => array( 31449600, '12 months' ),
			'a year'            => array( 31536000, '1 year' ),
			'400 days'          => array( 34560000, '1 year' ),
			'18 months is 2 years' => array( 47304000, '2 years' ),
			'ten years'         => array( 315360000, '10 years' ),
		);
	}

	/**
	 * The two ways of counting disagree exactly where rounding does.
	 */
	public function test_whole_units_and_nearest_units_differ(): void {
		$clock = Clock::frozen( self::NOW );
		$whole = array( 'cutoff' => 0 );
		$near  = array(
			'cutoff' => 0,
			'round'  => true,
		);

		// Thirteen days: one whole week has passed, and it is nearer two.
		$this->assertSame( '1 week ago', Site::relative( self::stored( 1123200 ), $clock, $whole ) );
		$this->assertSame( '2 weeks ago', Site::relative( self::stored( 1123200 ), $clock, $near ) );

		// Ninety seconds.
		$this->assertSame( '1 minute ago', Site::relative( self::stored( 90 ), $clock, $whole ) );
		$this->assertSame( '2 minutes ago', Site::relative( self::stored( 90 ), $clock, $near ) );

		// Eighty-nine: both say one.
		$this->assertSame( '1 minute ago', Site::relative( self::stored( 89 ), $clock, $near ) );
	}

	/**
	 * Each option is its own switch.
	 */
	public function test_relative_options_are_independent(): void {
		$clock = Clock::frozen( self::NOW );

		// Past thirty days it is a date -- unless there is no cutoff.
		$this->assertSame( '14 Jun 2026', Site::relative( self::stored( 2678400 ), $clock ) );
		$this->assertSame( '1 month ago', Site::relative( self::stored( 2678400 ), $clock, array( 'cutoff' => 0 ) ) );
		$this->assertSame( '2026-06-14', Site::relative( self::stored( 2678400 ), $clock, array( 'fallback' => 'Y-m-d' ) ) );
		$this->assertSame( '15 Jul 2026', Site::relative( self::stored( 7200 ), $clock, array( 'cutoff' => 3600 ) ) );

		// Under a minute.
		$this->assertSame( 'just now', Site::relative( self::stored( 12 ), $clock ) );
		$this->assertSame( 'just now', Site::relative( self::stored( -12 ), $clock ) );
		$this->assertSame( '12 seconds ago', Site::relative( self::stored( 12 ), $clock, array( 'just_now' => false ) ) );
		$this->assertSame( 'in 12 seconds', Site::relative( self::stored( -12 ), $clock, array( 'just_now' => false ) ) );
		$this->assertSame( '1 second ago', Site::relative( self::stored( 0 ), $clock, array( 'just_now' => false ) ), 'Never nought of anything.' );

		// Minutes, short and long.
		$this->assertSame( '5 minutes ago', Site::relative( self::stored( 300 ), $clock ) );
		$this->assertSame( '5 mins ago', Site::relative( self::stored( 300 ), $clock, array( 'abbreviate' => true ) ) );
		$this->assertSame( 'in 1 min', Site::relative( self::stored( -60 ), $clock, array( 'abbreviate' => true ) ) );
		$this->assertSame( '3 hours ago', Site::relative( self::stored( 10800 ), $clock, array( 'abbreviate' => true ) ), 'Only the minute has a short form.' );
		$this->assertSame( '5 mins', Site::duration( 300, array( 'abbreviate' => true ) ) );
		$this->assertSame( '2 mins ago', Site::relative( self::stored( 90 ), $clock, Site::LIKE_WORDPRESS + array( 'abbreviate' => true ) ) );
	}

	/**
	 * The phrase as markup: the moment on hover, both halves escaped.
	 */
	public function test_relative_html(): void {
		$clock = Clock::frozen( self::NOW );

		// 11:30 UTC is 12:30 in London in July.
		$this->assertSame( '<span title="July 15, 2026 12:30 pm">3 hours ago</span>', Site::relative_html( self::stored( 10800 ), $clock ) );
		$this->assertSame( '<span title="July 2, 2026 3:30 pm">2 weeks ago</span>', Site::relative_html( self::stored( 1123200 ), $clock, Site::LIKE_WORDPRESS ) );

		// It is the pair relative_with_title() gives, and nothing else.
		$pair = Site::relative_with_title( self::stored( 10800 ), $clock );
		$this->assertSame( '<span title="' . $pair['title'] . '">' . $pair['text'] . '</span>', Site::relative_html( self::stored( 10800 ), $clock ) );

		// A format an administrator typed markup into does not get out.
		$GLOBALS['du_options']['date_format'] = '\\<\\b\\>"Y';
		$GLOBALS['du_options']['time_format'] = '';
		$this->assertSame( '<span title="&lt;b&gt;&quot;2026 ">3 hours ago</span>', Site::relative_html( self::stored( 10800 ), $clock ) );

		foreach ( array( '', '0000-00-00', 'banana' ) as $bad ) {
			$this->assertSame( '', Site::relative_html( $bad, $clock ), $bad );
		}
	}

	/**
	 * A length of time, in this library's own counting.
	 */
	public function test_duration_by_default(): void {
		$this->assertSame( 'less than a minute', Site::duration( 30 ) );
		$this->assertSame( '1 minute', Site::duration( 89 ) );
		$this->assertSame( '1 minute', Site::duration( 90 ) );
		$this->assertSame( '5 minutes', Site::duration( 300 ) );
		$this->assertSame( '1 week', Site::duration( 1123200 ) );
		$this->assertSame( '3 months', Site::duration( 8000000 ) );
		$this->assertSame( '2 years', Site::duration( 70000000 ) );
	}

	/**
	 * A phrase is a whole translatable string with its plural, per unit.
	 *
	 * Proved by translating: a stand-in for a language pack that knows only
	 * whole phrases, and whose word order is not English's.
	 */
	public function test_every_phrase_is_translated_whole(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Site.php' );

		foreach ( array( 'second', 'minute', 'min', 'hour', 'day', 'week', 'month', 'year' ) as $unit ) {
			$this->assertStringContainsString( "_n( '%s {$unit} ago', '%s {$unit}s ago', \$count, 'sugarcommerce' )", $source );
			$this->assertStringContainsString( "_n( 'in %s {$unit}', 'in %s {$unit}s', \$count, 'sugarcommerce' )", $source );
			$this->assertStringContainsString( "_n( '%s {$unit}', '%s {$unit}s', \$count, 'sugarcommerce' )", $source );
		}

		$this->assertStringContainsString( "__( 'just now', 'sugarcommerce' )", $source );
	}

	/**
	 * A moment at the edge of what a DateTime holds does not overflow.
	 */
	public function test_relative_survives_the_extremes(): void {
		$this->assertSame( PHP_INT_MAX, Relative::delta( -PHP_INT_MAX, 10 ) );
		$this->assertSame( -PHP_INT_MAX, Relative::delta( PHP_INT_MAX, -10 ) );
		$this->assertSame( 5, Relative::delta( 10, 15 ) );
		$this->assertSame( -5, Relative::delta( 15, 10 ) );

		$this->assertIsArray( Relative::parts( PHP_INT_MIN ) );
		$this->assertIsArray( Relative::parts( PHP_INT_MAX, true ) );
	}

	/**
	 * parts() is the choice phrase() makes.
	 */
	public function test_parts(): void {
		$this->assertNull( Relative::parts( 59 ) );
		$this->assertNull( Relative::parts( -59 ) );
		$this->assertSame(
			array(
				'unit'   => 'second',
				'count'  => 59,
				'future' => true,
			),
			Relative::parts( -59, false, true )
		);
		$this->assertSame(
			array(
				'unit'   => 'second',
				'count'  => 1,
				'future' => false,
			),
			Relative::parts( 0, false, true )
		);
		$this->assertSame(
			array(
				'unit'   => 'week',
				'count'  => 1,
				'future' => false,
			),
			Relative::parts( 1123200 )
		);
		$this->assertSame(
			array(
				'unit'   => 'week',
				'count'  => 2,
				'future' => true,
			),
			Relative::parts( -1123200, true )
		);
	}
}
