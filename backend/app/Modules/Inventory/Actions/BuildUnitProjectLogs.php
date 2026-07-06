<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Http\Resources\CallResource;
use App\Modules\Pipeline\Http\Resources\VisitResource;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;

/**
 * The interaction logs (calls + visits) of every client project that has ever
 * touched THIS unit — visited it, shortlisted it, dealt on it, held it, or
 * carries it as the project's stamped unit. Grouped per project so the unit
 * page can render each project's story with the same timeline design used on
 * the project detail page.
 *
 * Visibility is enforced per project (ClientProject::visibleTo): a project the
 * caller cannot see never surfaces here, so the unit page never leaks another
 * agent's activity. Cancelled/superseded log versions are returned too — an
 * edit never hides its history (the FE nests old versions under their replacement).
 */
class BuildUnitProjectLogs
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Unit $unit, User $user): array
    {
        // Projects with an interest hold on this unit — Reservation has no ClientProject
        // relation the other way, so resolve the ids first and fold them in.
        $reservedProjectIds = Reservation::query()
            ->where('unit_id', $unit->id)
            ->whereNotNull('client_project_id')
            ->pluck('client_project_id');

        $projects = ClientProject::query()
            ->visibleTo($user)
            ->where(function ($q) use ($unit, $reservedProjectIds) {
                $q->where('unit_id', $unit->id)
                    ->orWhereHas('visits', fn ($v) => $v->where('unit_id', $unit->id))
                    ->orWhereHas('shortlistItems', fn ($s) => $s
                        ->where('shortlistable_type', 'unit')
                        ->where('shortlistable_id', $unit->id))
                    ->orWhereHas('deals.items', fn ($d) => $d->where('unit_id', $unit->id))
                    ->when($reservedProjectIds->isNotEmpty(), fn ($qq) => $qq
                        ->orWhereIn('id', $reservedProjectIds));
            })
            ->with(['client:id,first_name,last_name', 'location:id,name', 'unit:id,reference'])
            ->orderByDesc('updated_at')
            ->get();

        return $projects->map(function (ClientProject $project) {
            $calls = Call::query()
                ->where('client_project_id', $project->id)
                ->with(['agent', 'outcome', 'supersedes'])
                ->latest('called_at')
                ->get();

            $visits = Visit::query()
                ->where('client_project_id', $project->id)
                ->with(['agent', 'unit.floor', 'unit.location', 'unit.location.type', 'outcome', 'supersedes'])
                ->orderByDesc('scheduled_at')
                ->get();

            return [
                'project' => [
                    'id' => $project->id,
                    'client_id' => $project->client_id,
                    'client_name' => $project->client?->full_name,
                    'unit_reference' => $project->unit?->reference,
                    'location_name' => $project->location?->name,
                    'step' => $project->deriveStep(),
                    'stage' => $project->stage?->value,
                    'status' => $project->status?->value,
                ],
                'calls' => CallResource::collection($calls)->resolve(),
                'visits' => VisitResource::collection($visits)->resolve(),
            ];
        })->all();
    }
}
