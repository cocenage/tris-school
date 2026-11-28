<?php

return [
    'package' => env('TRIS_ANDROID_APP_LINK_PACKAGE'),
    // Comma-separated SHA-256 signing certificate fingerprints, not private keys.
    'fingerprints' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRIS_ANDROID_APP_LINK_SHA256', ''))))),
];
