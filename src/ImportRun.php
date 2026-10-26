<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

/**
 * One accepted upload on its way through the queue: where the file is stored,
 * who uploaded it and what validation counted. The import object itself holds
 * no upload state and is never serialized; this is what the chunk and finish
 * jobs carry, and what the succeeded or failed report is written from.
 */
final readonly class ImportRun
{
    /**
     * @param  string  $storedPath  the uploaded CSV on the configured disk, deleted when the import is done
     * @param  string  $originalFilename  the name the user uploaded, recorded on the report
     * @param  int|null  $userId  who uploaded, recorded on the report and handed to the events
     * @param  int  $totalRows  the rows validation counted, recorded on the report
     */
    public function __construct(
        public string $storedPath,
        public string $originalFilename,
        public ?int $userId,
        public int $totalRows,
    ) {}
}
