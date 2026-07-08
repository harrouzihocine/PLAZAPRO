<?php

declare(strict_types=1);

use App\Modules\Settings\Support\DefaultListTranslations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trilingual dropdowns: every dynamic-list item gains an optional per-locale
 * label map ({"en":…,"fr":…,"ar":…}); display is translations[locale] ?? label.
 * Backfills the default items that shipped with DynamicListSeeder on live DBs
 * (matched by list key + item value, never touching admin-written labels).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_list_items', function (Blueprint $table) {
            $table->json('label_translations')->nullable()->after('label');
        });

        foreach (DefaultListTranslations::map() as $listKey => $items) {
            $listId = DB::table('dynamic_lists')->where('key', $listKey)->value('id');
            if ($listId === null) {
                continue;
            }

            foreach ($items as $value => $translations) {
                DB::table('dynamic_list_items')
                    ->where('dynamic_list_id', $listId)
                    ->where('value', $value)
                    ->whereNull('label_translations')
                    ->update(['label_translations' => json_encode($translations, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('dynamic_list_items', function (Blueprint $table) {
            $table->dropColumn('label_translations');
        });
    }
};
