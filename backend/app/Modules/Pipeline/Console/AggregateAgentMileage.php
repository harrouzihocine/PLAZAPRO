<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Console;

use App\Modules\Pipeline\Models\AgentMileageDay;
use App\Modules\Pipeline\Models\AgentPosition;
use App\Modules\Pipeline\Support\Mileage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fold yesterday's GPS breadcrumbs into one mileage row per agent (km, fixes,
 * duty minutes). Runs nightly BEFORE positions:prune so the fuel/allowance
 * history survives the breadcrumbs' short retention. Idempotent: re-running a
 * day upserts the same rows.
 */
class AggregateAgentMileage extends Command
{
    protected $signature = 'dispatch:mileage {--day= : Y-m-d to (re)aggregate, defaults to yesterday}';

    protected $description = 'Aggregate agent GPS breadcrumbs into daily mileage rows';

    public function handle(): int
    {
        $day = $this->option('day') !== null
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('day'))
            : now()->subDay();

        $userIds = AgentPosition::query()
            ->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            AgentMileageDay::query()->updateOrCreate(
                ['user_id' => $userId, 'day' => $day->toDateString()],
                Mileage::forDay((int) $userId, $day),
            );
        }

        $this->info("Mileage for {$day->toDateString()}: {$userIds->count()} agent(s) aggregated.");

        return self::SUCCESS;
    }
}
