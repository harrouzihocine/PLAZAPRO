<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Models\Versement;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentTest extends TestCase
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

    private function versement(): Versement
    {
        $project = ClientProject::factory()->create(['total_price' => '2000.00']);

        return Versement::factory()->create([
            'client_project_id' => $project->id, 'amount' => '1000.00',
        ]);
    }

    public function test_generating_a_receipt_creates_a_numbered_document_and_a_private_pdf(): void
    {
        Storage::fake('documents');
        $versement = $this->versement();
        Sanctum::actingAs($this->userWithPermissions(['versements.view', 'documents.generate']));

        $response = $this->postJson("/api/v1/versements/{$versement->id}/document")
            ->assertCreated()
            ->assertJsonPath('data.type', 'receipt');

        $number = $response->json('data.number');
        $this->assertMatchesRegularExpression('/^REC-\d{4}-\d{6}$/', $number);

        // The queued render (sync in tests) produced the PDF and flipped the row
        // to ready. The documents row exists on the private disk and links back
        // to the versement.
        $this->assertDatabaseHas('documents', [
            'number' => $number, 'type' => 'receipt', 'disk' => 'documents', 'render_status' => 'ready',
        ]);
        Storage::disk('documents')->assertExists("receipts/{$number}.pdf");
        $this->assertNotNull($versement->refresh()->document_id);
    }

    public function test_document_numbers_are_unique_and_sequential(): void
    {
        Storage::fake('documents');
        $a = $this->versement();
        $b = $this->versement();
        Sanctum::actingAs($this->userWithPermissions(['versements.view', 'documents.generate']));

        $first = $this->postJson("/api/v1/versements/{$a->id}/document")->json('data.number');
        $second = $this->postJson("/api/v1/versements/{$b->id}/document")->json('data.number');

        $this->assertNotSame($first, $second);
        $year = now()->year;
        $this->assertSame("REC-{$year}-000001", $first);
        $this->assertSame("REC-{$year}-000002", $second);
    }

    public function test_a_generated_document_can_be_downloaded(): void
    {
        Storage::fake('documents');
        $versement = $this->versement();
        Sanctum::actingAs($this->userWithPermissions(['versements.view', 'documents.generate']));

        $id = $this->postJson("/api/v1/versements/{$versement->id}/document")->json('data.id');

        $this->get("/api/v1/documents/{$id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_generation_requires_the_documents_generate_permission(): void
    {
        Storage::fake('documents');
        $versement = $this->versement();
        Sanctum::actingAs($this->userWithPermissions(['versements.view', 'versements.record']));

        $this->postJson("/api/v1/versements/{$versement->id}/document")->assertForbidden();
    }
}
