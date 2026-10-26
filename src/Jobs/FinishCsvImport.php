<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LucaCermenati\SimpleCsvImport\CsvImport;
use LucaCermenati\SimpleCsvImport\ImportRun;

/**
 * The last job of an import chain: runs the import's afterImport() hook, deletes
 * the stored upload and records the succeeded report (CsvImport::finish()).
 */
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
