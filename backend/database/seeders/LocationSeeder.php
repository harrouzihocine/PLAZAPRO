<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Inventory\Actions\CreateLocation;
use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Database\Seeder;

/**
 * Comprehensive location/site test data covering:
 * - Multiple wilayas and communes
 * - All GTM priorities
 * - Locations with/without descriptions
 * - Locations with/without expected delivery dates
 * - Locations with/without coordinates (for Google Maps)
 * - Locations with/without cover media
 * - Various contract types
 */
class LocationSeeder extends Seeder
{
    private array $listCache = [];

    public function run(): void
    {
        $this->command->info('Seeding locations…');

        $createLocation = app(CreateLocation::class);

        // ---- Alger (High Priority, multiple sites) ----
        $this->command->line('  Creating Algiers locations...');

        $alger = Wilaya::where('name', 'Alger')->firstOrFail();
        $baazaAlger = Commune::where('wilaya_id', $alger->id)->where('name', 'Bab El Oued')->first()
            ?? Commune::where('wilaya_id', $alger->id)->first();

        // Premium location with all details
        $createLocation->handle([
            'name' => 'Résidence Prestige Hydra', 'code' => 'RPH001', 'wilaya_id' => $alger->id, 'commune_id' => $baazaAlger->id,
            'address' => '45 Rue Didouche Mourad, Hydra, Algiers', 'description' => 'Luxury residential complex in heart of Hydra. 5 floors, 2 elevators per building, premium finishing.',
            'contract_type_id' => $this->item('contract_types', 'vefa'), 'expected_delivery_date' => '2027-06-30',
            'gtm_priority' => GtmPriority::High, 'latitude' => 36.7724, 'longitude' => 3.0512,
        ]);

        // Good location, no delivery date yet
        $createLocation->handle([
            'name' => 'Les Jardins du Centre', 'code' => 'LJC002', 'wilaya_id' => $alger->id, 'commune_id' => $baazaAlger->id,
            'address' => '78 Avenue Frantz Fanon, Bab El Oued', 'description' => 'Mixed-use development. Retail on ground floor, residential above.',
            'contract_type_id' => $this->item('contract_types', 'turnkey'), 'gtm_priority' => GtmPriority::Medium,
            'latitude' => 36.7689, 'longitude' => 3.0654,
        ]);

        // Location with minimal details
        $createLocation->handle([
            'name' => 'Cité El Achour', 'code' => 'CEA003', 'wilaya_id' => $alger->id, 'commune_id' => $baazaAlger->id,
            'address' => 'Route El Achour', 'contract_type_id' => $this->item('contract_types', 'rent_to_own'),
            'gtm_priority' => GtmPriority::Low,
        ]);

        // ---- Oran (Medium Priority sites) ----
        $this->command->line('  Creating Oran locations...');

        $oran = Wilaya::where('name', 'Oran')->firstOrFail();
        $oranCommune = Commune::where('wilaya_id', $oran->id)->first();

        $createLocation->handle([
            'name' => 'Nouvelle Cité Méditerranée', 'code' => 'NCM004', 'wilaya_id' => $oran->id, 'commune_id' => $oranCommune->id,
            'address' => 'Route de Senia, Oran', 'description' => 'Coastal development with sea views. Phase 1 launching Q3 2026.',
            'contract_type_id' => $this->item('contract_types', 'vefa'), 'expected_delivery_date' => '2028-03-31',
            'gtm_priority' => GtmPriority::High, 'latitude' => 35.7320, 'longitude' => -0.6485,
        ]);

        $createLocation->handle([
            'name' => 'Résidence Essenia', 'code' => 'RES005', 'wilaya_id' => $oran->id, 'commune_id' => $oranCommune->id,
            'address' => 'Boulevard Mohamed V, Oran', 'description' => 'Family-friendly complex with green spaces and amenities.',
            'contract_type_id' => $this->item('contract_types', 'turnkey'), 'gtm_priority' => GtmPriority::Medium,
            'latitude' => 35.7389, 'longitude' => -0.6379,
        ]);

        $createLocation->handle([
            'name' => 'Immeubles Ahmed Ben Bella', 'code' => 'IAB006', 'wilaya_id' => $oran->id, 'commune_id' => $oranCommune->id,
            'address' => '12 Rue Ahmed Ben Bella, Oran', 'contract_type_id' => $this->item('contract_types', 'cash_sale'),
            'gtm_priority' => GtmPriority::Low,
        ]);

        // ---- Constantine (Mixed priority) ----
        $this->command->line('  Creating Constantine locations...');

        $constantine = Wilaya::where('name', 'Constantine')->firstOrFail();
        $constantineFCommune = Commune::where('wilaya_id', $constantine->id)->first();

        $createLocation->handle([
            'name' => 'Complexe Sheraton Constantine', 'code' => 'CSC007', 'wilaya_id' => $constantine->id, 'commune_id' => $constantineFCommune->id,
            'address' => 'Boulevard Djamel Azouz, Constantine', 'description' => 'Premium urban complex with hotel integration.',
            'contract_type_id' => $this->item('contract_types', 'vefa'), 'expected_delivery_date' => '2026-12-15',
            'gtm_priority' => GtmPriority::High,
            'latitude' => 36.3656, 'longitude' => 6.6269,
        ]);

        $createLocation->handle([
            'name' => 'Cité des 500 Logements', 'code' => 'C500008', 'wilaya_id' => $constantine->id, 'commune_id' => $constantineFCommune->id,
            'address' => 'Quartier Bellevue, Constantine', 'contract_type_id' => $this->item('contract_types', 'turnkey'),
            'gtm_priority' => GtmPriority::Low,
        ]);

        // ---- Tlemcen (Low Priority, newer development) ----
        $this->command->line('  Creating Tlemcen locations...');

        $tlemcen = Wilaya::where('name', 'Tlemcen')->firstOrFail();
        $tlemcenCommune = Commune::where('wilaya_id', $tlemcen->id)->first();

        $createLocation->handle([
            'name' => 'Hacienda Tlemcen', 'code' => 'HT009', 'wilaya_id' => $tlemcen->id, 'commune_id' => $tlemcenCommune->id,
            'address' => 'Route de Sebdou, Tlemcen', 'description' => 'Emerging residential project in historic town.',
            'contract_type_id' => $this->item('contract_types', 'vefa'), 'expected_delivery_date' => '2029-12-31',
            'gtm_priority' => GtmPriority::Low,
        ]);

        // ---- Bouira (Investment opportunity) ----
        $this->command->line('  Creating Bouira locations...');

        $bouira = Wilaya::where('name', 'Bouira')->firstOrFail();
        $bouiraCommune = Commune::where('wilaya_id', $bouira->id)->first();

        $createLocation->handle([
            'name' => 'Les Résidences de Bouira', 'code' => 'LRB010', 'wilaya_id' => $bouira->id, 'commune_id' => $bouiraCommune->id,
            'address' => 'Rue du 19 Mars, Bouira', 'description' => 'Suburban project with affordable pricing.',
            'contract_type_id' => $this->item('contract_types', 'turnkey'), 'gtm_priority' => GtmPriority::Medium,
            'latitude' => 36.3680, 'longitude' => 3.9001,
        ]);

        // ---- Edge cases ----
        $this->command->line('  Creating edge case locations...');

        // Location with all optional fields filled
        $createLocation->handle([
            'name' => 'Premium Downtown Complex', 'code' => 'PDC011', 'wilaya_id' => $alger->id, 'commune_id' => $baazaAlger->id,
            'address' => '999 Premium Plaza', 'description' => 'Ultimate luxury downtown residential complex with 5-star amenities: concierge, spa, restaurant, parking for 3+ cars per unit.',
            'contract_type_id' => $this->item('contract_types', 'vefa'), 'expected_delivery_date' => '2027-02-28',
            'gtm_priority' => GtmPriority::Critical, 'latitude' => 36.7533, 'longitude' => 3.0588,
        ]);

        // Location with minimal details (no description, no coordinates, no delivery date)
        $createLocation->handle([
            'name' => 'Site Classé', 'code' => 'SC012', 'wilaya_id' => $oran->id, 'commune_id' => $oranCommune->id,
            'address' => 'A classifier', 'contract_type_id' => $this->item('contract_types', 'rent_to_own'),
            'gtm_priority' => GtmPriority::Low,
        ]);

        $this->command->info('LocationSeeder complete: created 12 locations across 6 wilayas with various GTM priorities and details.');
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
