<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Legacy CRM import (php artisan legacy:import)
|--------------------------------------------------------------------------
| Every tunable of the legacy importer lives here — no magic values in the
| importer classes. The authoritative mapping spec is PLAZA_MIGRATION_PLAN.md
| at the repo root; section references (§) below point into it.
*/

return [

    // Laravel connection name of the staging schema the dump is loaded into.
    'connection' => 'legacy',

    // Where fresh dumps are dropped (scp from the old server). --load picks
    // the newest *.sql here unless a filename is given.
    'dumps_path' => 'database/data',

    // Subdirectory of storage/app that receives run reports, the warnings
    // ledger and the duplicate-clients CSV.
    'report_dir' => 'legacy-import',

    /*
    |----------------------------------------------------------------------
    | Locked decisions & flags for Hocine (§9)
    |----------------------------------------------------------------------
    */

    // Legacy prices are "millions de centimes": 850 → 8,500,000 DZD (§3).
    // ⚠ Confirm with Hocine before the production run.
    'price_multiplier' => 10000,

    // Legacy visit_reports were unit presentations → 'office' semantics
    // (§5.10). Also keeps tasks cat 2 → next_actions.type consistent
    // ('office' → office_visit, 'in_site' → in_site_visit, §5.11).
    'legacy_visit_type' => 'office',

    // Funnel stage for archived projects whose pre-archive status resolves
    // to lead (7,304 archived 'expected' + 210 'in visit' = dead leads,
    // §5.8). Alternative: 'lead'.
    'archived_lead_stage' => 'lost',

    // §6.1 fallback: a legacy unit with is_available=0 and no won deal /
    // versement / active hold. 4 years of sales vs 23 recorded payments
    // means it almost always meant sold. Alternative: 'available'. Every
    // unit set sold by this fallback goes to the review ledger.
    'legacy_unavailable_means' => 'sold',

    // Import all duplicate-phone clients as-is (no unique on phone); the
    // app's client_duplicate_requests workflow resolves them later (§5.2).
    'merge_duplicate_clients' => false,

    /*
    |----------------------------------------------------------------------
    | System user (§4.1) — only used where the target column is NOT NULL
    | and legacy has no author (e.g. media.uploaded_by).
    |----------------------------------------------------------------------
    */
    'system_user' => [
        'name' => 'Legacy Import',
        'email' => 'legacy-import@plaza-pro.dz',
        'username' => 'legacy-import',
        'role_slug' => 'admin',
    ],

    /*
    |----------------------------------------------------------------------
    | Users (§5.1) — legacy role/department names → new slugs. Roles and
    | permissions themselves are never imported; the new RBAC is
    | authoritative. Legacy role 'x' / dept 'x' are junk rows.
    |----------------------------------------------------------------------
    */
    'role_map' => [
        'admin' => 'admin',
        'semi-admin' => 'manager',
        'employee' => 'sales-agent',
    ],
    'role_fallback' => 'sales-agent', // unknown / junk role → fallback + warn

    'department_map' => [
        'admins' => 'administration',
        'المسؤولين' => 'direction',
        'فريق الاتصال' => 'ventes',
        'فريق الزيارات' => 'ventes',
        'قسم الحجوزات' => 'finance',
    ],

    /*
    |----------------------------------------------------------------------
    | Pipeline stages (§5.8). Legacy project status → new stage. The plan
    | wrote 'reserved' for selling; that stage was renamed to 'deal' in the
    | 2026-07 vocabulary swap (decision: map to 'deal'). Archived projects
    | use stageMap(last_status ?? 'expected'), downgraded per
    | archived_lead_stage when that resolves to 'lead'.
    |----------------------------------------------------------------------
    */
    'stage_map' => [
        'expected' => 'lead',
        'in visit' => 'lead',
        'negotiating' => 'negotiating',
        'selling' => 'deal',
        'desires fullfiled' => 'lead',
    ],

    /*
    |----------------------------------------------------------------------
    | Dynamic-list pins (§4.3). Keyed by list key → normalized legacy label
    | → existing item VALUE (never id — survives reseeding). The resolver
    | never creates an item when a pin exists.
    |----------------------------------------------------------------------
    */
    'list_pins' => [
        'client_ratings' => [
            '⭐⭐⭐' => 'hot',
            '⭐⭐' => 'warm',
            '⭐' => 'cold',
        ],
        'sources' => [
            'Oued Kniss' => 'ouedkniss',
            'Facebook Page' => 'facebook',
            'Instagram' => 'instagram',
            'TikTok' => 'tiktok',
        ],
        'floors' => [
            'RDC' => 'ground',
            '1' => 'floor_1',
            '2' => 'floor_2',
            '3' => 'floor_3',
            '4' => 'floor_4',
            '5' => 'floor_5',
            // The DynamicListSeeder switches naming style from floor 6 up.
            '6' => '6th_floor',
            '7' => '7th_floor',
            '8' => '8th_floor',
            '9' => '9th_floor',
        ],
        'room_numbers' => [
            'Studio' => 'studio',
            'F2' => 'f2',
            'F3' => 'f3',
            'F4' => 'f4',
            'F5' => 'f5',
            'Dublex' => 'dublex',
            'F2 + T' => 'f2_t',
            'F3 + T' => 'f3_t',
            'F4 + T' => 'f4_t',
        ],
        'project_types' => [
            'إقامة مفتوحة' => 'akam_mftoh',
            'إقامة مغلقة' => 'akam_mghlk',
            'اقامة شبه مغلقة' => 'akam_shbh_mghlk',
        ],
        'contract_types' => [
            'حصة في الأرض' => 'hs_fy_alard',
            'دفتر عقاري' => 'dftr_aakary',
            'وعد بالبيع' => 'oaad_balbyaa',
            'بيع على التصميم' => 'byaa_aal_altsmym',
        ],
        'project_payment_methods' => [
            'القرض البنكي غير متوفر' => 'alkrd_albnky_ghyr_mtofr',
            'القرض البنكي متوفر' => 'alkrd_albnky_mtofr',
            'امكانية التقسيط' => 'amkany_altksyt',
            'الدفع كاش' => 'aldfaa_kash',
        ],
        'payment_methods' => [
            'دفع كاش' => 'cash',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Dynamic-list creations with explicit label/value (§4.3). Labels not
    | listed here or in list_pins are auto-created (label = legacy label,
    | value = ASCII slug, meta {"legacy": true}).
    |----------------------------------------------------------------------
    */
    'list_creates' => [
        'floors' => [
            '10' => ['label' => '10th Floor', 'value' => 'floor_10'],
            '11' => ['label' => '11th Floor', 'value' => 'floor_11'],
            'En s1' => ['label' => 'Sous-sol 1', 'value' => 'sous_sol_1'],
            'En s2' => ['label' => 'Sous-sol 2', 'value' => 'sous_sol_2'],
        ],
        'payment_methods' => [
            'عربون' => ['label' => 'عربون (acompte)', 'value' => 'arboun'],
            '50% دفع' => ['label' => 'Non précisé (legacy)', 'value' => 'legacy_unspecified'],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Geography (§4.4). Legacy estate_locations are Algiers commune names in
    | Arabic → new communes.name (wilaya 16). NULL = no commune ("elsewhere").
    |----------------------------------------------------------------------
    */
    'commune_map' => [
        'برج الكيفان' => 'Bordj El Kiffan',
        'دالي ابراهيم' => 'Dely Ibrahim',
        'برج البحري' => 'Bordj El Bahri',
        'باب الزوار' => 'Bab Ezzouar',
        'قايدي' => 'Bordj El Kiffan', // Kaïdi is a BEK neighbourhood
        'شراقة' => 'Cheraga',
        'المرادية' => 'El Mouradia',
        'عين طاية' => 'Ain Taya',
        'درارية' => 'Draria',
        'بير خادم' => 'Birkhadem',
        'واد السمار' => 'Oued Smar',
        'العاشور' => 'El Achour',
        'اماكن اخرى' => null,
    ],

    /*
    |----------------------------------------------------------------------
    | Convergence pins for hand-entered rows (§5.5/§5.6). The plan's match
    | key (location, lower(reference), floor) cannot converge on the real
    | data: the hand-entered PERLA units carry door-number references
    | (201, 405, …) while legacy identity lives in estates.note (F3/03, NULL,
    | …). These pins realize the plan's intent deterministically; they were
    | derived by matching floor + area + price against both live databases.
    | Pins are by name/reference — a fresh production DB without the
    | hand-entered rows simply misses the lookup and creates the unit.
    |----------------------------------------------------------------------
    */

    // Legacy estate_categories.name → existing locations.name (when the
    // normalized names differ). PERLA ↔ PERLA already matches by name.
    'location_name_pins' => [
        'Mourdjen' => 'EL MOURDJAN',
    ],

    // Legacy estates.id → existing units.reference at the matched location.
    'unit_reference_pins' => [
        639 => '405', // F3/03 · 4th floor · 72.50 m²
        685 => '201', // F3 · 2nd floor · 94.10 m²
        734 => 'R01', // F3+C · RDC · 86.5+10.5 m²
        759 => '507', // Studio · 5th floor · 39.81 m²
        760 => '501', // F3 · 5th floor · 94.10 m²
        761 => '601', // F3 · 6th floor · 94.10 m²
        762 => '701', // F3+T · 7th floor · 96.40 m²
        763 => '702', // F3+T · 7th floor · 101.62 m²
    ],
];
