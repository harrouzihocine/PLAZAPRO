<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Analytics\Models\ActivityLog;
use App\Modules\Settings\Actions\RevokeUserSessions;
use App\Modules\Settings\Support\DeviceSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Connected sessions: the signed-in user reviewing every device their account
 * is logged in on (database session driver — one row per device) and force-
 * ending any of them: a single session, or everything except the device in
 * hand. Own account only — every query is scoped to $request->user().
 *
 * A raw session id IS the auth cookie, so it must never leave the server:
 * rows are exposed and addressed by their sha256 instead.
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $current = $this->currentSessionId($request);

        $sessions = $this->rowsFor($request)
            ->map(fn (object $row): array => [
                'id' => hash('sha256', $row->id),
                'ip_address' => $row->ip_address,
                'is_current' => $row->id === $current,
                'last_active_at' => CarbonImmutable::createFromTimestamp($row->last_activity)
                    ->setTimezone(config('app.timezone'))
                    ->toIso8601String(),
                ...DeviceSummary::fromUserAgent($row->user_agent),
            ])
            // Current device first; the query already ordered by recency.
            ->sortByDesc('is_current', SORT_REGULAR)
            ->values();

        return response()->json(['data' => $sessions]);
    }

    /** End one session, addressed by its sha256 (never the raw id). */
    public function destroy(Request $request, string $session, RevokeUserSessions $revoke): JsonResponse
    {
        $row = $this->rowsFor($request)->first(
            fn (object $r): bool => hash_equals(hash('sha256', $r->id), $session),
        );

        abort_if($row === null, 404, 'Session not found.');

        if ($row->id === $this->currentSessionId($request)) {
            throw ValidationException::withMessages([
                'session' => [__('This is your current session — use log out instead.')],
            ]);
        }

        $revoke->only($request->user(), $row->id, refreshRecaller: $request->hasSession());

        ActivityLog::record('session_revoked', $request->user(), ['scope' => 'one']);

        return response()->json(['message' => 'Session ended.']);
    }

    /** End every session except the one this request rides on. */
    public function destroyOthers(Request $request, RevokeUserSessions $revoke): JsonResponse
    {
        $current = $this->currentSessionId($request);

        $ended = $revoke->except($request->user(), $current, refreshRecaller: $current !== null);

        ActivityLog::record('session_revoked', $request->user(), ['scope' => 'others', 'count' => $ended]);

        return response()->json(['data' => ['ended' => $ended]]);
    }

    /** The user's live (non-expired) session rows, most recent first. */
    private function rowsFor(Request $request): Collection
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->getKey())
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->orderByDesc('last_activity')
            ->get();
    }

    /** Null for stateless (Bearer-token) callers, which have no web session. */
    private function currentSessionId(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }
}
