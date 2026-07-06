<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Clients\Actions\CreateClient;
use App\Modules\Clients\Models\Client;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Seeder;

/**
 * Comprehensive client test data covering:
 * - All source types (referral, walk-in, facebook, instagram, portal, phone)
 * - All ratings (hot, warm, cold)
 * - Different assigned agents
 * - Clients with full details vs. minimal (phone-only)
 * - Geographic distribution
 */
class ClientSeeder extends Seeder
{
    private array $listCache = [];

    public function run(): void
    {
        $this->command->info('Seeding clients…');

        // Get a sales agent for assignments
        $agents = User::whereHas('role', fn ($q) => $q->where('slug', 'sales-agent'))->get();
        if ($agents->isEmpty()) {
            $this->command->warn('No sales agents found. Skipping ClientSeeder.');

            return;
        }

        $createClient = app(CreateClient::class);

        // ---- Hot leads (high conversion potential) ----
        $this->command->line('  Creating hot leads...');

        // Full profile hot clients
        $createClient->handle([
            'first_name' => 'Ahmed', 'last_name' => 'Boudjema', 'phone' => '0550 111 111', 'email' => 'ahmed.boudjema@example.dz',
            'source_id' => $this->item('sources', 'referral'), 'rating_id' => $this->item('client_ratings', 'hot'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Budget 7-9M, urgent, needs F4 in Algiers.',
            'birth_date' => '1975-03-15', 'address' => '123 Rue de la Liberté, Alger',
        ]);

        $createClient->handle([
            'first_name' => 'Fatima', 'last_name' => 'Kada', 'phone' => '0551 222 222', 'email' => 'fatima.kada@example.dz',
            'source_id' => $this->item('sources', 'facebook'), 'rating_id' => $this->item('client_ratings', 'hot'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Relocating family, decision maker.',
            'birth_date' => '1980-07-22', 'address' => '456 Avenue Ben Bella, Oran',
        ]);

        // Walk-in hot clients
        $createClient->handle([
            'first_name' => 'Karim', 'last_name' => 'Lounis', 'phone' => '0552 333 333', 'email' => 'karim.lounis@example.dz',
            'source_id' => $this->item('sources', 'walk_in'), 'rating_id' => $this->item('client_ratings', 'hot'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Cash buyer, ready to sign.',
        ]);

        $createClient->handle([
            'first_name' => 'Leila', 'last_name' => 'Osman', 'phone' => '0553 444 444', 'email' => 'leila.osman@example.dz',
            'source_id' => $this->item('sources', 'portal'), 'rating_id' => $this->item('client_ratings', 'hot'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Investment property, F2 or F3.',
        ]);

        // Phone-only hot clients
        $createClient->handle([
            'phone' => '0554 555 555',
            'source_id' => $this->item('sources', 'referral'), 'rating_id' => $this->item('client_ratings', 'hot'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Initial inquiry via referral, follow-up needed.',
        ]);

        // ---- Warm leads (medium potential) ----
        $this->command->line('  Creating warm leads...');

        $createClient->handle([
            'first_name' => 'Noureddine', 'last_name' => 'Belhadi', 'phone' => '0555 666 666', 'email' => 'noureddine.belhadi@example.dz',
            'source_id' => $this->item('sources', 'referral'), 'rating_id' => $this->item('client_ratings', 'warm'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Interested but flexible on timeline.',
            'birth_date' => '1988-11-30', 'address' => '789 Rue de l\'Indépendance, Constantine',
        ]);

        $createClient->handle([
            'first_name' => 'Zainab', 'last_name' => 'Mansour', 'phone' => '0556 777 777', 'email' => 'zainab.mansour@example.dz',
            'source_id' => $this->item('sources', 'instagram'), 'rating_id' => $this->item('client_ratings', 'warm'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Saw ads on Instagram, curious.',
        ]);

        $createClient->handle([
            'first_name' => 'Salim', 'last_name' => 'Aouali', 'phone' => '0557 888 888', 'email' => 'salim.aouali@example.dz',
            'source_id' => $this->item('sources', 'walk_in'), 'rating_id' => $this->item('client_ratings', 'warm'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Visited office, gathering info.',
            'birth_date' => '1992-05-10', 'address' => '321 Boulevard Didouche Mourad, Algiers',
        ]);

        // Facebook warm clients
        $createClient->handle([
            'first_name' => 'Yasmine', 'last_name' => 'Bennani', 'phone' => '0558 999 999', 'email' => 'yasmine.bennani@example.dz',
            'source_id' => $this->item('sources', 'facebook'), 'rating_id' => $this->item('client_ratings', 'warm'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Shared referral from friend.',
        ]);

        // Phone-only warm clients
        $createClient->handle([
            'phone' => '0559 111 222',
            'source_id' => $this->item('sources', 'portal'), 'rating_id' => $this->item('client_ratings', 'warm'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Moderately interested, needs more info.',
        ]);

        $createClient->handle([
            'first_name' => 'Malik', 'last_name' => 'Saoudi', 'phone' => '0560 222 333',
            'source_id' => $this->item('sources', 'portal'), 'rating_id' => $this->item('client_ratings', 'warm'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Budget inquiry, no contact email.',
        ]);

        // ---- Cold leads (nurture potential) ----
        $this->command->line('  Creating cold leads...');

        $createClient->handle([
            'first_name' => 'Omar', 'last_name' => 'Hadj', 'phone' => '0561 333 444', 'email' => 'omar.hadj@example.dz',
            'source_id' => $this->item('sources', 'walk_in'), 'rating_id' => $this->item('client_ratings', 'cold'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Just browsing, no immediate plans.',
            'birth_date' => '1995-02-20', 'address' => '654 Rue Larbi Ben M\'hidi, Blida',
        ]);

        $createClient->handle([
            'first_name' => 'Sonia', 'last_name' => 'Kaci', 'phone' => '0562 444 555', 'email' => 'sonia.kaci@example.dz',
            'source_id' => $this->item('sources', 'instagram'), 'rating_id' => $this->item('client_ratings', 'cold'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Curiosity follow, low engagement.',
        ]);

        $createClient->handle([
            'first_name' => 'Hassan', 'last_name' => 'Berkane', 'phone' => '0563 555 666', 'email' => 'hassan.berkane@example.dz',
            'source_id' => $this->item('sources', 'facebook'), 'rating_id' => $this->item('client_ratings', 'cold'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Long-term interest, not urgent.',
        ]);

        // Phone-only cold clients
        $createClient->handle([
            'phone' => '0564 666 777',
            'source_id' => $this->item('sources', 'walk_in'), 'rating_id' => $this->item('client_ratings', 'cold'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Walk-in inquiry with low interest.',
        ]);

        $createClient->handle([
            'phone' => '0565 777 888',
            'source_id' => $this->item('sources', 'referral'), 'rating_id' => $this->item('client_ratings', 'cold'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Referral but no immediate interest.',
        ]);

        // Portal cold clients
        $createClient->handle([
            'first_name' => 'Amira', 'last_name' => 'Toufik', 'phone' => '0566 888 999', 'email' => 'amira.toufik@example.dz',
            'source_id' => $this->item('sources', 'portal'), 'rating_id' => $this->item('client_ratings', 'cold'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Browsed listings, no follow-up yet.',
        ]);

        // ---- Edge cases ----
        $this->command->line('  Creating edge cases...');

        // No name, just phone (minimal profile)
        $createClient->handle([
            'phone' => '0567 999 111',
            'source_id' => $this->item('sources', 'walk_in'), 'rating_id' => $this->item('client_ratings', 'warm'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Walk-in inquiry, no details provided.',
        ]);

        // Full identity details
        $createClient->handle([
            'first_name' => 'Mohammed', 'last_name' => 'Zerari', 'phone' => '0568 111 222', 'email' => 'mohammed.zerari@example.dz',
            'source_id' => $this->item('sources', 'referral'), 'rating_id' => $this->item('client_ratings', 'hot'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Business owner, company purchase.',
            'birth_date' => '1970-06-25', 'birth_place' => 'Algiers', 'address' => '999 Rue Zaïd Bechar, Algiers',
            'id_number' => '12345678901234', 'id_documents' => ['passport', 'national_id'],
        ]);

        // Another phone-only (to have variety in minimal profiles)
        $createClient->handle([
            'phone' => '0569 222 333',
            'source_id' => $this->item('sources', 'walk_in'), 'rating_id' => $this->item('client_ratings', 'cold'),
            'assigned_agent_id' => $agents->random()->id,
            'notes' => 'Walked in, no further details.',
        ]);

        $this->command->info('ClientSeeder complete: created 26 clients (hot/warm/cold, various sources, full/minimal profiles).');
    }

    private function item(string $listKey, string $value): int
    {
        if (! isset($this->listCache[$listKey])) {
            $listId = DynamicList::where('key', $listKey)->value('id');
            $this->listCache[$listKey] = DynamicListItem::where('dynamic_list_id', $listId)
                ->pluck('id', 'value')->all();
        }

        $id = $this->listCache[$listKey][$value] ?? null;
        abort_if($id === null, 500, "Missing dynamic-list item {$listKey}:{$value}");

        return (int) $id;
    }
}
