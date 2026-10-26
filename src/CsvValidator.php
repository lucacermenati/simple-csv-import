<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

use RuntimeException;

/**
 * Checks an uploaded CSV before anything is imported, and writes the rows that
 * would fail to a report CSV.
 *
 * A row fails when a code is not found in the table column it must match, when
 * a required cell is empty, when a positive-number cell is not above zero, when
 * a numeric cell is not a number, or when an enum cell has an unknown value.
 * The rules themselves live on the import as lists; this class only applies
 * them, reading the file once and holding nothing per row in memory, and never
 * calls map(). A unique key that occurs twice in a file is not a failure: the
 * import upserts both and the last row wins.
 */
final class CsvValidator
{
    public function __construct(private readonly CodeLookups $lookups = new CodeLookups) {}

    /**
     * @throws InvalidCsvException when the file is unreadable or has the wrong columns
     */
    public function validate(CsvImport $import, CsvReader $reader, string $reportPath): ValidationResult
    {
        $reader->assertHeaders($import->requiredHeaders());

        foreach ($import->forbiddenHeaders() as $column => $hint) {
            $reader->assertNotHeaders([$column], $hint);
        }

        $reportColumns = $import->reportColumns();
        $report = fopen($reportPath, 'w') ?: throw new RuntimeException("Cannot write report to [{$reportPath}].");
        fputcsv($report, ['line', 'verdict', ...$reportColumns, 'details'], ',', '"', '\\');

        $counts = array_fill_keys(Verdict::values(), 0);
        $totalRows = 0;

        foreach ($reader->rows() as $line => $row) {
            $totalRows++;
            $failure = $this->check($import, $row);

            if ($failure === null) {
                continue;
            }

            [$verdict, $details] = $failure;
            $counts[$verdict->value]++;

            $cells = array_map(fn (string $column) => CsvImport::normalize($row[$column] ?? null), $reportColumns);
            fputcsv($report, [$line, $verdict->value, ...$cells, $details], ',', '"', '\\');
        }

        fclose($report);

        return new ValidationResult($totalRows, $counts);
    }

    /**
     * @param  array<string, ?string>  $row
     * @return array{Verdict, string}|null the verdict and the details for the report, or null for a good row
     */
    private function check(CsvImport $import, array $row): ?array
    {
        foreach ($import->requiredMatch() as $column => $reference) {
            $cell = CsvImport::normalize($row[$column] ?? null);

            if ($cell !== null && $this->lookups->id($reference, $cell) === null) {
                return [Verdict::UnknownCode, "{$column}: {$cell} not found"];
            }
        }

        $problems = [];

        foreach ($import->requiredColumns() as $column) {
            if (CsvImport::normalize($row[$column] ?? null) === null) {
                $problems[$column] = "{$column}: empty";
            }
        }

        $verdict = $problems === [] ? null : Verdict::MissingRequired;

        foreach ($import->numericColumns() as $column) {
            $cell = CsvImport::normalize($row[$column] ?? null);

            if ($cell !== null && ! isset($problems[$column]) && ! is_numeric(str_replace(',', '.', $cell))) {
                $problems[$column] = "{$column}: not a number, got {$cell}";
                $verdict ??= Verdict::InvalidValue;
            }
        }

        foreach ($import->positiveColumns() as $column) {
            $cell = CsvImport::normalize($row[$column] ?? null);

            if ($cell !== null && ! isset($problems[$column]) && (float) str_replace(',', '.', $cell) <= 0) {
                $problems[$column] = "{$column}: must be above zero, got {$cell}";
                $verdict ??= Verdict::InvalidValue;
            }
        }

        // Case-insensitive, like the ENUM columns the values end up in.
        foreach ($import->enumColumns() as $column => $enum) {
            $cell = CsvImport::normalize($row[$column] ?? null);
            $allowed = array_map(fn (\BackedEnum $case) => (string) $case->value, $enum::cases());

            if ($cell !== null && ! isset($problems[$column]) && ! self::isOneOf($cell, $allowed)) {
                $problems[$column] = "{$column}: unknown value, got {$cell} (allowed: ".implode(', ', $allowed).')';
                $verdict ??= Verdict::InvalidValue;
            }
        }

        return $problems === [] ? null : [$verdict, implode('; ', $problems)];
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function isOneOf(string $cell, array $allowed): bool
    {
        foreach ($allowed as $value) {
            if (strcasecmp($cell, $value) === 0) {
                return true;
            }
        }

        return false;
    }
}
