<?php

namespace App\Services\MobilePush;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;

class FcmTransport
{
    public function send(string $token, string $id): array
    {
        try {
            $project = config('mobile_push.project_id');
            $path = config('mobile_push.credentials');
            if (! is_string($project) || ! preg_match('/^[a-z0-9][a-z0-9-]{3,62}$/D', $project)
                || ! is_string($path) || ! is_readable($path)) {
                return ['status' => 'failed', 'error' => 'FCM_NOT_CONFIGURED'];
            }

            // The official Google Auth library is installed only for this DEV tool.
            $autoload = base_path('tools/mobile/fcm/vendor/autoload.php');
            if (! class_exists(ServiceAccountCredentials::class) && is_file($autoload)) {
                require_once $autoload;
            }
            if (! class_exists(ServiceAccountCredentials::class)) {
                return ['status' => 'failed', 'error' => 'GOOGLE_AUTH_MISSING'];
            }
            $credentials = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (($credentials['type'] ?? null) !== 'service_account'
                || ($credentials['project_id'] ?? null) !== $project) {
                return ['status' => 'failed', 'error' => 'FCM_PROJECT_MISMATCH'];
            }
            // Do not allow a credential file to override the OAuth host.
            unset($credentials['token_uri']);
            $oauth = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/firebase.messaging'], $credentials
            );
            $auth = $oauth->fetchAuthToken();
            if (! is_string($auth['access_token'] ?? null) || $auth['access_token'] === '') {
                return ['status' => 'failed', 'error' => 'FCM_AUTH_FAILED'];
            }

            $response = Http::withToken($auth['access_token'])->acceptJson()
                ->connectTimeout(5)->timeout(15)
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => 'TRIS DEV',
                            'body' => 'Тестовое уведомление. Нажмите, чтобы открыть историю.',
                        ],
                        'data' => ['kind' => 'tris_test', 'notification_id' => $id],
                        'android' => ['priority' => 'high', 'ttl' => '300s'],
                    ],
                ]);
            if ($response->successful() && is_string($response->json('name'))) {
                return ['status' => 'accepted', 'error' => null];
            }
            $details = $response->json('error.details', []);
            foreach (is_array($details) ? $details : [] as $detail) {
                if (($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                    return ['status' => 'failed', 'error' => 'UNREGISTERED'];
                }
            }

            return ['status' => 'failed', 'error' => 'FCM_HTTP_'.$response->status()];
        } catch (\Throwable) {
            // Avoid leaking OAuth assertions, registration tokens and keys into logs.
            return ['status' => 'failed', 'error' => 'FCM_TRANSPORT_FAILED'];
        }
    }
}
