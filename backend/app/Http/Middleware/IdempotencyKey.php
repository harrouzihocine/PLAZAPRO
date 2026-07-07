<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replay protection for the offline outbox (route middleware `idempotent`,
 * applied to the queueable field-agent writes). The client sends a UUID in
 * X-Idempotency-Key with the FIRST attempt already (online too): if the
 * response was lost mid-flight (timeout after commit, dropped connection) the
 * replay returns the stored response instead of double-applying.
 *
 * Deliberate rules:
 *  - Only 2xx responses are stored. A 4xx MUST re-evaluate the live domain
 *    guards on retry — "this visit is already completed" (422) is the
 *    conflict-rejection substrate, never something to replay from a cache.
 *  - Keys are scoped per user (unique [user_id, key]); another user's key
 *    can never collide with or read back someone else's response.
 *  - Runs after auth:sanctum in the route group, so $request->user() is set.
 *  - A concurrent twin (same key racing on two connections) is settled by the
 *    unique index: the loser's insert is swallowed — its own execution already
 *    happened in the same request, so nothing is lost.
 */
class IdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Idempotency-Key');
        $user = $request->user();

        if ($key === null || $user === null || ! Str::isUuid($key)) {
            return $next($request);
        }

        $hit = DB::table('idempotency_keys')
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();

        if ($hit !== null) {
            return response($hit->response_body, (int) $hit->status_code)
                ->header('Content-Type', 'application/json')
                ->header('X-Idempotency-Replayed', 'true');
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            try {
                DB::table('idempotency_keys')->insert([
                    'key' => $key,
                    'user_id' => $user->id,
                    'method' => $request->method(),
                    'path' => Str::limit($request->path(), 250, ''),
                    'status_code' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Concurrent twin — first insert wins; this request already ran.
            }
        }

        return $response;
    }
}
