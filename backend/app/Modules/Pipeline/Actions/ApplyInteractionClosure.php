<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Actions\ArchiveClientProject;
use App\Modules\Clients\Actions\CreateDeal;
use App\Modules\Clients\Actions\EnsureActiveClientProject;
use App\Modules\Clients\Actions\ShiftProjectToDesire;
use App\Modules\Clients\Actions\UpsertDesire;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;

/**
 * Resolve an interaction that was concluded WITHOUT a next action into one of the
 * three explicit outcomes (see ValidatesClosure). Shared by the call log and the
 * visit completion so the rule lives in exactly one place.
 *
 *  - desire  → an active project is shifted to the desire list; a client with no
 *              project just gets its desire (re)captured.
 *  - archive → the (or a freshly-ensured) project is archived with reason + note.
 *  - deal    → THE deal opens on the (or a freshly-ensured) project; the visit /
 *              call id is passed as provenance from the log being concluded.
 */
class ApplyInteractionClosure
{
    public function __construct(
        private EnsureActiveClientProject $ensureProject,
        private ShiftProjectToDesire $shiftToDesire,
        private ArchiveClientProject $archive,
        private CreateDeal $createDeal,
        private UpsertDesire $upsertDesire,
    ) {}

    /**
     * @param  array<string, mixed>  $closure
     */
    public function handle(
        ?ClientProject $project,
        Client $client,
        array $closure,
        User $actor,
        ?int $visitId = null,
        ?int $callId = null,
    ): void {
        match ($closure['type']) {
            'desire' => $this->toDesire($project, $client, $closure['desire'] ?? []),
            'archive' => $this->toArchive($project ?? $this->ensureProject->handle($client), $closure),
            'deal' => $this->createDeal->handle(
                $project ?? $this->ensureProject->handle($client),
                [
                    'visit_id' => $visitId ?? ($closure['visit_id'] ?? null),
                    'call_id' => $callId,
                    'units' => $closure['units'],
                    'notes' => $closure['notes'] ?? null,
                ],
                $actor,
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $desire
     */
    private function toDesire(?ClientProject $project, Client $client, array $desire): void
    {
        if ($project !== null && $project->isActive()) {
            $this->shiftToDesire->handle($project, $desire);

            return;
        }

        $this->upsertDesire->handle($client, $desire);
    }

    /**
     * @param  array<string, mixed>  $closure
     */
    private function toArchive(ClientProject $project, array $closure): void
    {
        $reason = DynamicListItem::query()->whereKey($closure['reason_id'])->value('label');

        // Persist the structured reason id (for the Voice-of-Client "why lost"
        // analytics) alongside the human-readable audit note.
        $this->archive->handle(
            $project,
            trim(($reason ?? 'Archived').' — '.$closure['note']),
            archiveReasonId: (int) $closure['reason_id'],
        );
    }
}
