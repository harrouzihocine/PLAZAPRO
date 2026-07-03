<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Console;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Actions\EnsureProjectConversation;
use Illuminate\Console\Command;

/**
 * One-time backfill: give every ACTIVE project its dedicated chat (projects
 * created before the feature shipped). Idempotent — EnsureProjectConversation
 * finds-or-creates and reconciles participants, so re-running only heals.
 * Closed/archived projects get theirs on reactivation (CreateClientProject
 * path) or by re-running with --all.
 */
class BackfillProjectChats extends Command
{
    protected $signature = 'projects:backfill-chats {--all : Include archived/cancelled projects}';

    protected $description = 'Create the dedicated chat for existing client projects (idempotent)';

    public function handle(EnsureProjectConversation $ensure): int
    {
        $count = 0;

        ClientProject::query()
            ->when(! $this->option('all'), fn ($q) => $q->active())
            ->with('client')
            ->chunkById(100, function ($projects) use ($ensure, &$count) {
                foreach ($projects as $project) {
                    $ensure->handle($project);
                    $count++;
                }
            });

        $this->info("Ensured a chat for {$count} project(s).");

        return self::SUCCESS;
    }
}
