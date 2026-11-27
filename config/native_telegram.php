<?php

return [
    // Keep the experimental SDK token exchange isolated from production.
    'enabled' => env('TRIS_NATIVE_TELEGRAM_ENABLED', false),
    'client_id' => env('TRIS_NATIVE_TELEGRAM_CLIENT_ID'),
];
