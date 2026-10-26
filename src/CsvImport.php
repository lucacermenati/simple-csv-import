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
 * The flow every CSV import follows. A subclass describes its file (header and
 * column rules) and its table (model, unique key, update columns) and maps rows.
 */
abstract class CsvImport
{
    public function __construct(
        private readonly CsvValidator $validator = new CsvValidator,
        private readonly CodeLookups $lookups = new CodeLookups,
    ) {}

    /** @return class-string<Model> */
    abstract public function modelClass(): string;

    /** Short name for file names: "cities" for CityImport. */
    public function name(): string
    {
        return Str::snake(Str::beforeLast(class_basename(static::class), 'Import'));
    }

    /**
     * Other columns are ignored unless forbiddenHeaders() names them.
     *
     * @return list<string>
     */
    abstract public function requiredHeaders(): array;

    /**
     * column => hint; a file with one is rejected outright.
     *
     * @return array<string, string>
     */
    public function forbiddenHeaders(): array
    {
        return [];
    }

    /** @return list<string> */
    public function requiredColumns(): array
    {
        return [];
    }

    /**
     * Both "1234.56" and "1234,56" count.
     *
     * @return list<string>
     */
    public function numericColumns(): array
    {
        return [];
    }

    /** @return list<string> */
    public function positiveColumns(): array
    {
        return [];
    }

    /**
     * column => enum class, matched case-insensitively.
     *
     * @return array<string, class-string<\BackedEnum>>
     */
    public function enumColumns(): array
    {
        return [];
    }

    /**
     * column => "table.column", matched case-insensitively and resolved to an id before map().
     *
     * @return array<string, string>
     */
    public function requiredMatch(): array
    {
        return [];
    }

    /**
     * The cells that identify a failing row in the report.
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
     * $matches holds the id each requiredMatch() cell resolved to, null for an empty cell.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int|null>  $matches
     * @return array<string, mixed>
     */
    abstract public function map(array $row, array $matches): array;

    protected function rowsPerUpsert(): int
    {
        return (int) config('simple-csv-import.rows_per_upsert');
    }

    protected function rowsPerJob(): int
    {
        return (int) config('simple-csv-import.rows_per_job');
    }

    /** Runs in the worker after the last chunk; a throw ends the import as failed. */
    protected function afterImport(): void {}

    /** Validates in the request, then records the rejection or stores and queues the file. */
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

        // No failing rows: drop the header-only report.
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
     * Validates a file and writes the failing rows, without touching the database.
     *
     * @throws InvalidCsvException
     */
    final public function validateFile(string $csvPath, string $reportPath): ValidationResult
    {
        return $this->validator->validate($this, $this->reader($csvPath), $reportPath);
    }

    /** @param  int|null  $rowsPerJob  only tests pass one */
    final public function queue(ImportRun $run, ?int $rowsPerJob = null): void
    {
        $jobs = array_map(
            fn (CsvChunk $chunk) => new ImportCsvChunk(static::class, $run, $chunk),
            $this->storedReader($run)->chunks($rowsPerJob ?? $this->rowsPerJob()),
        );
        $jobs[] = new FinishCsvImport(static::class, $run);

        Bus::chain($jobs)->dispatch();
    }

    final public function importChunk(ImportRun $run, CsvChunk $chunk): void
    {
        $this->upsertRows($this->storedReader($run)->rows($chunk));
    }

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

    /** The chunks before the failing one stay written. */
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

    protected function reader(string $path): CsvReader
    {
        return new CsvReader($path);
    }

    private function storedReader(ImportRun $run): CsvReader
    {
        return $this->reader(Storage::disk(self::disk())->path($run->storedPath));
    }

    /** @param  iterable<array<string, mixed>>  $rows */
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
     * A code that resolved at validation but not now fails the import rather than writing null.
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

    /** @param  list<array<string, mixed>>  $rows */
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

    /** An empty cell, whitespace and "NA" all mean no value. */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || strtoupper($value) === 'NA' ? null : $value;
    }

    /** @param  array<string, mixed>  $row */
    protected static function cell(array $row, string $column): ?string
    {
        return self::normalize($row[$column] ?? null);
    }

    /** @param  array<string, mixed>  $row */
    protected static function int(array $row, string $column): ?int
    {
        $value = self::cell($row, $column);

        return $value === null ? null : (int) $value;
    }

    /**
     * Accepts "1234.56" and "1234,56".
     *
     * @param  array<string, mixed>  $row
     */
    protected static function float(array $row, string $column): ?float
    {
        $value = self::cell($row, $column);

        return $value === null ? null : (float) str_replace(',', '.', $value);
    }
}
