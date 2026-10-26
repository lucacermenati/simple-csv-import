<?php

declare(strict_types=1);

return [

    // Uploads and failing-rows reports live on this disk, which the web request
    // and the queue worker must share.
    'disk' => env('CSV_IMPORT_DISK', 'local'),

    'uploads_directory' => 'csv-imports',

    'reports_directory' => 'csv-import-reports',

    // Rows per queued job and per upsert statement; an import may override both.
    'rows_per_job' => 20_000,

    'rows_per_upsert' => 1_000,

    // Keep below the queue's retry_after, so a slow chunk never runs twice.
    'job_timeout' => 75,

    // The model behind ImportReport::user().
    'user_model' => env('CSV_IMPORT_USER_MODEL', 'App\\Models\\User'),

];
