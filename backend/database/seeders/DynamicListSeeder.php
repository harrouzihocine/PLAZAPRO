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
                $row = DynamicListItem::firstOrCreate(
                    ['dynamic_list_id' => $list->id, 'value' => $item['value']],
                    [
                        'label' => $item['label'],
                        'sort_order' => $order,
                        'is_active' => true,
                        'meta' => $item['meta'] ?? null,
                    ],
                );

                // Backfill the default icon onto items seeded before icons existed,
                // without clobbering an admin's own choice (only fills empty keys).
                if (! empty($item['meta'])) {
                    $meta = $row->meta ?? [];
                    $merged = $item['meta'] + $meta; // existing values win
                    if ($merged !== $meta) {
                        $row->update(['meta' => $merged]);
                    }
                }
            }
        }
    }

    /**
     * @return array<string, array{name: string, description?: string, items: list<array{label: string, value: string, meta?: array<string, mixed>}>}>
     */
    private function lists(): array
    {
        return [
            // Project (location) attribute: the financing / payment options a
            // project offers its buyers — what a client can use to BUY a unit
            // here (bank loan available or not, instalments, cash…). A project
            // may offer several, so it is a multi-select on the location.
            // Distinct from `payment_methods` below (how a payment is settled).
            'project_payment_methods' => [
                'name' => 'Project Payment Methods',
                'description' => 'Financing / payment options a project offers buyers (bank loan, instalments, cash…).',
                'items' => [
                    ['label' => 'القرض البنكي غير متوفر', 'value' => 'alkrd_albnky_ghyr_mtofr'],
                    ['label' => 'القرض البنكي متوفر', 'value' => 'alkrd_albnky_mtofr'],
                    ['label' => 'امكانية التقسيط', 'value' => 'amkany_altksyt'],
                    ['label' => 'الدفع كاش', 'value' => 'aldfaa_kash'],
                    ['label' => 'التحويل البنكي', 'value' => 'bank_transfer'],
                    ['label' => 'تحويل', 'value' => 'transfer'],
                ],
            ],
            // Payment-workflow attribute: HOW a versement (a deposit / instalment
            // payment) is actually settled — the tender used. Consumed by the
            // versement + holding-deposit forms. Distinct from the project's
            // offered options in `project_payment_methods` above.
            'payment_methods' => [
                'name' => 'Payment Methods',
                'description' => 'How a payment (deposit / instalment) is settled — the tender used.',
                'items' => [
                    ['label' => 'Cash', 'value' => 'cash'],
                    ['label' => 'Bank transfer', 'value' => 'bank_transfer'],
                    ['label' => 'Cheque', 'value' => 'cheque'],
                ],
            ],
            'sources' => [
                'name' => 'Lead Sources',
                'description' => 'Where a client came from.',
                'items' => [
                    ['label' => 'Walk-in', 'value' => 'walk_in', 'meta' => ['icon' => 'pi pi-users']],
                    ['label' => 'Referral', 'value' => 'referral', 'meta' => ['icon' => 'pi pi-share-alt']],
                    ['label' => 'Facebook', 'value' => 'facebook', 'meta' => ['icon' => 'pi pi-facebook']],
                    ['label' => 'Instagram', 'value' => 'instagram', 'meta' => ['icon' => 'pi pi-instagram']],
                    ['label' => 'Ouedkniss', 'value' => 'ouedkniss', 'meta' => ['icon' => 'pi pi-tag']],
                    ['label' => 'WhatsApp', 'value' => 'whatsapp', 'meta' => ['icon' => 'pi pi-whatsapp']],
                    ['label' => 'TikTok', 'value' => 'tiktok', 'meta' => ['icon' => 'pi pi-tiktok']],
                    ['label' => 'Phone Call', 'value' => 'phone_call', 'meta' => ['icon' => 'pi pi-phone']],
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
            // Project type — a project (location) attribute: the kind of residence
            // the whole development is (open / closed / semi-closed). Attached to a
            // location and surfaced on its units and on client desires.
            'project_types' => [
                'name' => 'Project Type',
                'description' => 'The kind of residence a project is (open / closed / semi-closed).',
                'items' => [
                    ['label' => 'إقامة مفتوحة', 'value' => 'akam_mftoh'],
                    ['label' => 'إقامة مغلقة', 'value' => 'akam_mghlk'],
                    ['label' => 'اقامة شبه مغلقة', 'value' => 'akam_shbh_mghlk'],
                ],
            ],
            // Number of rooms (F2 / F3 / …) — a size attribute in its own right,
            // separate from a unit's type. Captured on units and on desires.
            'room_numbers' => [
                'name' => 'Room Numbers',
                'description' => 'Number of rooms (F2, F3, …) — the apartment size.',
                'items' => [
                    ['label' => 'studio', 'value' => 'studio'],
                    ['label' => 'F1', 'value' => 'f1'],
                    ['label' => 'F2', 'value' => 'f2'],
                    ['label' => 'F3', 'value' => 'f3'],
                    ['label' => 'F4', 'value' => 'f4'],
                    ['label' => 'F5', 'value' => 'f5'],
                    ['label' => 'F2 + T', 'value' => 'f2_t'],
                    ['label' => 'F3 + T', 'value' => 'f3_t'],
                    ['label' => 'F4 + T', 'value' => 'f4_t'],
                    ['label' => 'Dublex', 'value' => 'dublex'],
                ],
            ],
            // Sale contract a project is marketed under. Attached to a location
            // (project) and surfaced on its units. Editable in-app.
            'contract_types' => [
                'name' => 'Contract Types',
                'description' => 'The sale contract a project is sold under (VEFA, turnkey, …).',
                'items' => [
                    ['label' => 'حصة في الأرض', 'value' => 'hs_fy_alard'],
                    ['label' => 'دفتر عقاري', 'value' => 'dftr_aakary'],
                    ['label' => 'وعد بالبيع', 'value' => 'oaad_balbyaa'],
                    ['label' => 'بيع على التصميم', 'value' => 'byaa_aal_altsmym'],
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
                    ['label' => 'Requested an in-site visit', 'value' => 'requested_an_office_visit'],
                    ['label' => 'Price negotiation', 'value' => 'price_negotiation'],
                    ['label' => 'No answer', 'value' => 'no_answer'],
                    ['label' => 'Wrong number', 'value' => 'wrong_number'],
                ],
            ],
            // Concerns / objections a client raised on a call or visit — the
            // "why not" signals the Voice-of-Client analytics mine to tell the
            // promoteur what is blocking sales. Distinct from `call_topics`
            // (what was discussed): these are the reasons a client hesitates.
            'objection_reasons' => [
                'name' => 'Objection Reasons',
                'description' => 'Why a client hesitates or passes (price, location, payment plan…). Mined by the Voice-of-Client analytics.',
                'items' => [
                    ['label' => 'Price too high', 'value' => 'price_too_high'],
                    ['label' => 'Location not preferred', 'value' => 'location_not_preferred'],
                    ['label' => 'Payment plan too short', 'value' => 'payment_plan_too_short'],
                    ['label' => 'Delivery date too far', 'value' => 'delivery_too_far'],
                    ['label' => 'Unit too small', 'value' => 'unit_too_small'],
                    ['label' => 'Unit too large', 'value' => 'unit_too_large'],
                    ['label' => 'Floor not preferred', 'value' => 'floor_not_preferred'],
                    ['label' => 'Financing difficulty', 'value' => 'financing_difficulty'],
                    ['label' => 'Prefers another project', 'value' => 'prefers_another_project'],
                    ['label' => 'Just comparing', 'value' => 'just_comparing'],
                    ['label' => 'Wants more discount', 'value' => 'wants_more_discount'],
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
            // Why the open next action was corrected (change of plan on the
            // timeline). Picked from this list for fast logging; an optional
            // free note adds the detail.
            'next_action_change_reasons' => [
                'name' => 'Next-Action Change Reasons',
                'description' => 'Why the pending next action was changed (fast timeline correction).',
                'items' => [
                    ['label' => 'Client requested a reschedule', 'value' => 'client_reschedule'],
                    ['label' => 'Client unavailable', 'value' => 'client_unavailable'],
                    ['label' => 'Changed the type of next step', 'value' => 'changed_type'],
                    ['label' => 'Reassigned to another agent', 'value' => 'reassigned'],
                    ['label' => 'Logged by mistake', 'value' => 'data_entry_error'],
                    ['label' => 'Other', 'value' => 'other'],
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
                    ['label' => '6th Floor', 'value' => '6th_floor'],
                    ['label' => '7th Floor', 'value' => '7th_floor'],
                    ['label' => '8th Floor', 'value' => '8th_floor'],
                    ['label' => '9th Floor', 'value' => '9th_floor'],
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
