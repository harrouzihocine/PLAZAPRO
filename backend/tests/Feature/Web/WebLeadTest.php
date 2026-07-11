<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The public lead form: anti-spam layers (honeypot, min-fill-time token,
 * throttle), publish-state validation, and the web.leads fan-out.
 */
class WebLeadTest extends TestCase
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

    /** A token old enough to pass the min-fill-time gate. */
    private function token(int $ageSeconds = 30): string
    {
        return Crypt::encryptString((string) now()->subSeconds($ageSeconds)->timestamp);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amine Visitor',
            'phone' => '0550 12 34 56',
            'type' => 'interest',
            'message' => 'Interested in an F3.',
            'form_token' => $this->token(),
            'website' => '',
        ], $overrides);
    }

    public function test_a_valid_submission_creates_a_lead_and_notifies_web_leads_holders(): void
    {
        Notification::fake();

        $holder = $this->userWithPermissions(['web.leads']);
        $bystander = $this->userWithPermissions(['clients.view']);

        $this->postJson('/api/v1/public/leads', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.ok', true);

        $this->assertDatabaseHas('web_leads', [
            'name' => 'Amine Visitor',
            'lead_status' => 'new',
            'type' => 'interest',
        ]);

        Notification::assertSentTo($holder, DomainNotification::class, fn ($n) => $n->kind === 'web_lead');
        Notification::assertNotSentTo($bystander, DomainNotification::class);
    }

    public function test_honeypot_gets_a_fake_success_and_creates_nothing(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/public/leads', $this->payload(['website' => 'http://spam.example']))
            ->assertCreated()
            ->assertJsonPath('data.ok', true);

        $this->assertDatabaseCount('web_leads', 0);
        Notification::assertNothingSent();
    }

    public function test_a_token_younger_than_the_min_fill_time_is_rejected(): void
    {
        $this->postJson('/api/v1/public/leads', $this->payload(['form_token' => $this->token(0)]))
            ->assertUnprocessable();

        $this->postJson('/api/v1/public/leads', $this->payload(['form_token' => 'garbage']))
            ->assertUnprocessable();

        $this->postJson('/api/v1/public/leads', $this->payload(['form_token' => null]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('web_leads', 0);
    }

    public function test_leads_against_unpublished_projects_are_rejected(): void
    {
        $hidden = Location::factory()->create(['is_published' => false]);

        $this->postJson('/api/v1/public/leads', $this->payload(['location_id' => $hidden->id]))
            ->assertUnprocessable();
    }

    public function test_the_unit_must_belong_to_the_sent_published_project(): void
    {
        $published = Location::factory()->create(['is_published' => true]);
        $other = Location::factory()->create(['is_published' => true]);
        $foreignUnit = Unit::factory()->for($other)->create();

        $this->postJson('/api/v1/public/leads', $this->payload([
            'location_id' => $published->id,
            'unit_id' => $foreignUnit->id,
        ]))->assertUnprocessable();

        $ownUnit = Unit::factory()->for($published)->create();

        $this->postJson('/api/v1/public/leads', $this->payload([
            'location_id' => $published->id,
            'unit_id' => $ownUnit->id,
        ]))->assertCreated();
    }

    public function test_submissions_are_throttled_per_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/public/leads', $this->payload(['phone' => "055012345{$i}"]))
                ->assertCreated();
        }

        $this->postJson('/api/v1/public/leads', $this->payload(['phone' => '0550123499']))
            ->assertStatus(429);
    }

    public function test_the_lead_stamps_locale_and_request_context(): void
    {
        $this->withHeaders(['Accept-Language' => 'ar', 'Referer' => 'https://example.test/plaza/projects/1'])
            ->postJson('/api/v1/public/leads', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('web_leads', [
            'locale' => 'ar',
            'source_url' => 'https://example.test/plaza/projects/1',
        ]);
    }
}
