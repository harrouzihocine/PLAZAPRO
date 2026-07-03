<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "My upcoming" (dashboard block) + the daily digest — both built on
 * BuildUpcomingWork, so one inclusion rule: assigned to me, or on a project I
 * contribute to; grouped per type, never mixed.
 */
class UpcomingWorkTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs, bool $isAgent = true): User
    {
        $role = Role::factory()->create(['is_agent' => $isAgent]);
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_dashboard_lists_my_upcoming_grouped_by_type(): void
    {
        $me = $this->userWith(['dashboard.view']);
        $other = User::factory()->create();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id, 'created_by' => $me->id]);

        // Mine: a pending call assigned to me, and an in-site visit on my project.
        NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => $client->id,
            'type' => 'call', 'state' => 'pending',
            'assigned_to' => $me->id, 'due_at' => now()->addDays(2),
        ]);
        Visit::factory()->inSite()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id,
            'agent_id' => $other->id, 'scheduled_at' => now()->addDay(), 'completed_at' => null,
        ]);
        // Not mine: someone else's call on a project I don't see.
        NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => Client::factory()->create()->id,
            'type' => 'call', 'state' => 'pending',
            'assigned_to' => $other->id, 'due_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        $this->assertCount(1, $response->json('data.my_upcoming.calls'));
        $this->assertCount(1, $response->json('data.my_upcoming.in_site_visits'));
        $this->assertCount(0, $response->json('data.my_upcoming.office_visits'));
        // The project visit deep-links to the project workspace.
        $this->assertSame(
            "/clients/{$client->id}/projects/{$project->id}",
            $response->json('data.my_upcoming.in_site_visits.0.link'),
        );
    }

    public function test_upcoming_digest_notifies_only_involved_users_per_type(): void
    {
        Notification::fake();

        $assignee = User::factory()->create();
        $bystander = User::factory()->create();
        $client = Client::factory()->create();

        NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => $client->id,
            'type' => 'call', 'state' => 'pending',
            'assigned_to' => $assignee->id, 'due_at' => now()->addHours(3),
        ]);

        $this->artisan('reminders:upcoming-digest')->assertSuccessful();

        Notification::assertSentTo(
            $assignee,
            DomainNotification::class,
            fn ($n) => $n->kind === 'upcoming_digest' && str_contains($n->title, 'call'),
        );
        Notification::assertNothingSentTo($bystander);
    }

    public function test_digest_repeats_daily_until_the_item_is_done(): void
    {
        Notification::fake();

        $assignee = User::factory()->create();
        $client = Client::factory()->create();

        $action = NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => $client->id,
            'type' => 'call', 'state' => 'pending',
            'assigned_to' => $assignee->id, 'due_at' => now()->subDay(), // overdue stays listed
        ]);

        $this->artisan('reminders:upcoming-digest')->assertSuccessful();
        $action->update(['state' => 'done', 'completed_at' => now()]);
        $this->artisan('reminders:upcoming-digest')->assertSuccessful();

        Notification::assertSentToTimes($assignee, DomainNotification::class, 1);
    }
}
