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
                'description' => 'Apartment / property layout (incl. commercial "local").',
                'items' => [
                    ['label' => 'Studio', 'value' => 'studio'],
                    ['label' => 'F2', 'value' => 'f2'],
                    ['label' => 'F3', 'value' => 'f3'],
                    ['label' => 'F4', 'value' => 'f4'],
                    ['label' => 'Duplex', 'value' => 'duplex'],
                    ['label' => 'Local / Commercial', 'value' => 'local'],
                ],
            ],
            // Sale contract a project is marketed under. Attached to a location
            // (project) and surfaced on its units. Editable in-app.
            'contract_types' => [
                'name' => 'Contract Types',
                'description' => 'The sale contract a project is sold under (VEFA, turnkey, …).',
                'items' => [
                    ['label' => 'VEFA (off-plan)', 'value' => 'vefa'],
                    ['label' => 'Turnkey (ready)', 'value' => 'turnkey'],
                    ['label' => 'Rent-to-own', 'value' => 'rent_to_own'],
                    ['label' => 'Cash sale', 'value' => 'cash_sale'],
                ],
            ],
            // What a client is shopping for — captured at lead creation, drives
            // qualification & the office-visit shortlist. Multi-select.
            'property_interests' => [
                'name' => 'Property Interests',
                'description' => 'What the client wants to buy (apartment / box / local). Multi-select.',
                'items' => [
                    ['label' => 'Apartment', 'value' => 'apartment'],
                    ['label' => 'Box', 'value' => 'box'],
                    ['label' => 'Local / Commercial', 'value' => 'local'],
                ],
            ],
            // Fast checkbox talking-points logged against a phone call. The former
            // separate "call outcome" reasons live here too — one Discussed list.
            'call_topics' => [
                'name' => 'Call Topics',
                'description' => 'What was discussed on a call (checkboxes, fast logging).',
                'items' => [
                    ['label' => 'Introduced the project', 'value' => 'introduced_project'],
                    ['label' => 'Discussed budget', 'value' => 'discussed_budget'],
                    ['label' => 'Sent brochure / media', 'value' => 'sent_media'],
                    ['label' => 'Interested', 'value' => 'interested'],
                    ['label' => 'Not interested (now)', 'value' => 'not_interested'],
                    ['label' => 'Requested a callback', 'value' => 'requested_callback'],
                    ['label' => 'Requested an office visit', 'value' => 'requested_office_visit'],
                    ['label' => 'Price negotiation', 'value' => 'price_negotiation'],
                    ['label' => 'No answer', 'value' => 'no_answer'],
                    ['label' => 'Wrong number', 'value' => 'wrong_number'],
                ],
            ],
            // Fast checkbox checklist logged against an office visit.
            'office_visit_checklist' => [
                'name' => 'Office Visit Checklist',
                'description' => 'What happened during the office visit (checkboxes).',
                'items' => [
                    ['label' => 'Showed stacking plan', 'value' => 'showed_stacking_plan'],
                    ['label' => 'Presented units', 'value' => 'presented_units'],
                    ['label' => 'Discussed price', 'value' => 'discussed_price'],
                    ['label' => 'Discussed payment plan', 'value' => 'discussed_payment_plan'],
                    ['label' => 'Showed media', 'value' => 'showed_media'],
                    ['label' => 'Client satisfied', 'value' => 'client_satisfied'],
                    ['label' => 'Requested an in-site visit', 'value' => 'requested_insite_visit'],
                ],
            ],
            // Per-property result recorded by the site agent during a field visit.
            'insite_outcomes' => [
                'name' => 'In-site Visit Outcomes',
                'description' => 'Per-property result of a field (in-site) visit.',
                'items' => [
                    ['label' => 'Not visited', 'value' => 'not_visited'],
                    ['label' => 'Visited – interested', 'value' => 'visited_interested'],
                    ['label' => 'Visited – not interested', 'value' => 'visited_not_interested'],
                    ['label' => 'Needs a second visit', 'value' => 'needs_second_visit'],
                ],
            ],
            // Why a deal was archived (required when archiving / losing a deal).
            'archive_reasons' => [
                'name' => 'Archive Reasons',
                'description' => 'Why a deal was archived or lost (required to archive).',
                'items' => [
                    ['label' => 'Changed mind', 'value' => 'changed_mind'],
                    ['label' => 'Found an alternative', 'value' => 'found_alternative'],
                    ['label' => 'Price too high', 'value' => 'price_too_high'],
                    ['label' => 'Financing fell through', 'value' => 'financing_failed'],
                    ['label' => 'Postponed', 'value' => 'postponed'],
                    ['label' => 'Other', 'value' => 'other'],
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
