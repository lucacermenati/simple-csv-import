<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

/** An accepted upload on its way through the queue: what the chunk and finish jobs carry. */
final readonly class ImportRun
{
    /** @param  string  $storedPath  on the configured disk, deleted when the import is done */
    public function __construct(
        public string $storedPath,
        public string $originalFilename,
        public ?int $userId,
        public int $totalRows,
    ) {}
}
