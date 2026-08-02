<?php
/**
 * The source of "now".
 *
 * @package   Sugar\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace Sugar\Dates;

/**
 * Class Clock
 *
 * Wraps the current time so it can be frozen.
 *
 * Anything that reads `time()` directly is untestable at the boundaries
 * that matter: what a renewal does on 31 January, what a relative
 * timestamp says the moment it crosses an hour, whether "this month"
 * means the same thing at 23:59 on the last day. Those are precisely the
 * cases that break in production and cannot be reproduced from a test
 * that has to wait for a real clock.
 *
 * @since 1.0.0
 */
final readonly class Clock {

	/**
	 * @since 1.0.0
	 *
	 * @param int|null $fixed Frozen Unix timestamp, or null for the real
	 *                        clock.
	 */
	public function __construct( private ?int $fixed = null ) {}

	/**
	 * A clock frozen at a moment.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $moment Unix timestamp or a parseable date string.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the string cannot be parsed.
	 */
	public static function frozen( int|string $moment ): self {
		if ( is_int( $moment ) ) {
			return new self( $moment );
		}

		$parsed = strtotime( $moment . ( str_contains( $moment, 'UTC' ) ? '' : ' UTC' ) );

		if ( false === $parsed ) {
			throw new \InvalidArgumentException( 'Could not parse the time: ' . $moment );
		}

		return new self( $parsed );
	}

	/**
	 * The current Unix timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function now(): int {
		return $this->fixed ?? time();
	}

	/**
	 * The current moment as a UTC date-time.
	 *
	 * @since 1.0.0
	 *
	 * @return \DateTimeImmutable
	 */
	public function utc(): \DateTimeImmutable {
		return ( new \DateTimeImmutable( '@' . $this->now() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Whether this clock is frozen.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_frozen(): bool {
		return null !== $this->fixed;
	}
}
