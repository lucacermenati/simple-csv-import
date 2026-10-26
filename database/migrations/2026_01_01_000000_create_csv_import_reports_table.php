<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per CSV upload: rejected by validation, imported, or failed
     * (ImportStatus). Only a rejected upload with failing rows has a report
     * file. Rows are the import history and are never pruned by the package.
     */
    public function up(): void
    {
        Schema::create('csv_import_reports', function (Blueprint $table): void {
            $table->id();
            // The CsvImport subclass, so the application can label and link it.
            $table->string('import');
            $table->string('status', 20);
            $table->string('original_filename');
            $table->string('report_path')->nullable();
            $table->text('reason')->nullable();
            // Failing rows per Verdict value; the summary line is derived from these two.
            $table->json('counts');
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('failed_rows');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_reports');
    }
};
