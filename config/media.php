<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media import limits (Task 27)
    |--------------------------------------------------------------------------
    |
    | Bounds enforced by App\Services\Media\MediaUploadService::download()
    | for POST /api/media/from-url. Configurable (rather than hardcoded
    | class constants) for two reasons: an operator may legitimately need
    | to tune them without a code change, and tests exercise the
    | size-exceeded branch by overriding `import_max_bytes` to a tiny
    | value rather than generating a genuine 100+ MB fixture on every
    | test run.
    |
    */

    'import_max_bytes' => (int) env('MEDIA_IMPORT_MAX_BYTES', 100 * 1024 * 1024),

    'import_timeout_seconds' => (int) env('MEDIA_IMPORT_TIMEOUT_SECONDS', 30),

    'import_max_redirects' => (int) env('MEDIA_IMPORT_MAX_REDIRECTS', 5),

    'thumbnail_width' => (int) env('MEDIA_THUMBNAIL_WIDTH', 400),

];
