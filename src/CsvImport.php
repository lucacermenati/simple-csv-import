<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LucaCermenati\SimpleCsvImport\Events\ImportFinished;
use LucaCermenati\SimpleCsvImport\Events\ImportRejected;
use LucaCermenati\SimpleCsvImport\Jobs\FinishCsvImport;
use LucaCermenati\SimpleCsvImport\Jobs\ImportCsvChunk;
use LucaCermenati\SimpleCsvImport\Models\ImportReport;
use RuntimeException;
use Throwable;

/**
 * The one flow every CSV import follows, and the contract a concrete import fills in.
 *
 * run() validates the whole upload in the request: CsvValidator streams the file
 * once against the lists below. A file that cannot be read, has the wrong columns
 * or has failing rows gets an invalid ImportReport (ImportRejected is dispatched)
 * and nothing is imported. A clean file is stored and queued as a chain of
 * ImportCsvChunk jobs closed by FinishCsvImport; each job seeks to its chunk's
 * byte offset, so the file is parsed once in total. When the last chunk is done,
 * or a chunk throws, the stored upload is deleted, a succeeded or failed
 * ImportReport is recorded and ImportFinished is dispatched: the application
 * listens to notify the uploader, clear caches, and so on.
 *
 * A subclass is a description of its file (the headers and columns the validator
 * checks) and its table (model, unique key, update columns), plus map(), which
 * turns a validated row and the ids its matched cells resolved to into model
 * attributes. It holds no upload state: the jobs carry an ImportRun and build a
 * fresh import per job through the container.
 */
abstract class CsvImport
{
    public function __construct(
        private readonly CsvValidator $validator = new CsvValidator,
        private readonly CodeLookups $lookups = new CodeLookups,
    ) {}

    /**
     * @return class-string<Model>
     */
    abstract public function modelClass(): string;

    /**
     * A short name for file names, e.g. "cities" for CityImport.
     */
    public function name(): string
    {
        return Str::snake(Str::beforeLast(class_basename(static::class), 'Import'));
    }

    /**
     * CSV columns the file must have. Any other column in the file is ignored
     * (map() reads the cells it knows by name) unless forbiddenHeaders() names it.
     *
     * @return list<string>
     */
    abstract public function requiredHeaders(): array;

    /**
     * CSV columns that mark a file of another import, column => hint for the user.
     * A file that has one is rejected outright, before any row is checked.
     *
     * @return array<string, string>
     */
    public function forbiddenHeaders(): array
    {
        return [];
    }

    /**
     * CSV columns that must carry a value. The database column is NOT NULL, or the
     * import would write something the column rejects.
     *
     * @return list<string>
     */
    public function requiredColumns(): array
    {
        return [];
    }

    /**
     * CSV columns that must hold a number when they hold anything. Both "1234.56"
     * and "1234,56" are numbers.
     *
     * @return list<string>
     */
    public function numericColumns(): array
    {
        return [];
    }

    /**
     * CSV columns that must hold a number above zero when they hold anything.
     *
     * @return list<string>
     */
    public function positiveColumns(): array
    {
        return [];
    }

    /**
     * CSV columns whose value must be one of a backed enum's values when given,
     * column => enum class. Matched case-insensitively.
     *
     * @return array<string, class-string<\BackedEnum>>
     */
    public function enumColumns(): array
    {
        return [];
    }

    /**
     * CSV columns whose value, when given, must be found (case-insensitively) in a
     * table column, column => "table.column". Whether the cell may be empty is
     * requiredColumns()' business. The import resolves every entry to the matched
     * row's id before map() runs.
     *
     * @return array<string, string>
     */
    public function requiredMatch(): array
    {
        return [];
    }

    /**
     * The CSV cells that identify a failing row in the report, so a user can find
     * it in the file. By default the required headers.
     *
     * @return list<string>
     */
    public function reportColumns(): array
    {
        return $this->requiredHeaders();
    }

    /** @return list<string> */
    abstract public function uniqueBy(): array;

    /** @return list<string> */
    abstract public function upsertColumns(): array;

    /**
     * The attributes of the model a CSV row becomes. The row has passed validation:
     * required cells are filled, numbers are numbers, enum values are known and
     * every requiredMatch() cell resolved to an id, handed over as $matches
     * (column => id, null for an empty cell).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int|null>  $matches
     * @return array<string, mixed>
     */
    abstract public function map(array $row, array $matches): array;

    /** Rows per INSERT ... ON DUPLICATE KEY UPDATE statement. */
    protected function rowsPerUpsert(): int
    {
        return (int) config('simple-csv-import.rows_per_upsert');
    }

    /** Rows per queued job. */
    protected function rowsPerJob(): int
    {
        return (int) config('simple-csv-import.rows_per_job');
    }

    /**
     * Runs in the queue worker once every chunk is written, before the succeeded
     * report is recorded. A throwing hook ends the import as failed.
     */
    protected function afterImport(): void {}

    /**
     * The whole upload from the user's point of view: validates the file and either
     * records why it was rejected or stores and queues it. Runs in the request; the
     * queued part reports back through finish() and fail().
     *
     * @param  int|null  $userId  who uploaded the file, recorded on the report
     */
    final public function run(UploadedFile $file, ?int $userId = null): ImportOutcome
    {
        $disk = Storage::disk(self::disk());
        $disk->makeDirectory(self::reportsDirectory());
        $reportPath = self::reportsDirectory().'/'.now()->format('Ymd-His').'-'.$this->name().'-'.Str::uuid().'.csv';
        $originalFilename = $file->getClientOriginalName();

        try {
            $result = $this->validator->validate($this, $this->reader($file->getRealPath()), $disk->path($reportPath));
        } catch (InvalidCsvException $e) {
            $empty = new ValidationResult(0, []);

            return $this->rejected($empty, $this->report(ImportStatus::Invalid, $originalFilename, $userId, $empty, $e->getMessage()));
        }

        if ($result->hasFailures()) {
            return $this->rejected($result, $this->report(ImportStatus::Invalid, $originalFilename, $userId, $result, 'Nothing was imported: the report lists the failing rows.', $reportPath));
        }

        // A clean file has no failing rows to list, so the header-only CSV is not kept.
        $disk->delete($reportPath);

        $storedPath = $file->store(self::uploadsDirectory(), self::disk()) ?: throw new RuntimeException("Cannot store the upload [{$originalFilename}].");

        try {
            $this->queue(new ImportRun($storedPath, $originalFilename, $userId, $result->totalRows));
        } catch (Throwable $e) {
            $disk->delete($storedPath);

            throw $e;
        }

        return new ImportOutcome($result);
    }

    /**
     * Streams a file once against this import's rules and writes the failing rows
     * to the report CSV. Nothing is written to the database.
     *
     * @throws InvalidCsvException when the file is unreadable or has the wrong columns
     */
    final public function validateFile(string $csvPath, string $reportPath): ValidationResult
    {
        return $this->validator->validate($this, $this->reader($csvPath), $reportPath);
    }

    /**
     * Dispatches the import of the stored file as a chain of chunk jobs.
     *
     * @param  int|null  $rowsPerJob  only tests pass a value, to cover chunk boundaries with a small file
     */
    final public function queue(ImportRun $run, ?int $rowsPerJob = null): void
    {
        $jobs = array_map(
            fn (CsvChunk $chunk) => new ImportCsvChunk(static::class, $run, $chunk),
            $this->storedReader($run)->chunks($rowsPerJob ?? $this->rowsPerJob()),
        );
        $jobs[] = new FinishCsvImport(static::class, $run);

        Bus::chain($jobs)->dispatch();
    }

    /**
     * Upserts the rows of one chunk, in one transaction. Runs in the queue worker.
     */
    final public function importChunk(ImportRun $run, CsvChunk $chunk): void
    {
        $this->upsertRows($this->storedReader($run)->rows($chunk));
    }

    /**
     * Runs in the queue worker once the last chunk is done.
     */
    final public function finish(ImportRun $run): void
    {
        try {
            $this->afterImport();
        } catch (Throwable $e) {
            $this->fail($run, $e->getMessage());

            throw $e;
        }

        $this->end($run, ImportStatus::Succeeded);
    }

    /**
     * A failed import has still written the chunks before the failing one, so it
     * ends the same way.
     *
     * @param  string|null  $reason  what went wrong, recorded on the report
     */
    final public function fail(ImportRun $run, ?string $reason = null): void
    {
        $this->end($run, ImportStatus::Failed, $reason);
    }

    private function rejected(ValidationResult $result, ImportReport $report): ImportOutcome
    {
        ImportRejected::dispatch($this, $report);

        return new ImportOutcome($result, $report);
    }

    private function end(ImportRun $run, ImportStatus $status, ?string $reason = null): void
    {
        Storage::disk(self::disk())->delete($run->storedPath);

        $report = $this->report($status, $run->originalFilename, $run->userId, new ValidationResult($run->totalRows, []), $reason);

        ImportFinished::dispatch($this, $run, $report);
    }

    private function report(ImportStatus $status, string $originalFilename, ?int $userId, ValidationResult $result, ?string $reason = null, ?string $reportPath = null): ImportReport
    {
        return ImportReport::query()->create([
            'import' => static::class,
            'status' => $status,
            'original_filename' => $originalFilename,
            'report_path' => $reportPath,
            'reason' => $reason,
            'counts' => $result->counts,
            'total_rows' => $result->totalRows,
            'failed_rows' => $result->failedRows(),
            'user_id' => $userId,
        ]);
    }

    /**
     * The reader is bound to one file, which the import only learns here.
     */
    protected function reader(string $path): CsvReader
    {
        return new CsvReader($path);
    }

    private function storedReader(ImportRun $run): CsvReader
    {
        return $this->reader(Storage::disk(self::disk())->path($run->storedPath));
    }

    /**
     * Maps and upserts rows in batches, all in one transaction.
     *
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function upsertRows(iterable $rows): void
    {
        DB::transaction(function () use ($rows): void {
            $limit = $this->rowsPerUpsert();
            $batch = [];

            foreach ($rows as $row) {
                $batch[] = $this->map($row, $this->matches($row));

                if (count($batch) === $limit) {
                    $this->upsert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->upsert($batch);
            }
        });
    }

    /**
     * The id every requiredMatch() cell resolves to. Validation accepted the row, so
     * a filled cell that no longer resolves means the row disappeared since; the
     * import fails rather than writing a null the column may silently accept.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, int|null>
     */
    private function matches(array $row): array
    {
        $matches = [];

        foreach ($this->requiredMatch() as $column => $reference) {
            $cell = self::cell($row, $column);

            $matches[$column] = $cell === null
                ? null
                : ($this->lookups->id($reference, $cell) ?? throw new RuntimeException("{$column}: {$cell} no longer exists."));
        }

        return $matches;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        $modelClass = $this->modelClass();

        $modelClass::query()->upsert($rows, $this->uniqueBy(), $this->upsertColumns());
    }

    public static function disk(): string
    {
        return (string) config('simple-csv-import.disk');
    }

    public static function uploadsDirectory(): string
    {
        return (string) config('simple-csv-import.uploads_directory');
    }

    public static function reportsDirectory(): string
    {
        return (string) config('simple-csv-import.reports_directory');
    }

    /**
     * An empty cell, whitespace and the literal "NA" all mean "no value".
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || strtoupper($value) === 'NA' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function cell(array $row, string $column): ?string
    {
        return self::normalize($row[$column] ?? null);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function int(array $row, string $column): ?int
    {
        $value = self::cell($row, $column);

        return $value === null ? null : (int) $value;
    }

    /**
     * Accepts both "1234.56" and "1234,56".
     *
     * @param  array<string, mixed>  $row
     */
    protected static function float(array $row, string $column): ?float
    {
        $value = self::cell($row, $column);

        return $value === null ? null : (float) str_replace(',', '.', $value);
    }
}
