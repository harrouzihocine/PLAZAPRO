<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Database\Seeder;

/**
 * Seeds the system dynamic lists and sensible starting items. Everything here is
 * editable in-app through the Lists admin; these are defaults, not fixed config.
 * Idempotent: safe to re-run (firstOrCreate on key / (list, value)).
 */
class DynamicListSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->lists() as $key => $config) {
            $list = DynamicList::firstOrCreate(
                ['key' => $key],
                [
                    'name' => $config['name'],
                    'description' => $config['description'] ?? null,
                    'is_system' => true,
                ],
            );

            foreach (array_values($config['items']) as $order => $item) {
                DynamicListItem::firstOrCreate(
                    ['dynamic_list_id' => $list->id, 'value' => $item['value']],
                    [
                        'label' => $item['label'],
                        'sort_order' => $order,
                        'is_active' => true,
                        'meta' => $item['meta'] ?? null,
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, array{name: string, description?: string, items: list<array{label: string, value: string, meta?: array<string, mixed>}>}>
     */
    private function lists(): array
    {
        return [
            'payment_methods' => [
                'name' => 'Payment Methods',
                'description' => 'How a versement (instalment) is paid.',
                'items' => [
                    ['label' => 'Cash', 'value' => 'cash'],
                    ['label' => 'Cheque', 'value' => 'cheque'],
                    ['label' => 'Bank Transfer', 'value' => 'bank_transfer'],
                ],
            ],
            'sources' => [
                'name' => 'Lead Sources',
                'description' => 'Where a client came from.',
                'items' => [
                    ['label' => 'Walk-in', 'value' => 'walk_in'],
                    ['label' => 'Referral', 'value' => 'referral'],
                    ['label' => 'Facebook', 'value' => 'facebook'],
                    ['label' => 'Instagram', 'value' => 'instagram'],
                    ['label' => 'Property Portal', 'value' => 'portal'],
                ],
            ],
            'client_ratings' => [
                'name' => 'Client Ratings',
                'description' => 'Lead temperature used to prioritise follow-up.',
                'items' => [
                    ['label' => 'Hot', 'value' => 'hot', 'meta' => ['weight' => 3, 'color' => '#e11d48']],
                    ['label' => 'Warm', 'value' => 'warm', 'meta' => ['weight' => 2, 'color' => '#f59e0b']],
                    ['label' => 'Cold', 'value' => 'cold', 'meta' => ['weight' => 1, 'color' => '#3b82f6']],
                ],
            ],
            'cancellation_reasons' => [
                'name' => 'Cancellation Reasons',
                'description' => 'Why a reservation, deal or record was cancelled.',
                'items' => [
                    ['label' => 'Changed mind', 'value' => 'changed_mind'],
                    ['label' => 'Financing fell through', 'value' => 'financing_failed'],
                    ['label' => 'Found an alternative', 'value' => 'found_alternative'],
                    ['label' => 'Price too high', 'value' => 'price_too_high'],
                    ['label' => 'Other', 'value' => 'other'],
                ],
            ],
            'unit_types' => [
                'name' => 'Unit Types',
                'description' => 'Apartment / property layout.',
                'items' => [
                    ['label' => 'Studio', 'value' => 'studio'],
                    ['label' => 'F2', 'value' => 'f2'],
                    ['label' => 'F3', 'value' => 'f3'],
                    ['label' => 'F4', 'value' => 'f4'],
                    ['label' => 'Duplex', 'value' => 'duplex'],
                ],
            ],
            'box_types' => [
                'name' => 'Box Types',
                'description' => 'Ancillary lots sold alongside a unit.',
                'items' => [
                    ['label' => 'Parking', 'value' => 'parking'],
                    ['label' => 'Storage', 'value' => 'storage'],
                ],
            ],
            'floors' => [
                'name' => 'Floors',
                'description' => 'Floor levels used across the inventory.',
                'items' => [
                    ['label' => 'Ground Floor', 'value' => 'ground'],
                    ['label' => '1st Floor', 'value' => 'floor_1'],
                    ['label' => '2nd Floor', 'value' => 'floor_2'],
                    ['label' => '3rd Floor', 'value' => 'floor_3'],
                    ['label' => '4th Floor', 'value' => 'floor_4'],
                    ['label' => '5th Floor', 'value' => 'floor_5'],
                ],
            ],
            // Geography (wilayas / communes) lives in its own tables now — see
            // WilayaCommuneSeeder — not in this flat dynamic list.
            'visit_outcomes' => [
                'name' => 'Visit Outcomes',
                'description' => 'Result recorded after an apartment visit.',
                'items' => [
                    ['label' => 'Interested', 'value' => 'interested'],
                    ['label' => 'Not interested', 'value' => 'not_interested'],
                    ['label' => 'Needs follow-up', 'value' => 'needs_followup'],
                    ['label' => 'Reserved', 'value' => 'reserved'],
                    ['label' => 'No-show', 'value' => 'no_show'],
                ],
            ],
            'call_outcomes' => [
                'name' => 'Call Outcomes',
                'description' => 'Result recorded after a phone call.',
                'items' => [
                    ['label' => 'Answered', 'value' => 'answered'],
                    ['label' => 'No answer', 'value' => 'no_answer'],
                    ['label' => 'Callback requested', 'value' => 'callback_requested'],
                    ['label' => 'Interested', 'value' => 'interested'],
                    ['label' => 'Not interested', 'value' => 'not_interested'],
                    ['label' => 'Wrong number', 'value' => 'wrong_number'],
                ],
            ],
        ];
    }
}
