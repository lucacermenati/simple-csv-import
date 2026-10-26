<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One row per upload: invalid, succeeded or failed. Never pruned by the package. */
    public function up(): void
    {
        Schema::create('csv_import_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('import');
            $table->string('status', 20);
            $table->string('original_filename');
            $table->string('report_path')->nullable();
            $table->text('reason')->nullable();
            // Failing rows per Verdict value.
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
