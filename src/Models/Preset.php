<?php
/**
 * Named reporting periods.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Models;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Timestamp;

/**
 * Enum Preset
 *
 * The periods behind every dashboard's date picker.
 *
 * Two decisions worth stating. **Ends are exclusive**, so "today" runs
 * to midnight tomorrow rather than to 23:59:59 — see {@see Range}.
 * And **"last 30 days" excludes today**, because a partial day dragged
 * into a total makes every comparison against a complete period look
 * like a decline. `Last30Days` ends at midnight this morning;
 * {@see self::Last30DaysToDate} includes today when that is what you
 * want.
 *
 * **Whose midnight.** A period is two moments, and they are stored and
 * queried in UTC -- but "today" is somebody's day. Left alone the days here
 * are UTC's, as they always were. Given a zone (and the day that zone's
 * week starts on), every boundary is that zone's midnight: a shop in New
 * York has a Tuesday that runs from 04:00 or 05:00 UTC to the same hour on
 * Wednesday, and the day the clocks change is 23 or 25 hours long. The
 * Range that comes back still prints UTC.
 *
 * @since 1.0.0
 */
enum Preset: string {

	/** Midnight today to midnight tonight. */
	case Today = 'today';

	/** The whole of yesterday. */
	case Yesterday = 'yesterday';

	/** The seven complete days before today. */
	case Last7Days = 'last_7';

	/** The thirty complete days before today. */
	case Last30Days = 'last_30';

	/** The last thirty days, today included. */
	case Last30DaysToDate = 'last_30_to_date';

	/** The ninety complete days before today. */
	case Last90Days = 'last_90';

	/** This week, from the day weeks start on: Monday unless told. */
	case ThisWeek = 'this_week';

	/** The week before this one. */
	case LastWeek = 'last_week';

	/** This calendar month. */
	case ThisMonth = 'this_month';

	/** The previous calendar month. */
	case LastMonth = 'last_month';

	/** This calendar quarter. */
	case ThisQuarter = 'this_quarter';

	/** The previous calendar quarter. */
	case LastQuarter = 'last_quarter';

	/** This calendar year. */
	case ThisYear = 'this_year';

	/** The previous calendar year. */
	case LastYear = 'last_year';

	/** From 1 January 2000 to the end of today. */
	case AllTime = 'all_time';

	/**
	 * What parse_input() calls a period somebody chose the dates of.
	 *
	 * Not a case: it has no range of its own. resolve() reads it, like an
	 * empty slug, as "use the two dates".
	 *
	 * @since 1.0.0
	 */
	public const string CUSTOM = 'custom';

	/**
	 * The pattern a typed date matches, for a JSON Schema.
	 *
	 * @since 1.0.0
	 */
	public const string DATE_PATTERN = '^\\d{4}-\\d{2}-\\d{2}$';

	/**
	 * Resolve to a concrete range.
	 *
	 * @since 1.0.0
	 *
	 * @param Clock|null         $clock       Source of now.
	 * @param \DateTimeZone|null $zone        Whose days these are. UTC when
	 *                                        null, which is what this did
	 *                                        before it could be told.
	 * @param int                $week_starts The day a week starts on: 0 for
	 *                                        Sunday to 6 for Saturday. Monday
	 *                                        unless told otherwise.
	 *
	 * @return Range Two UTC moments, whatever the zone.
	 */
	public function range( ?Clock $clock = null, ?\DateTimeZone $zone = null, int $week_starts = 1 ): Range {
		$utc   = new \DateTimeZone( 'UTC' );
		$today = ( $clock ?? new Clock() )->local( $zone ?? $utc )->setTime( 0, 0 );

		// Every step below is taken on the calendar of the zone, so a day
		// added to a midnight is the next midnight however long the day was.
		$week    = Interval::Week->floor( $today, $week_starts );
		$quarter = Interval::Quarter->floor( $today );

		[ $from, $to ] = match ( $this ) {
			self::Today     => array( $today, $today->modify( '+1 day' ) ),
			self::Yesterday => array( $today->modify( '-1 day' ), $today ),

			// Complete days only, so a part-day never distorts a comparison.
			self::Last7Days  => array( $today->modify( '-7 days' ), $today ),
			self::Last30Days => array( $today->modify( '-30 days' ), $today ),
			self::Last90Days => array( $today->modify( '-90 days' ), $today ),

			self::Last30DaysToDate => array( $today->modify( '-29 days' ), $today->modify( '+1 day' ) ),

			// Monday to Monday unless told the week starts on another day.
			self::ThisWeek => array( $week, $week->modify( '+7 days' ) ),
			self::LastWeek => array( $week->modify( '-7 days' ), $week ),

			self::ThisMonth => array(
				$today->modify( 'first day of this month' ),
				$today->modify( 'first day of next month' ),
			),
			self::LastMonth => array(
				$today->modify( 'first day of last month' ),
				$today->modify( 'first day of this month' ),
			),

			self::ThisQuarter => array( $quarter, $quarter->modify( '+3 months' ) ),
			self::LastQuarter => array( $quarter->modify( '-3 months' ), $quarter ),

			self::ThisYear => array(
				$today->modify( 'first day of january this year' ),
				$today->modify( 'first day of january next year' ),
			),
			self::LastYear => array(
				$today->modify( 'first day of january last year' ),
				$today->modify( 'first day of january this year' ),
			),

			// Far enough back to precede any plausible record.
			self::AllTime => array(
				new \DateTimeImmutable( '2000-01-01 00:00:00', $utc ),
				$today->modify( '+1 day' ),
			),
		};

		return new Range( $from->setTimezone( $utc ), $to->setTimezone( $utc ) );
	}

	/**
	 * A human-readable label, in the site's language.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Today            => __( 'Today', 'sugarcommerce' ),
			self::Yesterday        => __( 'Yesterday', 'sugarcommerce' ),
			self::Last7Days        => __( 'Last 7 days', 'sugarcommerce' ),
			self::Last30Days       => __( 'Last 30 days', 'sugarcommerce' ),
			self::Last30DaysToDate => __( 'Last 30 days (to date)', 'sugarcommerce' ),
			self::Last90Days       => __( 'Last 90 days', 'sugarcommerce' ),
			self::ThisWeek         => __( 'This week', 'sugarcommerce' ),
			self::LastWeek         => __( 'Last week', 'sugarcommerce' ),
			self::ThisMonth        => __( 'This month', 'sugarcommerce' ),
			self::LastMonth        => __( 'Last month', 'sugarcommerce' ),
			self::ThisQuarter      => __( 'This quarter', 'sugarcommerce' ),
			self::LastQuarter      => __( 'Last quarter', 'sugarcommerce' ),
			self::ThisYear         => __( 'This year', 'sugarcommerce' ),
			self::LastYear         => __( 'Last year', 'sugarcommerce' ),
			self::AllTime          => __( 'All time', 'sugarcommerce' ),
		};
	}

	/**
	 * Every preset as slug => label, for a select element.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		$options = array();

		foreach ( self::cases() as $preset ) {
			$options[ $preset->value ] = $preset->label();
		}

		return $options;
	}

	/**
	 * The JSON Schema for a period somebody asks for by API.
	 *
	 * Three optional string properties, for a REST route's `args` or an
	 * ability's input schema: `period` (one of the slugs), or `start` and `end`
	 * as `YYYY-MM-DD` for a period of the caller's own. The schema cannot say
	 * "one or the other"; parse_input() does.
	 *
	 * @since 1.0.0
	 *
	 * @param Preset|string         $fallback     The period used when none is
	 *                                            asked for, named in the
	 *                                            `period` description.
	 * @param array<string, string> $descriptions Wording of your own for any of
	 *                                            `period`, `start` and `end`.
	 *
	 * @return array{period: array<string, mixed>, start: array<string, mixed>, end: array<string, mixed>}
	 */
	public static function input_schema( Preset|string $fallback = self::Last30Days, array $descriptions = array() ): array {
		$fallback = self::from_fallback( $fallback );

		return array(
			'period' => array(
				'type'        => 'string',
				'enum'        => array_keys( self::options() ),
				'description' => $descriptions['period'] ?? sprintf(
					/* translators: %s: a period's name, such as "Last 30 days". */
					__( 'A named period. Left out with no start and end, it is "%s".', 'sugarcommerce' ),
					$fallback->label()
				),
			),
			'start'  => array(
				'type'        => 'string',
				'pattern'     => self::DATE_PATTERN,
				'description' => $descriptions['start'] ?? __( 'The first day of a period of your own, as YYYY-MM-DD. Give it with end, in place of period.', 'sugarcommerce' ),
			),
			'end'    => array(
				'type'        => 'string',
				'pattern'     => self::DATE_PATTERN,
				'description' => $descriptions['end'] ?? __( 'The last day of a period of your own, as YYYY-MM-DD. The whole of that day is counted.', 'sugarcommerce' ),
			),
		);
	}

	/**
	 * Read the period an API client or an assistant asked for.
	 *
	 * The other half of input_schema(), and stricter than resolve(), which
	 * quietly falls back: a person at a screen should see something, but a
	 * client that asked for the wrong thing should be told. So:
	 *
	 * - `start` and `end` together are a period of the caller's own. Each must
	 *   be a real `YYYY-MM-DD` day, the start not after the end, and not
	 *   alongside a `period`.
	 * - A `period` must be one of the slugs.
	 * - Neither is the fallback.
	 *
	 * Errors are `{$prefix}_bad_period` (the period is unknown, or given with
	 * dates) and `{$prefix}_bad_date` (a date is missing, not real, or after
	 * the other), each with a 400 status. What comes back passes straight to
	 * resolve(): a custom period is `custom` with its two dates, a named one
	 * has empty dates.
	 *
	 *     $asked = Preset::parse_input( $request->get_params(), Preset::Last30Days, 'myplugin' );
	 *     if ( is_wp_error( $asked ) ) { return $asked; }
	 *     $range = Preset::resolve( $asked['preset'], $asked['start'], $asked['end'], null, wp_timezone() );
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input    The input: `period`, `start`, `end`.
	 * @param Preset|string        $fallback The period when none is asked for.
	 * @param string               $prefix   What the error codes start with.
	 *
	 * @return array{preset: string, start: string, end: string}|\WP_Error
	 */
	public static function parse_input( array $input, Preset|string $fallback = self::Last30Days, string $prefix = 'dates' ): array|\WP_Error {
		$text   = static fn( string $key ): string => is_scalar( $input[ $key ] ?? '' ) ? trim( (string) ( $input[ $key ] ?? '' ) ) : '';
		$period = (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $text( 'period' ) ) );
		$start  = $text( 'start' );
		$end    = $text( 'end' );

		if ( '' !== $start || '' !== $end ) {
			if ( '' !== $period ) {
				return new \WP_Error( $prefix . '_bad_period', __( 'Give a period, or a start and an end, not both.', 'sugarcommerce' ), array( 'status' => 400 ) );
			}

			foreach ( array( $start, $end ) as $date ) {
				if ( null === Timestamp::from_date( $date ) ) {
					return new \WP_Error( $prefix . '_bad_date', __( 'A period of your own needs a start and an end, each a real date written YYYY-MM-DD.', 'sugarcommerce' ), array( 'status' => 400 ) );
				}
			}

			// Both are YYYY-MM-DD, so they compare as strings.
			if ( $start > $end ) {
				return new \WP_Error( $prefix . '_bad_date', __( 'The start is after the end.', 'sugarcommerce' ), array( 'status' => 400 ) );
			}

			return array(
				'preset' => self::CUSTOM,
				'start'  => $start,
				'end'    => $end,
			);
		}

		if ( '' === $period ) {
			$period = self::from_fallback( $fallback )->value;
		} elseif ( null === self::tryFrom( $period ) ) {
			return new \WP_Error(
				$prefix . '_bad_period',
				sprintf(
					/* translators: %s: a list of period names. */
					__( 'That is not a period. Use one of: %s — or a start and an end.', 'sugarcommerce' ),
					implode( ', ', array_keys( self::options() ) )
				),
				array( 'status' => 400 )
			);
		}

		return array(
			'preset' => $period,
			'start'  => '',
			'end'    => '',
		);
	}

	/**
	 * A fallback period given as a case or a slug.
	 *
	 * @since 1.0.0
	 *
	 * @param Preset|string $fallback A case, or its slug.
	 *
	 * @return Preset
	 *
	 * @throws \ValueError When the slug is not a preset: a programming error.
	 */
	private static function from_fallback( Preset|string $fallback ): Preset {
		return $fallback instanceof self ? $fallback : self::from( $fallback );
	}

	/**
	 * Resolve a slug, or fall back to custom dates.
	 *
	 * The shape a dashboard actually needs: a preset slug when one is
	 * chosen, two dates when the user picked their own.
	 *
	 * Two dates somebody picked are days in the zone as well: "1 May to 31
	 * May" from a shop in Auckland is Auckland's May. A date that carries
	 * its own offset keeps it.
	 *
	 * @since 1.0.0
	 *
	 * @param string             $preset      Slug, or '' / 'custom' for
	 *                                        explicit dates.
	 * @param string             $from        Explicit start.
	 * @param string             $to          Explicit end, inclusive as a
	 *                                        user means it.
	 * @param Clock|null         $clock       Source of now.
	 * @param \DateTimeZone|null $zone        Whose days these are. UTC when
	 *                                        null.
	 * @param int                $week_starts 0 for Sunday to 6 for Saturday.
	 *
	 * @return Range Two UTC moments, whatever the zone.
	 */
	public static function resolve(
		string $preset,
		string $from = '',
		string $to = '',
		?Clock $clock = null,
		?\DateTimeZone $zone = null,
		int $week_starts = 1
	): Range {
		$known = self::tryFrom( trim( $preset ) );

		if ( null !== $known ) {
			return $known->range( $clock, $zone, $week_starts );
		}

		$start = self::day( $from, $zone );
		$end   = self::day( $to, $zone );

		if ( null === $start || null === $end ) {
			return self::Last30Days->range( $clock, $zone, $week_starts );
		}

		// Both dates come from the request. Reversed, they made a range
		// whose end preceded its start: no buckets, and a previous()
		// period that ran forwards.
		if ( $end < $start ) {
			[ $start, $end ] = array( $end, $start );
		}

		// A person picking "1 May to 31 May" means the whole of the 31st.
		// The range end is exclusive, so it advances to midnight.
		if ( '00:00:00' === $end->format( 'H:i:s' ) ) {
			$end = $end->modify( '+1 day' );
		}

		$utc = new \DateTimeZone( 'UTC' );

		return new Range( $start->setTime( 0, 0 )->setTimezone( $utc ), $end->setTimezone( $utc ) );
	}

	/**
	 * A date somebody picked, as a moment on the zone's own clock.
	 *
	 * @since 1.0.0
	 *
	 * @param string             $value The date, with or without a time.
	 * @param \DateTimeZone|null $zone  The zone a bare date is read in, and
	 *                                  the one the answer is in. UTC when
	 *                                  null.
	 *
	 * @return \DateTimeImmutable|null Null when it is not a date.
	 */
	private static function day( string $value, ?\DateTimeZone $zone ): ?\DateTimeImmutable {
		// With no zone this is exactly what it always was.
		if ( null === $zone ) {
			return Timestamp::parse( $value );
		}

		$value = trim( $value );

		if ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}

		try {
			return ( new \DateTimeImmutable( $value, $zone ) )->setTimezone( $zone );
		} catch ( \Exception ) {
			return null;
		}
	}
}
