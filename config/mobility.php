<?php

return [
    'strike_source_url' => env('MOBILITY_STRIKE_SOURCE_URL', 'https://scioperi.mit.gov.it/mit2/public/scioperi'),
    // Enable only after the dedicated strike destination has been checked against the duty topic.
    'strike_notifications_enabled' => env('MOBILITY_STRIKE_NOTIFICATIONS_ENABLED', false),
    'strike_notifications_active_since' => env('MOBILITY_STRIKE_NOTIFICATIONS_ACTIVE_SINCE'),
    'regions' => ['lombardia'],
    'provinces' => ['milano'],
    'operators' => ['trenord', 'atm di milano', 'gruppo atm'],
    'sectors' => ['ferroviario', 'trasporto pubblico locale', 'generale', 'plurisettoriale'],
];
