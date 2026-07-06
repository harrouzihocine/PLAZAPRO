<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Actions\EnsureProjectConversation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/**
 * Open a new deal for a client. A deal starts at the `lead` stage unless a valid
 * starting stage is given. created_by is stamped from the authenticated user —
 * it drives the projects.view_all visibility rule. Every project opens with its
 * own dedicated chat (participants = the contributors).
 *
 * $createdBy overrides the creator: the duplicate-resolution "separate project"
 * outcome opens a project ON BEHALF of the finder (the creator must be the finder,
 * not the resolver who is acting), so it passes the finder's id here.
 */
class CreateClientProject
{
    public function __construct(private EnsureProjectConversation $ensureConversation) {}

    public function handle(Client $client, array $data, ?int $createdBy = null): ClientProject
    {
        $attributes = Arr::only($data, ['location_id', 'unit_id', 'stage', 'total_price']);
        $attributes['stage'] ??= ClientProjectStage::Lead->value;

        $project = new ClientProject($attributes);
        $project->client()->associate($client);
        $project->created_by = $createdBy ?? Auth::id();
        $project->save();

        $this->ensureConversation->handle($project);

        return $project;
    }
}
