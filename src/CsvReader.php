<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

use Generator;
use Illuminate\Support\Str;

/**
 * Streams a CSV row by row, keyed by its slugged header ("Risk Category" is
 * keyed "risk_category"). The delimiter is the candidate that occurs most often
 * in the header line, outside quotes; a comma when none does.
 */
final class CsvReader
{
    private const DELIMITERS = [',', ';', "\t", '|'];

    private readonly string $delimiter;

    /** @var list<string> */
    private readonly array $headers;

    public function __construct(private readonly string $path)
    {
        $handle = $this->open();
        $firstLine = (string) fgets($handle);
        fclose($handle);

        $this->delimiter = self::inferDelimiter($firstLine);
        $this->headers = array_map(
            fn (?string $heading) => Str::slug((string) $heading, '_'),
            str_getcsv($firstLine, $this->delimiter, '"', '\\'),
        );
    }

    private static function inferDelimiter(string $line): string
    {
        $unquoted = preg_replace('/"[^"]*"/', '', $line) ?? $line;
        $counts = array_map(fn (string $delimiter) => substr_count($unquoted, $delimiter), array_combine(self::DELIMITERS, self::DELIMITERS));

        // The first candidate wins a tie, so a line without any delimiter yields a comma.
        return (string) array_search(max($counts), $counts, true);
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * Header cells as the import sees them, e.g. "Risk Category" becomes "risk_category".
     *
     * @return list<string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @param  list<string>  $required
     *
     * @throws InvalidCsvException
     */
    public function assertHeaders(array $required): void
    {
        $missing = array_values(array_diff($required, $this->headers));

        if ($missing !== []) {
            throw new InvalidCsvException('The CSV is missing the column(s): '.implode(', ', $missing).'.');
        }
    }

    /**
     * @param  list<string>  $forbidden
     *
     * @throws InvalidCsvException
     */
    public function assertNotHeaders(array $forbidden, string $hint): void
    {
        $present = array_values(array_intersect($forbidden, $this->headers));

        if ($present !== []) {
            throw new InvalidCsvException('The CSV has the column(s) '.implode(', ', $present).', which do not belong to this import. '.$hint);
        }
    }

    /**
     * Yields line number => row. The header is line 1, so the first row is line 2.
     * Blank lines are skipped; short rows are padded with null, long rows truncated.
     * Given a chunk, seeks to its first row and stops after its last.
     *
     * @return Generator<int, array<string, ?string>>
     */
    public function rows(?CsvChunk $chunk = null): Generator
    {
        $columnCount = count($this->headers);
        $handle = $this->open();

        if ($chunk === null) {
            fgets($handle);
            $line = 1;
            $remaining = PHP_INT_MAX;
        } else {
            fseek($handle, $chunk->offset);
            $line = $chunk->line - 1;
            $remaining = $chunk->rows;
        }

        while ($remaining > 0 && ($values = fgetcsv($handle, null, $this->delimiter, '"', '\\')) !== false) {
            $line++;

            if (self::isBlank($values)) {
                continue;
            }

            if (count($values) !== $columnCount) {
                $values = array_slice(array_pad($values, $columnCount, null), 0, $columnCount);
            }

            $remaining--;

            yield $line => array_combine($this->headers, $values);
        }

        fclose($handle);
    }

    /**
     * Cuts the data rows into runs of at most $rows rows, reading the file once.
     * Blank lines count for nothing, so a chunk holds $rows rows the import will see.
     *
     * @return list<CsvChunk>
     */
    public function chunks(int $rows): array
    {
        $handle = $this->open();

        fgets($handle);
        $line = 1;
        $count = 0;
        $starts = [];

        for ($offset = ftell($handle); ($values = fgetcsv($handle, null, $this->delimiter, '"', '\\')) !== false; $offset = ftell($handle)) {
            $line++;

            if (self::isBlank($values)) {
                continue;
            }

            if ($count % $rows === 0) {
                $starts[] = [$offset, $line];
            }

            $count++;
        }

        fclose($handle);

        return array_map(
            fn (array $start, int $index) => new CsvChunk($start[0], $start[1], min($rows, $count - $index * $rows)),
            $starts,
            array_keys($starts),
        );
    }

    /**
     * fgetcsv() returns a single null cell for a blank line.
     *
     * @param  array<int, ?string>  $values
     */
    private static function isBlank(array $values): bool
    {
        return count($values) === 1 && $values[0] === null;
    }

    /**
     * @return resource
     */
    private function open()
    {
        $handle = is_readable($this->path) ? fopen($this->path, 'r') : false;

        if ($handle === false) {
            throw new InvalidCsvException("Cannot read file [{$this->path}].");
        }

        return $handle;
    }
}
