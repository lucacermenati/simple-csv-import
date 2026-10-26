<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

use Illuminate\Support\Facades\DB;

/**
 * Resolves a code a CSV cell carries to the id of the row that holds it in a
 * given table column ("regions.code", "countries.iso"), case-insensitively.
 * Each column's code => id map is loaded once per instance instead of a query
 * per row over a million-row file. The service is scoped in the container, so
 * the validator and the import share one instance per request and per queue
 * job, the way every row of one file does.
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
