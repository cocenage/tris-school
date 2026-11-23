<?php

return [
    /*
     * Vue 3 Focus Control is the public entry for active admins/supervisors.
     * The Classic Focus route remains available as a fallback.
     *
     * Emergency rollback without code changes:
     * FOCUS_V2_ENABLED=false, then php artisan optimize:clear.
     * This disables all V2 API endpoints and makes the checks landing page
     * point back to Classic Focus. Never bypass per-user authorization.
     */
    'enabled' => (bool) env('FOCUS_V2_ENABLED', true),
];
