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
- 💬 **Relative phrasing** — "3 hours ago", with an absolute timestamp for the tooltip.
- 📊 **Series queries** — SQL for a bucketed chart, for SQLite and MySQL, with gap filling.
- ↔️ **Comparable periods** — `previous()` gives the immediately preceding window of the same length.

## Requirements

PHP 8.5+

## Installation

```bash
composer require sugarmill/dates
```

## Usage

```php
use SugarMill\Dates\{Clock, Preset, Timestamp};

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
use SugarMill\Dates\Interval;

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

> The `$selects` argument is interpolated into the statement. It is for aggregate expressions you wrote, never for anything that came from a request.

### Comparisons

```php
$this_month = Preset::ThisMonth->range();
$last_month = $this_month->previous();   // same length, immediately before
```

### Relative times

```php
use SugarMill\Dates\Relative;

Relative::format( '2026-07-31 09:00:00' );        // '1 day ago'
Relative::with_title( '2026-07-31 09:00:00' );    // ['label' => …, 'title' => '… UTC']
```

The pair is for a UI: the phrase reads well, and the absolute timestamp goes in the `title` attribute so precision is one hover away.

### Parsing and formatting

```php
use SugarMill\Dates\Timestamp;

Timestamp::parse( $value );      // ?DateTimeImmutable — null, not an exception
Timestamp::to_unix( $value );    // ?int
Timestamp::now();                // '2026-08-01 13:00:00'
Timestamp::iso( $value );        // '2026-08-01T13:00:00+00:00'
Timestamp::format( $value, 'j M Y', 'Europe/Dublin' );
Timestamp::is_past( $value );
```

`parse()` returning null rather than throwing is deliberate: a date column read from a database is routinely empty, malformed, or `0000-00-00`, and none of those deserve an exception.

## Storage

Store UTC. Convert on display, using the viewer's timezone, and never store the result.

A local timestamp cannot be reasoned about after the fact: you cannot tell whether `2026-10-25 01:30:00` in a European timezone was before or after the clocks changed, because both happened. UTC has no ambiguous hour.

## Testing

```bash
composer install
composer test
```

84 tests, run entirely against a frozen clock — including the leap year, the DST boundaries, and the month-end arithmetic that breaks naive `-1 month` maths.

## License

GPL-2.0-or-later
