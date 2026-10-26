<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

use LucaCermenati\SimpleCsvImport\Models\ImportReport;

/** What run() returns: validation rejected the upload (importReport says why), or it is queued. */
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

    /** The one line the user reads after uploading. */
    public function message(): string
    {
        if ($this->importReport === null) {
            return $this->validation->summary().' Import queued.';
        }

        // Rejected outright: no failing-rows CSV, the reason says why.
        if ($this->importReport->report_path === null) {
            return (string) $this->importReport->reason;
        }

        return $this->validation->summary().' Nothing was imported; the report lists the failing rows.';
    }
}
