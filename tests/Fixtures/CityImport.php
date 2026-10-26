<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Tests\Fixtures;

use LucaCermenati\SimpleCsvImport\CsvImport;

/** The smallest import that exercises every rule. */
class CityImport extends CsvImport
{
    public function modelClass(): string
    {
        return City::class;
    }

    public function requiredHeaders(): array
    {
        return ['name', 'country', 'population'];
    }

    public function forbiddenHeaders(): array
    {
        return ['iso' => 'It looks like a countries file.'];
    }

    public function requiredColumns(): array
    {
        return ['name', 'country'];
    }

    public function numericColumns(): array
    {
        return ['population'];
    }

    public function positiveColumns(): array
    {
        return ['population'];
    }

    public function enumColumns(): array
    {
        return ['climate' => Climate::class];
    }

    public function requiredMatch(): array
    {
        return ['country' => 'countries.iso'];
    }

    public function uniqueBy(): array
    {
        return ['name', 'country_id'];
    }

    public function upsertColumns(): array
    {
        return ['population', 'climate'];
    }

    public function map(array $row, array $matches): array
    {
        $climate = self::cell($row, 'climate');

        return [
            'name' => self::cell($row, 'name'),
            'country_id' => $matches['country'],
            'population' => self::int($row, 'population'),
            'climate' => $climate === null ? null : strtolower($climate),
        ];
    }
}
