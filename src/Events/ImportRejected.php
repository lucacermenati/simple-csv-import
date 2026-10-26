<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaCermenati\SimpleCsvImport\CsvImport;
use LucaCermenati\SimpleCsvImport\Models\ImportReport;

/** Dispatched in the request when validation rejects an upload; nothing was imported. */
final class ImportRejected
{
    use Dispatchable;

    public function __construct(
        public readonly CsvImport $import,
        public readonly ImportReport $report,
    ) {}
}
