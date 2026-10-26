<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

use Illuminate\Support\Facades\DB;

/**
 * Resolves a code to the id of the row holding it in "table.column", case-insensitively.
 * Each map is loaded once per instance; the service is scoped per request and job.
 */
final class CodeLookups
{
    /** @var array<string, array<string, int>> reference => (upper-cased code => id) */
    private array $maps = [];

    /**
     * @param  string  $reference  "table.column"
     */
    public function id(string $reference, ?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        return $this->map($reference)[strtoupper($code)] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private function map(string $reference): array
    {
        if (! isset($this->maps[$reference])) {
            [$table, $column] = explode('.', $reference, 2);

            $this->maps[$reference] = array_change_key_case(
                DB::table($table)->whereNotNull($column)->pluck('id', $column)->all(),
                CASE_UPPER,
            );
        }

        return $this->maps[$reference];
    }
}
