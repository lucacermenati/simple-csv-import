<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LucaCermenati\SimpleCsvImport\CsvChunk;
use LucaCermenati\SimpleCsvImport\CsvImport;
use LucaCermenati\SimpleCsvImport\ImportRun;
use Throwable;

/**
 * Upserts one chunk of a queued CSV import. The chunks of a file run as a chain
 * closed by FinishCsvImport, so a failing chunk stops the import and records the
 * failure.
 */
final class ImportCsvChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    /**
     * @param  class-string<CsvImport>  $importClass
     */
    public function __construct(
        public readonly string $importClass,
        public readonly ImportRun $run,
        public readonly CsvChunk $chunk,
    ) {
        $this->timeout = (int) config('simple-csv-import.job_timeout');
    }

    public function handle(): void
    {
        $this->import()->importChunk($this->run, $this->chunk);
    }

    public function failed(?Throwable $exception): void
    {
        $this->import()->fail($this->run, $exception?->getMessage());
    }

    private function import(): CsvImport
    {
        return app($this->importClass);
    }
}
