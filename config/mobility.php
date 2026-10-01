<?php

return [
    'strike_source_url' => env('MOBILITY_STRIKE_SOURCE_URL', 'https://scioperi.mit.gov.it/mit2/public/scioperi'),
    'regions' => ['lombardia'],
    'provinces' => ['milano'],
    'operators' => ['trenord', 'atm di milano', 'gruppo atm'],
    'sectors' => ['ferroviario', 'trasporto pubblico locale', 'generale', 'plurisettoriale'],
];
