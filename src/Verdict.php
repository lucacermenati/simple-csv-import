<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

/**
 * Why a CSV row fails validation. The string values are written to the report
 * CSV, so they are part of the output format.
 */
enum Verdict: string
{
    case MissingRequired = 'missing_required';
    case InvalidValue = 'invalid_value';
    case UnknownCode = 'unknown_code';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::MissingRequired => 'missing required value',
            self::InvalidValue => 'invalid value',
            self::UnknownCode => 'unknown code',
        };
    }
}
