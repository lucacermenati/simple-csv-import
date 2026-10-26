<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

/** A run of data rows by byte offset, so a job seeks instead of parsing the rows before it. */
final readonly class CsvChunk
{
    /**
     * @param  int  $offset  byte offset of the first row
     * @param  int  $line  line number of the first row, the header being line 1
     * @param  int  $rows  non-blank rows in the chunk
     */
    public function __construct(
        public int $offset,
        public int $line,
        public int $rows,
    ) {}
}
