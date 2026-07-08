<?php

declare(strict_types=1);

namespace App\Modules\Clients\Console;

use App\Modules\Clients\Models\Client;
use App\Modules\Collaboration\Notifications\DomainNotification;
use Illuminate\Console\Command;

/**
 * Nudge the creator of any client left EMPTY (no project, no call, no desire)
 * for more than 48h — once. Feeds the "empty client" reminder the owner asked
 * for; the oversight page and dashboard widget show the same anomaly live.
 * Scheduled daily (see routes/console.php).
 */
class FlagEmptyClients extends Command
{
    protected $signature = 'clients:flag-empty';

    protected $description = 'Remind creators of clients left empty (no project/call/desire) for 48h';

    public function handle(): int
    {
        $clients = Client::query()->active()
            ->whereNull('empty_reminded_at')
            ->where('created_at', '<', now()->subHours(48))
            ->doesntHave('projects')
            ->doesntHave('calls')
            ->doesntHave('desire')
            ->with('creator')
            ->get();

        $count = 0;
        foreach ($clients as $client) {
            $client->creator?->notify(new DomainNotification(
                kind: 'reminder',
                key: 'empty_client',
                params: ['name' => $client->full_name],
                link: '/clients/'.$client->id,
            ));

            // Stamp quietly — a system flag, not an audited edit.
            $client->forceFill(['empty_reminded_at' => now()])->saveQuietly();
            $count++;
        }

        $this->info("Reminded on {$count} empty client(s).");

        return self::SUCCESS;
    }
}
