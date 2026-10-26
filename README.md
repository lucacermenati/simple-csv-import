# Simple CSV Import

Validate a CSV upload in the request, import it in chunks on the queue, and record every upload as a report. An import is a description of its file and its table; the package owns the flow.

## The flow

1. `CsvImport::run($uploadedFile, $userId)` streams the whole file once through `CsvValidator`, against the lists the import declares. Nothing per row is held in memory, so a million-row file validates in seconds.
2. A file that cannot be read, has the wrong columns or has failing rows gets an `ImportReport` with status `invalid` (and, when rows failed, a CSV listing them with line number and reason). `ImportRejected` is dispatched. Nothing is imported.
3. A clean file is stored on the configured disk and queued as a chain of `ImportCsvChunk` jobs closed by `FinishCsvImport`. Each job seeks to its chunk's byte offset, so the file is parsed once in total.
4. When the last chunk is done, or a chunk throws, the upload is deleted, a `succeeded` or `failed` report is recorded and `ImportFinished` is dispatched. Your application listens to notify the uploader, clear caches, and so on.

## Installation

```bash
composer require lucacermenati/simple-csv-import
php artisan vendor:publish --tag=simple-csv-import-config
php artisan migrate
```

The config sets the disk and directories for uploads and reports (the disk must be shared by the web request and the queue worker), the rows per job and per upsert statement, the job timeout and the user model behind `ImportReport::user()`.

## Defining an import

```php
use LucaCermenati\SimpleCsvImport\CsvImport;

class CityImport extends CsvImport
{
    public function modelClass(): string { return City::class; }

    // The file
    public function requiredHeaders(): array { return ['name', 'country', 'population']; }
    public function forbiddenHeaders(): array { return ['iso' => 'It looks like a countries file.']; }
    public function requiredColumns(): array { return ['name', 'country']; }
    public function numericColumns(): array { return ['population']; }
    public function positiveColumns(): array { return ['population']; }
    public function enumColumns(): array { return ['climate' => Climate::class]; }
    public function requiredMatch(): array { return ['country' => 'countries.iso']; }

    // The table
    public function uniqueBy(): array { return ['name', 'country_id']; }
    public function upsertColumns(): array { return ['population', 'climate']; }

    // A validated row, with the id every requiredMatch() cell resolved to
    public function map(array $row, array $matches): array
    {
        return [
            'name' => self::cell($row, 'name'),
            'country_id' => $matches['country'],
            'population' => self::int($row, 'population'),
            'climate' => self::cell($row, 'climate'),
        ];
    }
}
```

What the lists mean:

| Method | Rule | Verdict in the report |
| --- | --- | --- |
| `requiredHeaders()` | the file must have these columns, or it is rejected outright | (no report file) |
| `forbiddenHeaders()` | the file must not have these columns, column => hint | (no report file) |
| `requiredColumns()` | the cell must be filled | `missing_required` |
| `numericColumns()` | a filled cell must be a number (`1234.56` or `1234,56`) | `invalid_value` |
| `positiveColumns()` | a filled cell must be above zero | `invalid_value` |
| `enumColumns()` | a filled cell must be a value of the backed enum, case-insensitively | `invalid_value` |
| `requiredMatch()` | a filled cell must be found in `table.column`, case-insensitively | `unknown_code` |
| `reportColumns()` | the cells that identify a failing row in the report (default: the required headers) | |

`map()` is only called on rows that passed validation and never by the validator. `$matches` holds the id every `requiredMatch()` cell resolved to, or null for an empty cell. A code that disappeared between validation and import makes the chunk fail with "column: value no longer exists" rather than writing a null.

Headers are slugged: `"Risk Category"` is read as `risk_category`. The delimiter is detected from the header line. An empty cell, whitespace and the literal `NA` all mean "no value" (`CsvImport::normalize()`).

`afterImport()` is a hook that runs in the worker once every chunk is written, before the succeeded report; a throw ends the import as failed. `rowsPerJob()` and `rowsPerUpsert()` can be overridden per import, e.g. `1` for a file whose later rows look up earlier ones.

## Running an import

```php
$outcome = app(CityImport::class)->run($request->file('file'), $request->user()?->id);

if ($outcome->isInvalid()) {
    // $outcome->importReport is the invalid ImportReport; $outcome->message() tells the user why
}

// otherwise the file is queued: $outcome->message() says so
```

Build the import through the container (`app(CityImport::class)`), so the validator and the import share one `CodeLookups` instance per request or job: the code => id map of every `requiredMatch()` reference is loaded once and reused for every row, never queried per row.

## Reacting to the outcome

```php
Event::listen(ImportFinished::class, function (ImportFinished $event) {
    // $event->succeeded(), $event->report, $event->run->userId, $event->import
});

Event::listen(ImportRejected::class, function (ImportRejected $event) {
    // $event->report, $event->import
});
```

## Dry run

```bash
php artisan csv-import:dry-run "App\Imports\CityImport" cities.csv --detail=cities.report.csv
```

Validates the file the way the upload does and writes the failing rows, without touching the database.

## Reports

`LucaCermenati\SimpleCsvImport\Models\ImportReport` holds one row per upload: the import class, status (`invalid`, `succeeded`, `failed`), original file name, the reason of a rejection or failure, the failing rows per verdict, total and failed rows, the uploader and the report file. Deleting a report deletes its file. Show them in your admin as you like; the package ships no UI.
