<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Desire;

/**
 * The "Desire matches" board: waiting clients (a client-level desire, no deal yet)
 * whose criteria now match available inventory. Agent-scoped like the dashboard —
 * an agent sees only their own book; a non-agent sees the whole company.
 */
class BuildDesireMatches
{
    public function __construct(private MatchDesireToInventory $matcher) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(?int $agentId): array
    {
        $desires = Desire::query()->active()
            ->whereNull('client_project_id')
            ->whereHas('client', function ($q) use ($agentId) {
                $q->active();
                if ($agentId !== null) {
                    $q->where('assigned_agent_id', $agentId);
                }
            })
            ->with('client')
            ->get();

        $out = [];

        foreach ($desires as $desire) {
            $matches = $this->matcher->handle($desire);

            if ($matches->isEmpty()) {
                continue;
            }

            $out[] = [
                'client' => [
                    'id' => $desire->client->id,
                    'full_name' => $desire->client->full_name,
                    'phone' => $desire->client->phone,
                ],
                'desire' => [
                    'type_id' => $desire->type_id,
                    'budget_min' => $desire->budget_min,
                    'budget_max' => $desire->budget_max,
                    'wilaya_id' => $desire->wilaya_id,
                    'commune_id' => $desire->commune_id,
                ],
                'matches' => $matches->take(10)->map(fn ($u) => [
                    'id' => $u->id,
                    'reference' => $u->reference,
                    'price' => $u->price,
                    'location' => $u->location?->name,
                ])->values()->all(),
            ];
        }

        return $out;
    }
}
