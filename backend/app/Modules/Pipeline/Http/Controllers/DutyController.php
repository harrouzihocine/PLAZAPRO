<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Pipeline\Events\AgentDutyChanged;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Pipeline\Support\AgentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The agent's own duty switch. On duty = an open DutySession + the device
 * shares its position; off duty = the session closes and location sharing
 * stops server-side too (RecordAgentPosition refuses fixes). Idempotent both
 * ways — toggling "on" twice keeps the original session.
 */
class DutyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->state((int) $request->user()->id);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        abort_unless($request->user()->isAgent(), 403, 'Only field agents go on duty.');

        $userId = (int) $request->user()->id;
        $open = DutySession::openFor($userId);

        if ($data['on'] && $open === null) {
            DutySession::create(['user_id' => $userId, 'started_at' => now()]);
        } elseif (! $data['on'] && $open !== null) {
            $open->update(['ended_at' => now()]);
        }

        AgentDutyChanged::dispatch($userId, AgentStatus::forUsers([$userId])->statusOf($userId));

        return $this->state($userId);
    }

    /**
     * The shell's kill switch: device location was turned OFF while on duty.
     * Duty ends (a dark "on duty" is a lie on the dispatch board) and both
     * sides hear why — the agent gets the "turn it back on" nudge, the
     * dispatchers learn the agent went dark and why.
     */
    public function locationLost(Request $request): JsonResponse
    {
        $user = $request->user();
        $open = DutySession::openFor((int) $user->id);

        if ($open !== null) {
            $open->update(['ended_at' => now()]);
            AgentDutyChanged::dispatch((int) $user->id, 'off_duty');

            $user->notify(new \App\Modules\Collaboration\Notifications\DomainNotification(
                kind: 'duty_gps_lost',
                key: 'duty_gps_lost_agent',
                link: '/my-day',
            ));

            $dispatchers = \App\Modules\Settings\Models\User::query()
                ->where('is_active', true)
                ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'visits.dispatch'))
                ->where('id', '!=', $user->id)
                ->get();
            $notice = new \App\Modules\Collaboration\Notifications\DomainNotification(
                kind: 'duty_gps_lost',
                key: 'duty_gps_lost_dispatcher',
                params: ['agent' => $user->name],
                link: '/dispatch',
            );
            foreach ($dispatchers as $dispatcher) {
                $dispatcher->notify($notice);
            }
        }

        return $this->state((int) $user->id);
    }

    private function state(int $userId): JsonResponse
    {
        $open = DutySession::openFor($userId);

        return response()->json(['data' => [
            'on' => $open !== null,
            'since' => $open?->started_at,
        ]]);
    }
}
