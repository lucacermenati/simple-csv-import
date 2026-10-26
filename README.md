# Simple CSV Import

A Laravel package that validates a CSV upload in the request, imports it in chunks on the queue, and records every upload as a report. An import is a description of its file and its table; the package owns the flow.

- The whole file is validated before a single row is written. A file with one bad row imports nothing and gets a report listing the failing rows.
- Validation streams the file and holds nothing per row in memory, so a million-row file validates in seconds.
- The import runs as a chain of queued jobs, each seeking to its chunk's byte offset, so the file is parsed once in total.
- Lookup codes (`country` => `countries.iso`) are resolved through one code => id map per request or job, never a query per row.
- Every upload, rejected, succeeded or failed, leaves an `ImportReport` row your admin can show.

## Requirements

- PHP 8.3+
- Laravel 12
- A queue connection (the import runs on the queue; `sync` works for local development)
- A filesystem disk shared by the web request and the queue worker

## Installation

```bash
composer require lucacermenati/simple-csv-import
php artisan migrate
```

The migration creating the `csv_import_reports` table loads from the package. Publish the config to change the disk, directories, chunk sizes, job timeout or the user model, and publish the migration only if you want to edit it:

```bash
php artisan vendor:publish --tag=simple-csv-import-config
php artisan vendor:publish --tag=simple-csv-import-migrations
```

## Quick start

Given a `cities` table and a `countries` table with an `iso` column, this import reads a file like:

```csv
name,country,population,climate
Amsterdam,NL,921402,temperate
Milan,IT,1371498,temperate
```

### 1. Define the import

```php
namespace App\Imports;

use App\Enums\Climate;
use App\Models\City;
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

### 2. Run it from a controller

```php
namespace App\Http\Controllers;

use App\Imports\CityImport;
use Illuminate\Http\Request;

class CityImportController extends Controller
{
    public function store(Request $request, CityImport $import)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);

        $outcome = $import->run($request->file('file'), $request->user()?->id);

        if ($outcome->isInvalid()) {
            // Nothing was imported. $outcome->importReport is the invalid report;
            // its report_path (when set) is the CSV of failing rows on the configured disk.
            return back()->withErrors(['file' => $outcome->message()]);
        }

        // The file is stored and queued. The report arrives when the worker is done.
        return back()->with('status', $outcome->message()); // "1,234 rows, 0 failed. Import queued."
    }
}
```

Resolve the import through the container (route injection or `app(CityImport::class)`), never with `new`. The validator and the import then share one `CodeLookups` instance per request or job, so every `requiredMatch()` reference is loaded once and reused for every row.

### 3. React when the import ends

The queued part reports back through events. Listen to notify the uploader, clear caches, and so on:

```php
use LucaCermenati\SimpleCsvImport\Events\ImportFinished;
use LucaCermenati\SimpleCsvImport\Events\ImportRejected;

Event::listen(ImportFinished::class, function (ImportFinished $event) {
    // $event->succeeded()        true for a succeeded report, false for a failed one
    // $event->report             the succeeded or failed ImportReport
    // $event->run->userId        who uploaded
    // $event->run->totalRows     rows validation counted
    // $event->import             a fresh instance of the import
});

Event::listen(ImportRejected::class, function (ImportRejected $event) {
    // Dispatched in the request when validation rejects a file.
    // $event->report is the invalid ImportReport, $event->import the import.
});
```

## The flow

1. `CsvImport::run($uploadedFile, $userId)` streams the whole file once through `CsvValidator`, against the lists the import declares.
2. A file that cannot be read, has the wrong columns or has failing rows gets an `ImportReport` with status `invalid` (and, when rows failed, a CSV listing them with line number and reason). `ImportRejected` is dispatched. Nothing is imported.
3. A clean file is stored on the configured disk and queued as a chain of `ImportCsvChunk` jobs closed by `FinishCsvImport`. Each chunk is upserted in one transaction.
4. When the last chunk is done, or a chunk throws, the upload is deleted, a `succeeded` or `failed` report is recorded and `ImportFinished` is dispatched. A failed import keeps the chunks written before the failing one.

## Defining an import

A subclass of `CsvImport` describes its file and its table. The abstract methods are `modelClass()`, `requiredHeaders()`, `uniqueBy()`, `upsertColumns()` and `map()`; the rest default to empty lists.

### Validation rules

| Method | Rule | Verdict in the report |
| --- | --- | --- |
| `requiredHeaders()` | the file must have these columns, or it is rejected outright | (no report file) |
| `forbiddenHeaders()` | the file must not have these columns, column => hint for the user | (no report file) |
| `requiredColumns()` | the cell must be filled | `missing_required` |
| `numericColumns()` | a filled cell must be a number (`1234.56` or `1234,56`) | `invalid_value` |
| `positiveColumns()` | a filled cell must be above zero | `invalid_value` |
| `enumColumns()` | a filled cell must be a value of the backed enum, case-insensitively | `invalid_value` |
| `requiredMatch()` | a filled cell must be found in `table.column`, case-insensitively | `unknown_code` |
| `reportColumns()` | the cells that identify a failing row in the report (default: the required headers) | |

A row gets one verdict. An unknown code wins, then a missing required value, then an invalid value; the details column of the report lists every problem found on the row. Any column the file has beyond the required ones is ignored unless `forbiddenHeaders()` names it. A unique key that occurs twice in a file is not a failure: both rows are upserted and the last one wins.

### How the file is read

- Headers are slugged: `"Risk Category"` is read as `risk_category`. Name your columns in slug form.
- The delimiter is detected from the header line: comma, semicolon, tab or pipe, whichever occurs most.
- The header is line 1, so the first data row is line 2. Blank lines are skipped, short rows are padded with null, long rows are truncated.
- An empty cell, whitespace and the literal `NA` all mean "no value" (`CsvImport::normalize()`).

### Writing `map()`

`map()` turns a validated row into the attributes of the model. It is only called on rows that passed validation and never by the validator. `$matches` holds the id every `requiredMatch()` cell resolved to, or null for an empty cell. A code that disappeared between validation and import makes the chunk fail with "column: value no longer exists" rather than writing a null.

Three protected helpers read a cell through `normalize()`:

| Helper | Returns |
| --- | --- |
| `self::cell($row, 'name')` | the trimmed string, or null for no value |
| `self::int($row, 'population')` | an int, or null |
| `self::float($row, 'price')` | a float, accepting both `1234.56` and `1234,56`, or null |

### The table

Rows are written with `Model::upsert()` in batches, inside one transaction per chunk. `uniqueBy()` names the columns that identify a row (they need a unique index), `upsertColumns()` the columns updated when the row already exists. Re-importing a file updates the upsert columns only.

### Hooks and tuning

- `afterImport()` runs in the worker once every chunk is written, before the succeeded report is recorded. A throw ends the import as failed, with the exception message as the reason.
- `rowsPerJob()` and `rowsPerUpsert()` override the config per import, e.g. `1` for a file whose later rows look up earlier ones.
- `name()` is the short name used in report file names, `cities` for `CityImport`.

## Reports

`LucaCermenati\SimpleCsvImport\Models\ImportReport` holds one row per upload in `csv_import_reports`:

| Column | Holds |
| --- | --- |
| `import` | the import class, so the application can label and link it |
| `status` | `ImportStatus`: `invalid`, `succeeded` or `failed` |
| `original_filename` | the name the user uploaded |
| `report_path` | the failing-rows CSV on the configured disk; only set for a rejected upload with failing rows |
| `reason` | why a file was rejected outright, or the exception message of a failed import |
| `counts` | failing rows per verdict |
| `total_rows`, `failed_rows` | what validation counted |
| `user_id` | the id handed to `run()` |

The model gives you `$report->summary` ("12 rows, 2 failed."), `$report->user()` (the configured user model) and `$report->importer()` (a fresh instance of the import). Deleting a report deletes its file. Rows are the import history and are never pruned by the package. The package ships no UI; show the reports in your admin as you like.

### The failing-rows CSV

The report CSV has one row per failing row of the upload: its line number in the file, the verdict, the cells of `reportColumns()` so a user can find the row, and the details.

```csv
line,verdict,name,country,population,details
4,unknown_code,Oslo,NO,,"country: NO not found"
9,missing_required,,NL,1200,"name: empty"
12,invalid_value,Utrecht,NL,-3,"population: must be above zero, got -3"
```

## Dry run

Validate a file from the command line the way the upload does, without touching the database:

```bash
php artisan csv-import:dry-run "App\Imports\CityImport" cities.csv --detail=cities.report.csv
```

It prints the failing rows per verdict and writes them to the detail path, by default `<file>.dry-run.csv`.

## Configuration

`config/simple-csv-import.php`:

| Key | Default | Meaning |
| --- | --- | --- |
| `disk` | `CSV_IMPORT_DISK` or `local` | where uploads and reports live; must be shared by the web request and the queue worker |
| `uploads_directory` | `csv-imports` | an accepted upload is stored here until the import is done |
| `reports_directory` | `csv-import-reports` | the failing-rows CSV of a rejected upload lives here as long as its report row exists |
| `rows_per_job` | `20000` | rows per queued chunk job |
| `rows_per_upsert` | `1000` | rows per `INSERT ... ON DUPLICATE KEY UPDATE` statement |
| `job_timeout` | `75` | seconds a chunk job may run |
| `user_model` | `CSV_IMPORT_USER_MODEL` or `App\Models\User` | the model behind `ImportReport::user()` |

Keep `job_timeout` below your queue connection's `retry_after`, so a slow chunk is never run twice. Chunk jobs have one try: a failing chunk ends the import as failed rather than being retried.

## Testing

```bash
composer test
composer lint
```

The suite runs on Orchestra Testbench with an in-memory SQLite database and covers the whole flow: a clean file queued and imported, failing rows rejected with a report, wrong and foreign headers, multi-chunk files, a code that disappears mid-import, re-imports and the dry-run command.

## License

MIT.
