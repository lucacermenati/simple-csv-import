<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

use LucaCermenati\SimpleCsvImport\Models\ImportReport;

/**
 * What CsvImport::run() tells the caller about an upload: either validation
 * rejected it and the invalid ImportReport says why, or it was clean and is
 * queued. A queued upload has no report yet; the worker records one when it is
 * done.
 */
final readonly class ImportOutcome
{
    public function __construct(
        public ValidationResult $validation,
        public ?ImportReport $importReport = null,
    ) {}

    public function isInvalid(): bool
    {
        return $this->importReport !== null;
    }

    /**
     * The one line the user reads after uploading, e.g. "413,241 rows, 14 failed. ..."
     */
    public function message(): string
    {
        if ($this->importReport === null) {
            return $this->validation->summary().' Import queued.';
        }

        // A file that could not be validated at all has no failing-rows CSV; its reason says what was wrong.
        if ($this->importReport->report_path === null) {
            return (string) $this->importReport->reason;
        }

        return $this->validation->summary().' Nothing was imported; the report lists the failing rows.';
    }
}
