<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaCermenati\SimpleCsvImport\CsvImport;
use LucaCermenati\SimpleCsvImport\ImportRun;
use LucaCermenati\SimpleCsvImport\ImportStatus;
use LucaCermenati\SimpleCsvImport\Models\ImportReport;

/** Dispatched in the worker when a queued import ends; the upload is deleted and the report recorded. */
final class ImportFinished
{
    use Dispatchable;

    public function __construct(
        public readonly CsvImport $import,
        public readonly ImportRun $run,
        public readonly ImportReport $report,
    ) {}

    public function succeeded(): bool
    {
        return $this->report->status === ImportStatus::Succeeded;
    }
}
