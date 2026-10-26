<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaCermenati\SimpleCsvImport\CsvImport;
use LucaCermenati\SimpleCsvImport\ImportRun;
use LucaCermenati\SimpleCsvImport\ImportStatus;
use LucaCermenati\SimpleCsvImport\Models\ImportReport;

/**
 * A queued import ended, succeeded or failed ($report->status); the stored upload
 * is deleted and the report recorded. Dispatched in the queue worker: listen to
 * notify $run->userId, clear caches, and so on.
 */
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
