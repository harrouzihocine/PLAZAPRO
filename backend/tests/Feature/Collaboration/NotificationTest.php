<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Clients\Models\Client;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Actions\DispatchReminders;
use App\Modules\Pipeline\Actions\ScheduleVisit;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Reminder;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_the_feed_requires_notifications_view(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->getJson('/api/v1/notifications')->assertForbidden();
    }

    public function test_a_user_sees_only_their_own_notifications_with_an_unread_count(): void
    {
        $me = $this->userWithPermissions(['notifications.view']);
        $other = User::factory()->create();

        $me->notify(new DomainNotification('test', 'Mine'));
        $other->notify(new DomainNotification('test', 'Theirs'));

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine')
            ->assertJsonPath('meta.unread_count', 1);
    }

    public function test_marking_a_notification_read_decrements_the_unread_count(): void
    {
        $me = $this->userWithPermissions(['notifications.view']);
        $me->notify(new DomainNotification('test', 'Hi'));
        $id = $me->notifications()->first()->id;

        Sanctum::actingAs($me);

        $this->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($me->notifications()->first()->read_at);
    }

    public function test_mark_all_read(): void
    {
        $me = $this->userWithPermissions(['notifications.view']);
        $me->notify(new DomainNotification('a', 'A'));
        $me->notify(new DomainNotification('b', 'B'));

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $me->unreadNotifications()->count());
    }

    public function test_a_due_reminder_notifies_the_actions_assignee(): void
    {
        $agent = User::factory()->agent()->create();
        $action = NextAction::factory()->create([
            'assigned_to' => $agent->id,
            'subject_type' => 'client',
            'subject_id' => Client::factory(),
            'state' => 'pending',
            'due_at' => now()->subHour(),
        ]);
        Reminder::factory()->due()->create(['next_action_id' => $action->id]);

        app(DispatchReminders::class)->handle();

        $this->assertSame(1, $agent->notifications()->count());
        $this->assertSame('reminder', $agent->notifications()->first()->data['kind']);
    }

    public function test_a_reminder_for_a_completed_action_does_not_notify(): void
    {
        $agent = User::factory()->agent()->create();
        $action = NextAction::factory()->create(['assigned_to' => $agent->id, 'due_at' => now()->subHour()]);
        Reminder::factory()->due()->create(['next_action_id' => $action->id]);
        $action->update(['state' => 'done', 'completed_at' => now()]);

        app(DispatchReminders::class)->handle();

        $this->assertSame(0, $agent->notifications()->count());
    }

    public function test_scheduling_a_visit_notifies_the_assigned_agent(): void
    {
        $agent = User::factory()->agent()->create();
        $client = Client::factory()->create();

        app(ScheduleVisit::class)->handle([
            'client_id' => $client->id,
            'type' => VisitType::Office->value,
            'agent_id' => $agent->id,
            'scheduled_at' => now()->addDay(),
        ]);

        $this->assertSame(1, $agent->notifications()->count());
        $this->assertSame('visit_assigned', $agent->notifications()->first()->data['kind']);
    }
}
