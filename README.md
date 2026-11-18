# Dates

Dates for a WordPress site: what "last 30 days" means in UTC, the site's own
timezone and formats for showing a date back, and a clock tests can stop.

## What it does

A reports screen needs the same handful of things every time — what "last 30
days" means in UTC, the equivalent window before it to compare against, the
buckets to plot, and the site's timezone and format for showing a date back.

Getting that right by hand means remembering that WordPress stores UTC and
displays local, that a range is half-open or a row lands in two months at
once, and that "3 days ago" needs the exact date in a tooltip. This does all
of it in one place, against one clock a test can freeze.

## Features

* Turn a preset — this month, last quarter — into a start and end in UTC, in the site's timezone
* Get the matching period before it, for a comparison figure
* Build the WHERE clause and its bound parameters from a range, for a datetime column or one of Unix seconds
* Cut a range into the hours, days, weeks or months a chart draws, in the site's timezone
* Show a date in the site's timezone and format, or say "3 days ago" with the exact moment underneath
* Say a run of days as shortly as it can be said: "9–15 Nov 2026"
* Read a date somebody typed strictly, and a period an API client asked for
* Freeze, move and reset the clock in tests — the one every package reads

## Installation

```bash
composer require sugarcommerce/dates
```

## Quick start

The window a report is showing, and the one before it:

```php
use SugarCommerce\Dates\Preset;

$range    = Preset::Last30Days->range( null, wp_timezone() );
$previous = $range->previous();

// where_prepared() writes %s placeholders for $wpdb; where() writes :from
// and :to for PDO.
[ $where, $params ] = $range->where_prepared( 'date_created' );

$sales = $wpdb->get_var(
	$wpdb->prepare( "SELECT SUM(total) FROM {$table} WHERE {$where}", $params )
);
```

`Preset::options()` gives the whole list, labelled in the site's language, for
a dropdown.

## Whose midnight

A period is two UTC moments, but "today" is somebody's day. Left alone the
days are UTC's. Pass a timezone, and the day its week starts on, and every
boundary is that zone's midnight — a day the clocks change is 23 or 25 hours
long — while the range still prints UTC for the query:

```php
$today = Preset::Today->range( null, wp_timezone(), Site::week_starts() );

// A preset slug, or two dates somebody picked, the same way.
$range = Preset::resolve( $slug, $from, $to, null, wp_timezone(), Site::week_starts() );

// The last seven whole days, today included.
$week = Range::last_days( 7, null, wp_timezone() );
```

Ends are exclusive, so `>= from AND < to` is always right, and "last 30 days"
is thirty complete days — `Last30DaysToDate` includes today.

## Unix-time columns

A column of integers needs saying so. SQLite reads a bare number as a Julian
day and sorts every integer before every string, so a query written for a
datetime column silently matches nothing:

```php
[ $where, $bindings ] = $range->where_epoch( 'date_created' );           // :from and :to as ints
[ $where, $params ]   = $range->where_prepared( 'date_created', true );  // %d, for $wpdb
[ $sql, $bindings ]   = $range->series_query( 'orders', 'date_created', Interval::Day, epoch: true );
```

The keys come out the same as for a text column, so `fill()` works on either.
On MySQL the epoch form goes through `FROM_UNIXTIME()`, which uses the session
time zone: set it to UTC.

## Buckets for a chart

`Buckets` cuts a range into the pieces a chart draws, in the site's timezone:
hours for a day, days for a fortnight, numbered weeks for a month, then
months, quarters and years. Weeks start on the site's first day of the week,
the hour the clocks go back is on the axis once, and the names come through
`wp_date()`, so they are translated.

```php
use SugarCommerce\Dates\Buckets;

$buckets = Buckets::for_range( $range );   // size, keys, labels, titles, starts
$index   = array_flip( $buckets['keys'] );
$length  = Buckets::slot_length( $range ); // 13, or 16 for a zone off the hour

foreach ( $rows as $row ) {  // SELECT SUBSTR( date_created, 1, {$length} ) AS slot, ...
	$at = $index[ Buckets::key( Buckets::slot_time( $row->slot ), $buckets['size'] ) ] ?? null;
}
```

`Interval` is the other way to group, in SQL and in UTC:
`$range->series_query()` with `$range->fill()`. A quarter there is keyed by
its first month (`2026-04`), and the SQL groups by that too.

## Showing a date

```php
use SugarCommerce\Dates\Site;

echo Site::format( $order->date_created );          // in the site's timezone and format
echo Site::format_datetime( $order->date_created ); // the date and the time
echo Site::relative_html( $order->date_created );   // <span title="the exact moment">3 days ago</span>
echo Site::span( $range->start(), $last_day );      // "9–15 Nov 2026", "30 Nov – 6 Dec 2026"
echo Site::duration( time() - $started );           // "3 months"
```

`Site::relative()` and `Site::duration()` pick a whole translated phrase for
each unit and count, because "3 weeks ago" cannot be translated as "%s ago".
By default they count whole units, say "just now" under a minute, and give a
date past thirty days. A screen that has always used `human_time_diff()` can
keep its wording with `Site::relative( $value, null, Site::LIKE_WORDPRESS )`.

`Site::relative()` is the one relative-time renderer of the SugarCommerce
packages: tables' list dates and flyouts' activity feed call it rather than
keeping their own. `Relative::delta()` and `Relative::parts()` are its
arithmetic, for an application that words the phrase itself.

## A period asked for by API

A REST route or an ability that reports on a period takes a named `period`,
or a `start` and an `end` of the caller's own. `input_schema()` describes them
and `parse_input()` reads them strictly — a client that asked for the wrong
thing is told, where `resolve()` quietly falls back for a person at a screen:

```php
$args  = Preset::input_schema( Preset::Last30Days ) + $my_other_args;
$asked = Preset::parse_input( $request->get_params(), Preset::Last30Days, 'myplugin' );

if ( is_wp_error( $asked ) ) {
	return $asked; // myplugin_bad_period or myplugin_bad_date, status 400
}

$range = Preset::resolve( $asked['preset'], $asked['start'], $asked['end'], null, wp_timezone() );
```

## Dates somebody typed

`Timestamp::parse()` is lenient — it reads `tomorrow` — and is for stored
values. A date from a form goes through the strict ones, which answer null for
anything that is not a real day (`2026-02-30`, `2026-7-5`, `next friday`):

```php
$stored = Timestamp::date_to_sql( $_POST['end'], 'end' ); // '2026-07-15 23:59:59', or the zero date
$starts = Timestamp::from_date( $_POST['start'], 'Europe/Dublin' );
```

The zero date in any spelling is no date: `parse()`, `to_unix()` and
`to_sql()` answer null, and `Timestamp::is_empty()` says so. A string with no
zone is read as UTC whatever `date_default_timezone_set()` was last given.

## Freezing the clock

Everything here that asks the time reads `Clock`. Pass a frozen one to a
single call, or freeze the clock every package reads — for the code a test
cannot hand a clock to, such as a signed link minted deep inside a request:

```php
use SugarCommerce\Dates\Clock;

Preset::ThisMonth->range( Clock::frozen( '2026-01-31 23:59:59' ) );

Clock::freeze( '2026-07-15 12:00:00' ); // or a Unix time
Clock::advance( 61 );                   // and leave it stopped there
Clock::time();                          // time(), unless frozen
Clock::reset();                         // in tearDown()
```

`Clock::use( callable )` reads another source, asked every time. A clock given
a moment of its own always wins over a frozen one. Each plugin that bundles
this library under its own prefix has its own clock, so freezing it in one
plugin's tests leaves the rest on real time.

## Moving from arraypress/wp-*

From `arraypress/wp-date-utils` (`ArrayPress\Dates`) and the `Clock` of
`arraypress/wp-signed-tokens` (`ArrayPress\SignedTokens`). Everything not
listed keeps its name under `SugarCommerce\Dates`.

| Old | New |
|---|---|
| `ArrayPress\Dates\*` | `SugarCommerce\Dates\*` |
| `Site::relative_with_exact( $v, $clock, $args )` | `Site::relative_with_title( $v, $clock, $args )` |
| `Relative::format( $v, $clock, $cutoff, $fallback )` | `Site::relative( $v, $clock, [ 'cutoff' => $cutoff, 'fallback' => $fallback ] )` -- the same words in English, translated elsewhere |
| `Relative::phrase( $seconds )` | `Site::relative()` of a stored time, or `Relative::parts( $seconds )` for the unit and count |
| `Relative::with_title( $v, $clock )` | `Site::relative_with_title( $v, $clock )` -- the title is in the site's format and zone |
| `Buckets::span( $from, $to, $with_year )` | `Site::span( $from, $to, $with_year, [ 'timezone' => $from->getTimezone(), 'repeat_year' => true ] )` |
| `Buckets::local( $utc, $zone )` | `Site::local( $utc, $zone )` — null for something that is not a date, where it was now |
| `(int) get_option( 'start_of_week', 1 )` | `Site::week_starts()` |
| `Interval::Week->floor( $date )` | unchanged; `Interval::Week->floor( $date, $week_starts )` for a week that is not Monday's |
| `Clock::frozen( 'date' )` | unchanged; a string with an offset now keeps it |
| `ArrayPress\SignedTokens\Clock::now()` | `Clock::time()` |
| `ArrayPress\SignedTokens\Clock::freeze( $at )` | `Clock::freeze( $at )` — a date string as well as a Unix time |
| `ArrayPress\SignedTokens\Clock::advance( $s )` | `Clock::advance( $s )` |
| `ArrayPress\SignedTokens\Clock::use( $source )` | `Clock::use( $source )` |
| `ArrayPress\SignedTokens\Clock::reset()` | `Clock::reset()` |
| `ArrayPress\SignedTokens\Clock::is_frozen()` | `( new Clock() )->is_frozen()` |
| `Clock::use( $dates_clock->now( ... ) )` to keep the two in step | not needed: there is one clock |

New: `Range::where_epoch()`, `where_prepared( $column, true )`, the `$epoch`
argument of `series_query()` and `Interval::group_expression()`, and
`Clock::local()`. `Preset::label()` is translated (text domain `arraypress`).

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
