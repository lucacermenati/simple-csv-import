<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaCermenati\SimpleCsvImport\CsvImport;
use LucaCermenati\SimpleCsvImport\Models\ImportReport;

/**
 * Validation rejected an upload in the request; the report says why and lists
 * the failing rows. Nothing was imported.
 */
final class ImportRejected
{
    use Dispatchable;

    public function __construct(
        public readonly CsvImport $import,
        public readonly ImportReport $report,
    ) {}
}
