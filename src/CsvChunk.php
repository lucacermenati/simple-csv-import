<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

/**
 * A run of data rows in a CSV, by where it starts. A queued import job seeks to
 * the byte offset instead of parsing every row before it, so the file is read
 * once in total however many chunks it has.
 */
final readonly class CsvChunk
{
    /**
     * @param  int  $offset  byte offset of the first row
     * @param  int  $line  line number of the first row, the header being line 1
     * @param  int  $rows  number of non-blank rows in the chunk
     */
    public function __construct(
        public int $offset,
        public int $line,
        public int $rows,
    ) {}
}
