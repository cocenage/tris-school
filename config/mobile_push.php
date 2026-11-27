<?php

return [
    // DEV-only until native session revocation and delivery policy are complete.
    'enabled' => env('TRIS_PUSH_ENABLED', false),
    'project_id' => env('TRIS_PUSH_PROJECT_ID'),
    'credentials' => storage_path('app/mobile-dev/firebase-service-account.json'),
];
