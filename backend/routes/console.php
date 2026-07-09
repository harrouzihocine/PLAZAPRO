<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep expired 48h interest holds. Requires a running scheduler in the
// container (schedule:work, or cron calling schedule:run). See Phase 7.
Schedule::command('holds:expire')->everyFiveMinutes()->withoutOverlapping();

// Return Reserved units whose holding-deposit window lapsed to the market
// (interested if backups remain, else available) and notify the former holder.
Schedule::command('reserved:expire')->everyFiveMinutes()->withoutOverlapping();

// Pipeline reminders: generate reminders for due/overdue next actions (hourly),
// then dispatch the pending ones to their assigned agents (every minute).
Schedule::command('actions:mark-overdue')->hourly()->withoutOverlapping();
Schedule::command('reminders:dispatch')->everyMinute()->withoutOverlapping();

// Every morning: remind each user of the calls / visits / tasks that involve
// them and are due today or tomorrow (repeats daily until completed).
Schedule::command('reminders:upcoming-digest')->dailyAt('08:00')->withoutOverlapping();

// Flip past-due, unpaid payment-schedule instalments to overdue (daily).
Schedule::command('schedules:mark-overdue')->dailyAt('00:15')->withoutOverlapping();

// Materialize the day's KPI values into kpi_snapshots (after schedules flip to
// overdue) so the command center's trend curves have a fresh daily point.
Schedule::command('kpi:snapshot')->dailyAt('00:30')->withoutOverlapping();

// Nudge creators of clients left empty (no project/call/desire) for 48h — once.
Schedule::command('clients:flag-empty')->dailyAt('07:00')->withoutOverlapping();

// Idempotency-key replay ledger (offline outbox): rows older than 7 days are
// dead weight — the client never replays that old.
Schedule::call(fn () => DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(7))->delete())
    ->name('idempotency:prune')
    ->dailyAt('04:00');
