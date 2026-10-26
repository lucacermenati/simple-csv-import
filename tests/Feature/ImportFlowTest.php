<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use LucaCermenati\SimpleCsvImport\Events\ImportFinished;
use LucaCermenati\SimpleCsvImport\Events\ImportRejected;
use LucaCermenati\SimpleCsvImport\ImportRun;
use LucaCermenati\SimpleCsvImport\ImportStatus;
use LucaCermenati\SimpleCsvImport\Models\ImportReport;
use LucaCermenati\SimpleCsvImport\Tests\Fixtures\City;
use LucaCermenati\SimpleCsvImport\Tests\Fixtures\CityImport;
use LucaCermenati\SimpleCsvImport\Tests\Fixtures\Country;
use LucaCermenati\SimpleCsvImport\Tests\Fixtures\User;
use LucaCermenati\SimpleCsvImport\Tests\TestCase;
use RuntimeException;

class ImportFlowTest extends TestCase
{
    private const HEADER = 'name;country;population;climate';

    private Country $netherlands;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Event::fake([ImportRejected::class, ImportFinished::class]);

        $this->netherlands = Country::query()->create(['iso' => 'NL', 'name' => 'Netherlands']);
        $this->admin = User::query()->create(['name' => 'Admin']);
    }

    public function test_a_clean_file_is_queued_imported_and_recorded_as_succeeded(): void
    {
        $outcome = (new CityImport)->run($this->csv([
            self::HEADER,
            'Amsterdam;NL;900000;Temperate',
            'Utrecht;nl;;',
        ]), $this->admin->id);

        $this->assertFalse($outcome->isInvalid());
        $this->assertSame('2 rows, 0 failed. Import queued.', $outcome->message());

        // The test queue is sync, so the chain has already run.
        $this->assertSame(
            ['Amsterdam' => 900000, 'Utrecht' => null],
            City::query()->orderBy('name')->pluck('population', 'name')->all(),
        );
        $this->assertSame('temperate', City::query()->where('name', 'Amsterdam')->value('climate'));

        $report = ImportReport::query()->sole();
        $this->assertSame(CityImport::class, $report->import);
        $this->assertSame(ImportStatus::Succeeded, $report->status);
        $this->assertSame('cities.csv', $report->original_filename);
        $this->assertSame(2, $report->total_rows);
        $this->assertSame($this->admin->id, $report->user_id);
        $this->assertTrue($report->user->is($this->admin));
        $this->assertSame([], Storage::disk('local')->files('csv-imports'));

        Event::assertDispatched(ImportFinished::class, fn (ImportFinished $event) => $event->succeeded()
            && $event->run->userId === $this->admin->id
            && $event->report->is($report));
    }

    public function test_a_file_with_failing_rows_is_not_imported_and_gets_a_report(): void
    {
        $outcome = (new CityImport)->run($this->csv([
            self::HEADER,
            'Amsterdam;NL;900000;temperate', // 2: fine
            'Paris;FR;2000000;',             // 3: unknown country
            ';NL;100;',                      // 4: no name
            'Leiden;NL;abc;',                // 5: population not a number
            'Delft;NL;0;',                   // 6: population not above zero
            'Breda;NL;180000;arctic',        // 7: unknown climate
        ]), $this->admin->id);

        $report = $outcome->importReport;

        $this->assertTrue($outcome->isInvalid());
        $this->assertSame('6 rows, 5 failed. Nothing was imported; the report lists the failing rows.', $outcome->message());
        $this->assertSame(ImportStatus::Invalid, $report->status);
        $this->assertSame(['missing_required' => 1, 'invalid_value' => 3, 'unknown_code' => 1], $report->counts);
        $this->assertSame(5, $report->failed_rows);
        $this->assertSame($this->admin->id, $report->user_id);
        $this->assertSame(0, City::query()->count());

        $lines = array_values(array_filter(explode("\n", Storage::disk('local')->get($report->report_path))));
        $this->assertSame('line,verdict,name,country,population,details', $lines[0]);
        $this->assertSame('3,unknown_code,Paris,FR,2000000,"country: FR not found"', $lines[1]);
        $this->assertSame('4,missing_required,,NL,100,"name: empty"', $lines[2]);
        $this->assertSame('5,invalid_value,Leiden,NL,abc,"population: not a number, got abc"', $lines[3]);
        $this->assertSame('6,invalid_value,Delft,NL,0,"population: must be above zero, got 0"', $lines[4]);
        $this->assertSame('7,invalid_value,Breda,NL,180000,"climate: unknown value, got arctic (allowed: temperate, tropical)"', $lines[5]);

        Event::assertDispatched(ImportRejected::class, fn (ImportRejected $event) => $event->report->is($report));
        Event::assertNotDispatched(ImportFinished::class);
    }

    public function test_a_file_with_the_wrong_columns_is_rejected_outright(): void
    {
        $outcome = (new CityImport)->run($this->csv(['iso;name', 'NL;Netherlands']));

        $this->assertTrue($outcome->isInvalid());
        $this->assertSame('The CSV is missing the column(s): country, population.', $outcome->message());
        $this->assertNull($outcome->importReport->report_path);
        $this->assertSame(0, $outcome->importReport->total_rows);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_file_of_another_import_is_rejected_by_its_headers(): void
    {
        $outcome = (new CityImport)->run($this->csv(['iso;name;country;population', 'NL;Netherlands;NL;1']));

        $this->assertSame('The CSV has the column(s) iso, which do not belong to this import. It looks like a countries file.', $outcome->message());
    }

    public function test_a_file_larger_than_one_chunk_is_imported_in_full(): void
    {
        Storage::disk('local')->put('csv-imports/upload.csv', implode("\n", [
            self::HEADER,
            'Amsterdam;NL;900000;',
            '',
            'Utrecht;NL;360000;',
            'Leiden;NL;125000;',
        ]));

        // Two rows per job: the second job must seek past the first two rows and the blank line.
        (new CityImport)->queue(new ImportRun('csv-imports/upload.csv', 'cities.csv', null, 3), 2);

        $this->assertSame(['Amsterdam', 'Leiden', 'Utrecht'], City::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(ImportStatus::Succeeded, ImportReport::query()->sole()->status);
    }

    public function test_a_code_that_disappeared_between_validation_and_import_fails_the_import(): void
    {
        Storage::disk('local')->put('csv-imports/upload.csv', self::HEADER."\nParis;FR;2000000;\n");

        $this->assertThrows(
            fn () => (new CityImport)->queue(new ImportRun('csv-imports/upload.csv', 'cities.csv', $this->admin->id, 1)),
            RuntimeException::class,
            'country: FR no longer exists.',
        );

        $report = ImportReport::query()->sole();
        $this->assertSame(ImportStatus::Failed, $report->status);
        $this->assertSame('country: FR no longer exists.', $report->reason);
        $this->assertSame([], Storage::disk('local')->files('csv-imports'));
        Event::assertDispatched(ImportFinished::class, fn (ImportFinished $event) => ! $event->succeeded());
    }

    public function test_re_importing_updates_the_update_columns_only(): void
    {
        City::query()->create(['name' => 'Amsterdam', 'country_id' => $this->netherlands->id, 'population' => 1]);

        (new CityImport)->run($this->csv([self::HEADER, 'Amsterdam;NL;900000;tropical']));

        $this->assertSame(1, City::query()->count());
        $this->assertSame(900000, City::query()->sole()->population);
    }

    public function test_the_dry_run_command_reports_without_writing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-');
        file_put_contents($path, self::HEADER."\nAmsterdam;NL;900000;\nParis;FR;1;\n");

        $this->artisan('csv-import:dry-run', ['import' => CityImport::class, 'file' => $path, '--detail' => $path.'.report.csv'])
            ->expectsOutputToContain('2 rows, 1 failed.')
            ->assertExitCode(0);

        $this->assertStringContainsString('3,unknown_code,Paris,FR,1,"country: FR not found"', file_get_contents($path.'.report.csv'));
        $this->assertSame(0, City::query()->count());
        $this->assertSame(0, ImportReport::query()->count());

        @unlink($path);
        @unlink($path.'.report.csv');
    }

    /**
     * @param  list<string>  $lines
     */
    private function csv(array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cities.csv', implode("\n", $lines)."\n");
    }
}
