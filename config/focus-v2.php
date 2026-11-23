<?php

return [
    /*
     * Focus Control V2 is opt-in outside local and automated tests.
     *
     * Enable only on an isolated staging installation after checking its
     * APP_URL, database and storage. Production defaults to 404 for every
     * Focus V2 page and API endpoint, even if the code is deployed there.
     */
    'enabled' => (bool) env('FOCUS_V2_ENABLED', app()->environment('local', 'testing')),
];
