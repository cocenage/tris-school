<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class AndroidAssetLinksController
{
    public function __invoke(): JsonResponse
    {
        $package = config('android_app_links.package');
        $fingerprints = config('android_app_links.fingerprints', []);

        abort_unless(is_string($package) && preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/D', $package), 404);
        abort_unless(is_array($fingerprints) && count($fingerprints) > 0, 404);

        foreach ($fingerprints as $fingerprint) {
            abort_unless(is_string($fingerprint) && preg_match('/^(?:[A-Fa-f0-9]{2}:){31}[A-Fa-f0-9]{2}$/D', $fingerprint), 404);
        }

        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => array_values(array_unique(array_map('strtoupper', $fingerprints))),
            ],
        ]])->header('Cache-Control', 'public, max-age=300');
    }
}
