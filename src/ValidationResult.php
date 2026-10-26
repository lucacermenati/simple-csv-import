<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

final readonly class ValidationResult
{
    /**
     * @param  array<string, int>  $counts  failing rows per Verdict value
     */
    public function __construct(
        public int $totalRows,
        public array $counts,
    ) {}

    public function count(Verdict $verdict): int
    {
        return $this->counts[$verdict->value] ?? 0;
    }

    public function failedRows(): int
    {
        return array_sum($this->counts);
    }

    public function hasFailures(): bool
    {
        return $this->failedRows() > 0;
    }

    /**
     * One line for an admin, e.g. "413,241 rows, 14 failed."
     */
    public function summary(): string
    {
        return sprintf('%s rows, %s failed.', number_format($this->totalRows), number_format($this->failedRows()));
    }
}
