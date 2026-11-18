<?php
/**
 * The source of "now".
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Class Clock
 *
 * Wraps the current time so it can be frozen.
 *
 * Anything that reads `time()` directly is untestable at the boundaries
 * that matter: what a renewal does on 31 January, what a relative
 * timestamp says the moment it crosses an hour, whether "this month"
 * means the same thing at 23:59 on the last day, whether a signed link is
 * refused the second after it expires. Those are precisely the cases that
 * break in production and cannot be reproduced from a test that has to
 * wait for a real clock.
 *
 * Two ways to stop it, for two kinds of code.
 *
 * **A clock passed in.** Everything in this library that asks the time
 * takes an optional Clock, and a frozen one answers for that call alone:
 *
 *     Preset::ThisMonth->range( Clock::frozen( '2026-01-31 23:59:59' ) );
 *
 * **The clock everybody reads.** Code a test cannot reach into -- a signer
 * built deep inside a request, a report a REST route runs -- uses the real
 * clock, `new Clock()` or {@see self::time()}. That one can be stopped from
 * outside, moved on, and started again:
 *
 *     Clock::freeze( 1_800_000_000 );
 *     $token = $signer->sign( 'file', [ 'id' => 7 ], 60 );
 *     Clock::advance( 61 );
 *     // ... the download route now refuses $token ...
 *     Clock::reset();
 *
 * A clock given a moment of its own always wins over a frozen real one.
 *
 * It is a static, but not a global one: a plugin that bundles this library
 * under its own prefix has its own copy, so freezing it in one plugin's
 * tests leaves every other plugin on the real clock. It is for tests.
 * Nothing in production should freeze it.
 *
 * @since 1.0.0
 */
final class Clock {

	/**
	 * Where the real clock reads the time from, or null for `time()`.
	 *
	 * @since 1.0.0
	 *
	 * @var (\Closure(): int)|null
	 */
	private static ?\Closure $source = null;

	/**
	 * Whether the source is being asked right now.
	 *
	 * A source that reads the real clock -- `Clock::use( Clock::time( ... ) )`
	 * -- would ask itself forever. Asked from inside itself, the real clock
	 * answers `time()`.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private static bool $asking = false;

	/**
	 * @since 1.0.0
	 *
	 * @param int|null $fixed Frozen Unix timestamp, or null for the real
	 *                        clock.
	 */
	public function __construct( private readonly ?int $fixed = null ) {}

	/**
	 * A clock frozen at a moment.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $moment Unix timestamp, or a date string. A string
	 *                           with no zone of its own is UTC.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the string cannot be parsed.
	 */
	public static function frozen( int|string $moment ): self {
		return new self( self::unix( $moment ) );
	}

	/**
	 * The current Unix timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function now(): int {
		if ( null !== $this->fixed ) {
			return $this->fixed;
		}

		if ( null === self::$source || self::$asking ) {
			return time();
		}

		self::$asking = true;

		try {
			return (int) ( self::$source )();
		} finally {
			self::$asking = false;
		}
	}

	/**
	 * The current moment as a UTC date-time.
	 *
	 * @since 1.0.0
	 *
	 * @return DateTimeImmutable
	 */
	public function utc(): DateTimeImmutable {
		return ( new DateTimeImmutable( '@' . $this->now() ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The current moment on a zone's clock: the site's unless told.
	 *
	 * @since 1.0.0
	 *
	 * @param DateTimeZone|null $zone The zone. Null is the site's.
	 *
	 * @return DateTimeImmutable
	 */
	public function local( ?DateTimeZone $zone = null ): DateTimeImmutable {
		return $this->utc()->setTimezone( $zone ?? Site::timezone() );
	}

	/**
	 * Whether this clock is frozen: given a moment of its own, or reading
	 * a real clock that has been stopped or replaced.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_frozen(): bool {
		return null !== $this->fixed || null !== self::$source;
	}

	/* ─── The real clock, for tests ─────────────────────────────────── */

	/**
	 * The current Unix time on the real clock.
	 *
	 * `time()`, unless a test has frozen it. The one-liner for code that
	 * is handed no Clock.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function time(): int {
		return ( new self() )->now();
	}

	/**
	 * Stop the real clock at a moment.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $at Unix time, or a date string as frozen() takes.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the string cannot be parsed.
	 */
	public static function freeze( int|string $at ): void {
		$unix = self::unix( $at );

		self::$source = static fn(): int => $unix;
	}

	/**
	 * Move the real clock on, and leave it stopped there.
	 *
	 * From a running clock this freezes it at that many seconds from now. A
	 * negative number moves it back.
	 *
	 * @since 1.0.0
	 *
	 * @param int $seconds How far.
	 *
	 * @return void
	 */
	public static function advance( int $seconds ): void {
		self::freeze( self::time() + $seconds );
	}

	/**
	 * Have the real clock read the time from somewhere else.
	 *
	 * Asked every time, so a source that moves -- another library's frozen
	 * clock, a counter a test steps -- is followed.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(): int $source Returns Unix time each time it is asked.
	 *
	 * @return void
	 */
	public static function use( callable $source ): void {
		self::$source = \Closure::fromCallable( $source );
	}

	/**
	 * Back to the real clock.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$source = null;
	}

	/**
	 * A moment as Unix time.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $moment Unix time or a date string.
	 *
	 * @return int
	 *
	 * @throws \InvalidArgumentException When the string cannot be parsed.
	 */
	private static function unix( int|string $moment ): int {
		if ( is_int( $moment ) ) {
			return $moment;
		}

		$parsed = Timestamp::to_unix( $moment );

		if ( null === $parsed ) {
			throw new \InvalidArgumentException( 'Could not parse the time: ' . $moment );
		}

		return $parsed;
	}
}
