<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Events\VisitAssigned;
use App\Modules\Pipeline\Models\Visit;

/**
 * Schedule an office or apartment visit. The agent-only rule and the
 * apartment-requires-a-unit rule are the FormRequest's job (the trust boundary,
 * via the IsAgentUser rule); reassignment later goes through AssignVisit.
 */
class ScheduleVisit
{
    public function handle(array $data): Visit
    {
        $visit = Visit::create([
            'client_id' => $data['client_id'],
            'client_project_id' => $data['client_project_id'] ?? null,
            'type' => $data['type'],
            'unit_id' => $data['unit_id'] ?? null,
            'agent_id' => $data['agent_id'],
            'scheduled_at' => $data['scheduled_at'],
            'notes' => $data['notes'] ?? null,
        ]);

        // Notify the assigned agent (Collaboration listens; Phase 5).
        VisitAssigned::dispatch($visit);

        return $visit;
    }
}
