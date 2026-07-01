<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Pipeline\Actions\DispatchReminders;
use App\Modules\Pipeline\Actions\GenerateDueReminders;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Reminder;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_overdue_next_action_generates_a_reminder_for_the_assigned_agent(): void
    {
        $agent = User::factory()->agent()->create();
        $action = NextAction::factory()->overdue()->create(['assigned_to' => $agent->id]);

        $created = app(GenerateDueReminders::class)->handle();

        $this->assertSame(1, $created);
        $reminder = Reminder::first();
        $this->assertSame($action->id, $reminder->next_action_id);
        $this->assertSame('pending', $reminder->state->value);
        // The recipient is the action's assigned agent.
        $this->assertSame($agent->id, $reminder->nextAction->assigned_to);
    }

    public function test_generation_is_idempotent(): void
    {
        NextAction::factory()->overdue()->create();

        $this->assertSame(1, app(GenerateDueReminders::class)->handle());
        // Running again does not create a duplicate reminder for the same action.
        $this->assertSame(0, app(GenerateDueReminders::class)->handle());
        $this->assertSame(1, Reminder::count());
    }

    public function test_a_future_next_action_does_not_generate_a_reminder(): void
    {
        NextAction::factory()->create(['due_at' => now()->addDays(3)]); // pending, not due

        $this->assertSame(0, app(GenerateDueReminders::class)->handle());
        $this->assertSame(0, Reminder::count());
    }

    public function test_a_done_next_action_does_not_generate_a_reminder(): void
    {
        NextAction::factory()->overdue()->done()->create();

        $this->assertSame(0, app(GenerateDueReminders::class)->handle());
    }

    public function test_dispatch_marks_due_pending_reminders_sent(): void
    {
        Reminder::factory()->due()->create();
        Reminder::factory()->create(['remind_at' => now()->addHour()]); // not due yet

        $sent = app(DispatchReminders::class)->handle();

        $this->assertSame(1, $sent);
        $this->assertSame(1, Reminder::query()->where('state', 'sent')->count());
        $this->assertSame(1, Reminder::query()->where('state', 'pending')->count());
    }

    public function test_dispatch_cancels_a_reminder_whose_action_was_completed(): void
    {
        // An overdue action generates a reminder, then the action is completed
        // before the dispatcher runs.
        $action = NextAction::factory()->overdue()->create();
        $reminder = Reminder::factory()->due()->create(['next_action_id' => $action->id]);
        $action->update(['state' => 'done', 'completed_at' => now()]);

        $sent = app(DispatchReminders::class)->handle();

        $this->assertSame(0, $sent);
        $this->assertSame('cancelled', $reminder->fresh()->state->value);
    }

    public function test_the_scheduled_commands_run(): void
    {
        $this->artisan('actions:mark-overdue')->assertSuccessful();
        $this->artisan('reminders:dispatch')->assertSuccessful();
    }
}
