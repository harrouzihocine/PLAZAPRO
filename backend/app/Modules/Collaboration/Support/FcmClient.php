<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal FCM HTTP v1 sender — no SDK dependency. Authenticates with the
 * Firebase service-account JSON (FIREBASE_CREDENTIALS): a self-signed RS256
 * JWT exchanged at Google's token endpoint for a ~1h access token (cached for
 * 50 min), then POSTs to /v1/projects/{id}/messages:send.
 *
 * Not configured (no credentials file) → configured() is false and every send
 * quietly no-ops: push is an OPTIONAL layer over the bell/broadcast channels,
 * never a hard dependency.
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** @var array{project_id: string, client_email: string, private_key: string}|null|false */
    private array|null|false $credentials = false; // false = not loaded yet

    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * Send one data-only, high-priority message to one device.
     *
     * @param  array<string, string>  $data  string→string map (FCM v1 requirement)
     * @return string 'ok' | 'unregistered' (prune the token) | 'error'
     */
    public function send(string $deviceToken, array $data): string
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return 'error';
        }

        try {
            $accessToken = $this->accessToken($credentials);
        } catch (RuntimeException $e) {
            Log::warning('FCM auth failed: '.$e->getMessage());

            return 'error';
        }

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->post(
                'https://fcm.googleapis.com/v1/projects/'.$credentials['project_id'].'/messages:send',
                [
                    'message' => [
                        'token' => $deviceToken,
                        'data' => $data,
                        // Data-only + high priority: onMessageReceived runs even
                        // with the app in the background, so the shell fully
                        // controls the tray notification (grouping, deep link).
                        'android' => ['priority' => 'high'],
                    ],
                ],
            );

        if ($response->successful()) {
            return 'ok';
        }

        // 404 / UNREGISTERED = the app was uninstalled or the token rotated —
        // callers should delete the row so we stop paying for dead sends.
        $status = $response->json('error.details.0.errorCode') ?? $response->json('error.status');
        if ($response->status() === 404 || in_array($status, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            return 'unregistered';
        }

        Log::warning('FCM send failed', ['status' => $response->status(), 'body' => $response->json()]);

        return 'error';
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string}|null
     */
    private function credentials(): ?array
    {
        if ($this->credentials !== false) {
            return $this->credentials;
        }

        $path = config('services.fcm.credentials');
        if (! $path || ! is_file($path)) {
            return $this->credentials = null;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || ! isset($json['project_id'], $json['client_email'], $json['private_key'])) {
            Log::warning('FIREBASE_CREDENTIALS is not a valid service-account JSON — push disabled.');

            return $this->credentials = null;
        }

        return $this->credentials = [
            'project_id' => (string) $json['project_id'],
            'client_email' => (string) $json['client_email'],
            'private_key' => (string) $json['private_key'],
        ];
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $credentials
     */
    private function accessToken(array $credentials): string
    {
        return Cache::remember('fcm.access_token', 3000, function () use ($credentials) {
            $now = time();
            $jwt = $this->signedJwt([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URI,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key']);

            $response = Http::asForm()->post(self::TOKEN_URI, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('token exchange returned '.$response->status());
            }

            return $token;
        });
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function signedJwt(array $claims, string $privateKey): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $payload = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims);

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false || ! openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('could not sign the service-account JWT');
        }

        return $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
