<?php
/**
 * Named reporting periods.
 *
 * @package   SugarMill\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Dates;

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
 * @since 1.0.0
 */
enum Preset: string {

	case Today            = 'today';
	case Yesterday        = 'yesterday';
	case Last7Days        = 'last_7';
	case Last30Days       = 'last_30';
	case Last30DaysToDate = 'last_30_to_date';
	case Last90Days       = 'last_90';
	case ThisWeek         = 'this_week';
	case LastWeek         = 'last_week';
	case ThisMonth        = 'this_month';
	case LastMonth        = 'last_month';
	case ThisQuarter      = 'this_quarter';
	case LastQuarter      = 'last_quarter';
	case ThisYear         = 'this_year';
	case LastYear         = 'last_year';
	case AllTime          = 'all_time';

	/**
	 * Resolve to a concrete range.
	 *
	 * @since 1.0.0
	 *
	 * @param Clock|null $clock Source of now.
	 *
	 * @return Range
	 */
	public function range( ?Clock $clock = null ): Range {
		$now   = ( $clock ?? new Clock() )->utc();
		$today = $now->setTime( 0, 0 );

		return match ( $this ) {
			self::Today     => new Range( $today, $today->modify( '+1 day' ) ),
			self::Yesterday => new Range( $today->modify( '-1 day' ), $today ),

			// Complete days only, so a part-day never distorts a comparison.
			self::Last7Days  => new Range( $today->modify( '-7 days' ), $today ),
			self::Last30Days => new Range( $today->modify( '-30 days' ), $today ),
			self::Last90Days => new Range( $today->modify( '-90 days' ), $today ),

			self::Last30DaysToDate => new Range( $today->modify( '-29 days' ), $today->modify( '+1 day' ) ),

			// ISO weeks: Monday start, which is what every European
			// business reports on.
			self::ThisWeek => new Range(
				$today->modify( 'monday this week' ),
				$today->modify( 'monday this week' )->modify( '+7 days' )
			),
			self::LastWeek => new Range(
				$today->modify( 'monday last week' ),
				$today->modify( 'monday this week' )
			),

			self::ThisMonth => new Range(
				$today->modify( 'first day of this month' ),
				$today->modify( 'first day of next month' )
			),
			self::LastMonth => new Range(
				$today->modify( 'first day of last month' ),
				$today->modify( 'first day of this month' )
			),

			self::ThisQuarter => self::quarter( $today, 0 ),
			self::LastQuarter => self::quarter( $today, -1 ),

			self::ThisYear => new Range(
				$today->modify( 'first day of january this year' ),
				$today->modify( 'first day of january next year' )
			),
			self::LastYear => new Range(
				$today->modify( 'first day of january last year' ),
				$today->modify( 'first day of january this year' )
			),

			// Far enough back to precede any plausible record.
			self::AllTime => new Range(
				new \DateTimeImmutable( '2000-01-01 00:00:00', new \DateTimeZone( 'UTC' ) ),
				$today->modify( '+1 day' )
			),
		};
	}

	/**
	 * A human-readable label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Today            => 'Today',
			self::Yesterday        => 'Yesterday',
			self::Last7Days        => 'Last 7 days',
			self::Last30Days       => 'Last 30 days',
			self::Last30DaysToDate => 'Last 30 days (to date)',
			self::Last90Days       => 'Last 90 days',
			self::ThisWeek         => 'This week',
			self::LastWeek         => 'Last week',
			self::ThisMonth        => 'This month',
			self::LastMonth        => 'Last month',
			self::ThisQuarter      => 'This quarter',
			self::LastQuarter      => 'Last quarter',
			self::ThisYear         => 'This year',
			self::LastYear         => 'Last year',
			self::AllTime          => 'All time',
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
	 * Resolve a slug, or fall back to custom dates.
	 *
	 * The shape a dashboard actually needs: a preset slug when one is
	 * chosen, two dates when the user picked their own.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $preset Slug, or '' / 'custom' for explicit dates.
	 * @param string     $from   Explicit start.
	 * @param string     $to     Explicit end, inclusive as a user means it.
	 * @param Clock|null $clock  Source of now.
	 *
	 * @return Range
	 */
	public static function resolve( string $preset, string $from = '', string $to = '', ?Clock $clock = null ): Range {
		$known = self::tryFrom( trim( $preset ) );

		if ( null !== $known ) {
			return $known->range( $clock );
		}

		$start = Timestamp::parse( $from );
		$end   = Timestamp::parse( $to );

		if ( null === $start || null === $end ) {
			return self::Last30Days->range( $clock );
		}

		// A person picking "1 May to 31 May" means the whole of the 31st.
		// The range end is exclusive, so it advances to midnight.
		if ( '00:00:00' === $end->format( 'H:i:s' ) ) {
			$end = $end->modify( '+1 day' );
		}

		return new Range( $start->setTime( 0, 0 ), $end );
	}

	/**
	 * The calendar quarter containing a date, offset by whole quarters.
	 *
	 * @since 1.0.0
	 *
	 * @param \DateTimeImmutable $date   Reference date.
	 * @param int                $offset Quarters to shift by.
	 *
	 * @return Range
	 */
	private static function quarter( \DateTimeImmutable $date, int $offset ): Range {
		$month = (int) $date->format( 'n' );
		$start = $date->setDate( (int) $date->format( 'Y' ), ( intdiv( $month - 1, 3 ) * 3 ) + 1, 1 )->setTime( 0, 0 );

		if ( 0 !== $offset ) {
			$start = $start->modify( ( $offset * 3 ) . ' months' );
		}

		return new Range( $start, $start->modify( '+3 months' ) );
	}
}
