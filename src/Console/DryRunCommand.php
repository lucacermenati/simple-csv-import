<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Console;

use Illuminate\Console\Command;
use LucaCermenati\SimpleCsvImport\CsvImport;
use LucaCermenati\SimpleCsvImport\InvalidCsvException;
use LucaCermenati\SimpleCsvImport\Verdict;

class DryRunCommand extends Command
{
    protected $signature = 'csv-import:dry-run
        {import : The import class, e.g. "App\\Imports\\CityImport"}
        {file : Path to the CSV file}
        {--detail= : Path for the failing-rows report CSV (default: <file>.dry-run.csv)}';

    protected $description = 'Check a CSV the way the upload does, without writing anything';

    public function handle(): int
    {
        $importClass = (string) $this->argument('import');
        $file = (string) $this->argument('file');

        if (! is_subclass_of($importClass, CsvImport::class)) {
            $this->error("[{$importClass}] is not a ".CsvImport::class.'.');

            return self::FAILURE;
        }

        $reportPath = (string) ($this->option('detail') ?: $file.'.dry-run.csv');

        try {
            $result = app($importClass)->validateFile($file, $reportPath);
        } catch (InvalidCsvException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['failure', 'rows'],
            array_map(fn (Verdict $verdict) => [$verdict->label(), number_format($result->count($verdict))], Verdict::cases()),
        );
        $this->info($result->summary().' Failing rows written to '.$reportPath.'.');
        $this->warn('Dry run only: the database was not modified.');

        return self::SUCCESS;
    }
}
