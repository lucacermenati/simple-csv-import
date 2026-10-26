<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use LucaCermenati\SimpleCsvImport\CsvImport;
use LucaCermenati\SimpleCsvImport\ImportStatus;
use LucaCermenati\SimpleCsvImport\ValidationResult;

/**
 * One CSV upload that was rejected, imported or failed (ImportStatus): which
 * import, how many rows it held and how many failed validation. A failed import
 * carries the exception message as its reason, a rejected upload why it was
 * rejected. Only an upload with failing rows has a report CSV, listing them with
 * their line number and the reason; deleting a report deletes its file.
 *
 * @property int $id
 * @property class-string<CsvImport> $import
 * @property ImportStatus $status
 * @property string $original_filename
 * @property string|null $report_path
 * @property string|null $reason
 * @property array<string, int> $counts
 * @property int $total_rows
 * @property int $failed_rows
 * @property int|null $user_id
 * @property-read string $summary
 */
class ImportReport extends Model
{
    protected $table = 'csv_import_reports';

    protected $fillable = [
        'import',
        'status',
        'original_filename',
        'report_path',
        'reason',
        'counts',
        'total_rows',
        'failed_rows',
        'user_id',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $report): void {
            if ($report->report_path !== null) {
                Storage::disk(CsvImport::disk())->delete($report->report_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'counts' => 'array',
        ];
    }

    /**
     * The one-line summary a user reads, e.g. "12 rows, 2 failed."
     */
    protected function summary(): Attribute
    {
        return Attribute::get(fn () => (new ValidationResult($this->total_rows, $this->counts ?? []))->summary());
    }

    /**
     * A fresh instance of the import that produced this report.
     */
    public function importer(): CsvImport
    {
        return app($this->import);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo((string) config('simple-csv-import.user_model'));
    }
}
