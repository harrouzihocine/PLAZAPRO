<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-key and relabel the dynamic list now that "unit type" has become a project
 * (location) attribute — see 2026_07_21_100000. Only the list's key/name/description
 * change; the item ids are untouched, so every FK that references them (locations,
 * desires) keeps pointing at the same rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('dynamic_lists')->where('key', 'unit_types')->update([
            'key' => 'project_types',
            'name' => 'Project Type',
            'description' => 'The kind of residence a project is (open / closed / semi-closed).',
        ]);
    }

    public function down(): void
    {
        DB::table('dynamic_lists')->where('key', 'project_types')->update([
            'key' => 'unit_types',
            'name' => 'Unit Types',
            'description' => 'Apartment / property layout (incl. commercial "local").',
        ]);
    }
};
