<?php

return [
    'files_enabled' => env('AUDIT_FILES_ENABLED', true),
    'path' => env('AUDIT_FILE_PATH', storage_path('app/private/audit')),
];
