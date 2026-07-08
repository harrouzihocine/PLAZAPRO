<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Console;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Actions\BuildUpcomingWork;
use App\Modules\Settings\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The daily "what's coming up" reminder: every morning, each user is notified
 * about the calls / office visits / in-site visits / tasks that involve them
 * and are due today or tomorrow (overdue ones included until completed) — one
 * notification per type, never mixed, each deep-linking to its list or, for a
 * single item, straight to its project. Repeats daily until the item is done:
 * that IS the reminder cadence.
 */
class SendUpcomingDigest extends Command
{
    protected $signature = 'reminders:upcoming-digest {--force : Send even if today\'s digest already went out}';

    protected $description = 'Notify each user of their upcoming calls / visits / tasks (due today or tomorrow)';

    private const LABELS = [
        'calls' => ['call', 'calls'],
        'office_visits' => ['office visit', 'office visits'],
        'in_site_visits' => ['in-site visit', 'in-site visits'],
        'tasks' => ['task', 'tasks'],
    ];

    public function handle(BuildUpcomingWork $build): int
    {
        // One digest per day, whoever calls: a manual artisan run, a re-deploy,
        // or a second scheduler host must not double-notify every user.
        // --force bypasses (testing).
        if (! $this->option('force') && ! Cache::add('digest:sent:'.now()->toDateString(), 1, now()->endOfDay())) {
            $this->warn('Digest already sent today — use --force to resend.');

            return self::SUCCESS;
        }

        $sent = 0;

        User::query()->active()->where('is_active', true)
            ->chunkById(100, function ($users) use ($build, &$sent) {
                foreach ($users as $user) {
                    $sent += $this->notify($user, $build->handle($user, now()->addDay()->endOfDay()));
                }
            });

        $this->info("Sent {$sent} digest notification(s).");

        return self::SUCCESS;
    }

    /** @param array<string, list<array<string, mixed>>> $upcoming */
    private function notify(User $user, array $upcoming): int
    {
        $sent = 0;

        foreach (array_keys(self::LABELS) as $group) {
            $items = $upcoming[$group] ?? [];
            if ($items === []) {
                continue;
            }

            $count = count($items);
            $overdue = count(array_filter($items, fn ($i) => $i['is_overdue']));

            // Max detail in the body: who/where/when per item (first few).
            $lines = array_map(
                fn ($i) => implode(' · ', array_filter([
                    $i['client'] ?? $i['title'] ?? null,
                    $i['unit'] ?? null,
                    $i['location'] ?? null,
                    $i['due_at']?->format('D d M H:i'),
                ])),
                array_slice($items, 0, 3),
            );
            if ($count > 3) {
                $lines[] = __('notifications.digest_more', ['count' => $count - 3], $user->preferredLocale());
            }

            $user->notify(new DomainNotification(
                kind: 'upcoming_digest',
                key: $overdue > 0 ? 'upcoming_digest_overdue' : 'upcoming_digest',
                params: [
                    'count' => $count,
                    'group' => '@notifications.group.'.$group,
                    'overdue' => $overdue,
                    'lines' => implode(' — ', $lines),
                ],
                // One item → straight to its project; several → the dashboard list.
                link: $count === 1 ? ($items[0]['link'] ?? '/') : '/',
            ));
            $sent++;
        }

        return $sent;
    }
}
