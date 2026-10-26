<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where the files live
    |--------------------------------------------------------------------------
    |
    | An accepted upload is stored under the uploads directory until the queued
    | import is done; the failing-rows CSV of a rejected upload lives under the
    | reports directory for as long as its report row exists. The disk must be
    | shared by the web request and the queue worker.
    |
    */

    'disk' => env('CSV_IMPORT_DISK', 'local'),

    'uploads_directory' => 'csv-imports',

    'reports_directory' => 'csv-import-reports',

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | Rows per queued job and rows per INSERT ... ON DUPLICATE KEY UPDATE
    | statement. An import may override both. The job timeout must stay below
    | the queue's retry_after, so a slow chunk is never run twice.
    |
    */

    'rows_per_job' => 20_000,

    'rows_per_upsert' => 1_000,

    'job_timeout' => 75,

    /*
    |--------------------------------------------------------------------------
    | Who uploaded
    |--------------------------------------------------------------------------
    |
    | The model behind ImportReport::user(). The report stores the id the
    | application hands to CsvImport::run().
    |
    */

    'user_model' => env('CSV_IMPORT_USER_MODEL', 'App\\Models\\User'),

];
