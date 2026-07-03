<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;

/**
 * Return the client's open project, creating one (at `lead`) when none exists.
 * Used when a call shortlists properties for a client who has no active project
 * yet — the shortlist needs a project to live on.
 *
 * Reopen rule: a project that was shifted to the desire list is REACTIVATED here
 * instead of opening a duplicate — logging a call after a desire match is exactly
 * how the profile comes back from the desire list.
 */
class EnsureActiveClientProject
{
    public function __construct(
        private CreateClientProject $createClientProject,
        private ReactivateClientProject $reactivateClientProject,
    ) {}

    public function handle(Client $client): ClientProject
    {
        $open = $client->projects()->active()
            ->whereNotIn('stage', [ClientProjectStage::Won->value, ClientProjectStage::Lost->value])
            ->orderBy('id')
            ->first();

        if ($open !== null) {
            return $open;
        }

        $waitingOnDesire = $client->projects()->archived()
            ->whereNotNull('closed_to_desire_at')
            ->latest('id')
            ->first();

        if ($waitingOnDesire !== null) {
            return $this->reactivateClientProject->handle($waitingOnDesire);
        }

        return $this->createClientProject->handle($client, []);
    }
}
