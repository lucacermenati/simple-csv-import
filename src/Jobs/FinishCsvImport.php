<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LucaCermenati\SimpleCsvImport\CsvImport;
use LucaCermenati\SimpleCsvImport\ImportRun;

/** Closes the chain: afterImport(), delete the upload, record the succeeded report. */
final class FinishCsvImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  class-string<CsvImport>  $importClass
     */
    public function __construct(
        public readonly string $importClass,
        public readonly ImportRun $run,
    ) {}

    public function handle(): void
    {
        app($this->importClass)->finish($this->run);
    }
}
