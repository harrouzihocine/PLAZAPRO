<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Payment Methods" carried two different meanings on one list. Split them:
 *
 *  - The ORIGINAL list held a project (location) attribute — the financing /
 *    payment options a project offers buyers (bank loan available or not,
 *    instalments, cash…). Re-key it to `project_payment_methods`.
 *  - A FRESH `payment_methods` list is (re)seeded for the payment workflow —
 *    HOW a deposit / instalment is settled (cash, bank transfer, cheque). It
 *    is created by DynamicListSeeder, not here.
 *
 * Only the list key/name/description change; item ids are untouched, so any FK
 * that referenced them keeps pointing at the same rows. Guarded so it is a
 * no-op on a fresh database (the seeder then creates both lists cleanly) and
 * runs at most once on an existing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $alreadySplit = DB::table('dynamic_lists')->where('key', 'project_payment_methods')->exists();

        if (! $alreadySplit) {
            DB::table('dynamic_lists')->where('key', 'payment_methods')->update([
                'key' => 'project_payment_methods',
                'name' => 'Project Payment Methods',
                'description' => 'Financing / payment options a project offers buyers (bank loan, instalments, cash…).',
            ]);
        }
    }

    public function down(): void
    {
        // Reverse the rename. The re-seeded workflow `payment_methods` list (if
        // any) is left for the seeder to manage; we only undo the re-key.
        DB::table('dynamic_lists')->where('key', 'project_payment_methods')->update([
            'key' => 'payment_methods',
            'name' => 'Payment Methods',
            'description' => 'How a versement (instalment) is paid.',
        ]);
    }
};
