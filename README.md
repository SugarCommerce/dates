# Dates

UTC-first date handling: a freezable clock, safe parsing, relative times, and reporting ranges with exclusive ends. Zero dependencies.

## Why

Two things go wrong with dates in an application that reports on itself.

**Tests that depend on the wall clock.** A test asserting "this was 3 days ago" passes until it runs at midnight, or on the last day of a month, or during a DST transition. Inject a clock and freeze it.

**Ranges with inclusive ends.** `WHERE created_at BETWEEN '2026-01-01' AND '2026-01-31'` silently drops everything on the 31st after midnight, because the end is `00:00:00`. Every range here is half-open — `>= from AND < to` — which is the only form that composes without gaps or overlaps.

## Features

- 🕐 **Freezable clock** — `Clock::frozen()` makes time-dependent code testable.
- 🌍 **UTC throughout** — store UTC, convert at the edge, never in between.
- 📅 **15 reporting presets** — today through last year, each returning a half-open range.
- 🔍 **Safe parsing** — returns null instead of throwing or guessing.
- ✍️ **Strict typed dates** — `2026-02-30` is no date, not 2 March; the end of a day is right on a 23-hour day.
- 🗣️ **Localised dates** — "5. Oktober 2026" through ext-intl, ISO 8601 without it.
- 💬 **Relative phrasing** — "3 hours ago", with an absolute timestamp for the tooltip.
- 📊 **Series queries** — SQL for a bucketed chart, for SQLite and MySQL, with gap filling, on date-time or Unix-integer columns.
- ↔️ **Comparable periods** — `previous()` gives the immediately preceding window of the same length.

## Requirements

PHP 8.5+

## Installation

```bash
composer require sugarcommerce/dates
```

## Usage

```php
use SugarCommerce\Dates\{Clock, Preset, Timestamp};

$range = Preset::Last30Days->range();

$range->start();   // '2026-07-02 00:00:00'
$range->end();     // '2026-08-01 00:00:00' — exclusive
$range->days();    // 30
```

### The clock

```php
$clock = new Clock();                       // real time
$clock = Clock::frozen( '2026-08-01 12:00:00' );
$clock = Clock::frozen( 1785331200 );

$clock->now();   // unix seconds
$clock->utc();   // DateTimeImmutable in UTC
```

Every function that needs the current time takes an optional `Clock`. Pass a frozen one in tests and the assertions stop depending on when they run.

```php
Preset::ThisMonth->range( $clock );
Timestamp::is_past( $expires_at, $clock );
Relative::format( $created_at, $clock );
```

### Presets

```php
Preset::Today, Yesterday, Last7Days, Last30Days, Last30DaysToDate, Last90Days,
       ThisWeek, LastWeek, ThisMonth, LastMonth, ThisQuarter, LastQuarter,
       ThisYear, LastYear, AllTime

Preset::options();                                    // value => label, for a select
Preset::resolve( 'custom', '2026-01-01', '2026-03-31' ); // named preset or explicit dates
```

`resolve()` is what a dashboard's date picker calls: it takes whatever came from the form and returns a `Range` either way.

### Querying

```php
[ $where, $bindings ] = $range->where( 'created_at' );

$sql = "SELECT SUM(total) FROM orders WHERE {$where}";
$statement->execute( $bindings );
```

Column names are validated against a strict pattern and throw if they don't match — they are interpolated, not bound, because SQL has no placeholder for an identifier.

For a chart:

```php
use SugarCommerce\Dates\Interval;

[ $sql, $bindings ] = $range->series_query(
    'orders',
    'created_at',
    Interval::Day,
    [ 'SUM(total) AS revenue' ]
);

$rows   = $db->query( $sql, $bindings );
$series = $range->fill( $rows, Interval::Day );  // every bucket present, gaps zeroed
```

`fill()` matters more than it looks: a chart built straight from grouped rows skips days with no orders, which turns a flat week into a misleading spike.

#### Unix-time columns

A column of integers needs saying so. SQLite's `strftime()` reads a bare number as a Julian day — every bucket lands thousands of years ago, without an error — and comparing an integer column with the date strings `where()` binds follows SQLite's type ordering, so it matches everything or nothing.

```php
$range = Range::last_days( 30 );              // midnight 29 days ago → midnight tonight

[ $where, $bindings ] = $range->where_epoch( 'date_created' );   // binds ints
Interval::Day->group_expression( 'date_created', 'sqlite', epoch: true );
// strftime('%Y-%m-%d', date_created, 'unixepoch')

[ $sql, $bindings ] = $range->series_query( 'orders', 'date_created', Interval::Day, epoch: true );
$series = $range->fill( $counts_by_bucket, Interval::Day );
```

The keys are the same as for a text column, so `fill()` works on either. On MySQL the epoch form goes through `FROM_UNIXTIME()`, which uses the session time zone: set it to UTC.

> The `$selects` argument is interpolated into the statement. It is for aggregate expressions you wrote, never for anything that came from a request.

### Comparisons

```php
$this_month = Preset::ThisMonth->range();
$last_month = $this_month->previous();   // same length, immediately before
```

### Relative times

```php
use SugarCommerce\Dates\Relative;

Relative::format( '2026-07-31 09:00:00' );        // '1 day ago'
Relative::with_title( '2026-07-31 09:00:00' );    // ['label' => …, 'title' => '… UTC']
```

The pair is for a UI: the phrase reads well, and the absolute timestamp goes in the `title` attribute so precision is one hover away.

The phrases are English. To translate them, take the choice without the words:

```php
Relative::parts( 3 * 86400 );   // ['unit' => 'day', 'count' => 3, 'future' => false]
Relative::parts( 30 );          // null — "just now"
```

and pick a translated sentence per unit and count. "{count} {unit} ago" cannot be translated as one template — the unit inflects with the count in most languages — and asking `parts()` keeps the translated phrase and the English one agreeing about where three weeks becomes a month.

### Parsing and formatting

```php
use SugarCommerce\Dates\Timestamp;

Timestamp::parse( $value );      // ?DateTimeImmutable — null, not an exception
Timestamp::to_unix( $value );    // ?int
Timestamp::now();                // '2026-08-01 13:00:00'
Timestamp::iso( $value );        // '2026-08-01T13:00:00+00:00'
Timestamp::format( $value, 'j M Y', 'Europe/Dublin' );
Timestamp::is_past( $value );
```

`parse()` returning null rather than throwing is deliberate: a date column read from a database is routinely empty, malformed, or `0000-00-00`, and none of those deserve an exception.

### Typed dates

A date typed into a form is where a lenient parse does damage: `DateTimeImmutable` reads `2026-02-30` as 2 March and "next friday" as whichever Friday is next, and nobody sees the error.

```php
Timestamp::from_date( '2026-03-31' );                 // midnight UTC that day
Timestamp::from_date( '2026-02-30' );                 // null — no such day
Timestamp::end_of_day( '2026-03-31' );                // 23:59:59 that day, for "until 31 March"
Timestamp::end_of_day( '2026-03-29', 'Europe/London' ); // right on a 23-hour day
```

Strict `YYYY-MM-DD`, round-tripped: a value that does not print back as what was typed is null. The end of a day is the next midnight less a second, not the start plus 86,399, because a day with a clock change is not 86,400 seconds long.

### Localised dates

```php
Timestamp::format_localized( $unix, 'de' );      // '5. Oktober 2026'
Timestamp::format_localized( $unix, 'pt-BR' );   // '5 de outubro de 2026'
Timestamp::format_localized( $unix, 'ja', 'Asia/Tokyo' );
```

The long date style, without a time. Needs ext-intl; without it the fallback format (ISO 8601 by default) is used, which every reader can parse.

## Storage

Store UTC. Convert on display, using the viewer's timezone, and never store the result.

A local timestamp cannot be reasoned about after the fact: you cannot tell whether `2026-10-25 01:30:00` in a European timezone was before or after the clocks changed, because both happened. UTC has no ambiguous hour.

## Testing

```bash
composer install
composer test
```

114 tests, run entirely against a frozen clock — including the leap year, the DST boundaries, and the month-end arithmetic that breaks naive `-1 month` maths; typed dates that do not exist, the end of 23- and 25-hour days, localised dates in four languages, relative parts agreeing with the English phrase across ±15 months, and an epoch series run against a real SQLite table and filled.

## License

GPL-2.0-or-later
