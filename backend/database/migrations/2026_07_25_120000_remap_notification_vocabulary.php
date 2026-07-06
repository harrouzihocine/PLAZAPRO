<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Companion to the interested/reserved vocabulary swap: bring the DURABLE bell
 * records (notifications.data JSON) over to the new words, so history does not
 * read as the opposite state (old "reserved" meant a client hold — now called
 * Interested; old "on hold" meant the deposit lock — now called Reserved).
 *
 * Uses JSON functions (not string REPLACE on the JSON column) because MySQL's
 * JSON rendering ("kind": "x", with a space) makes raw REPLACE patterns
 * unreliable. Texts are rewritten only on the two machine-generated shapes:
 *   unit_status    title 'Unit REF — <label>'  body '<Label>[ · details]'
 *   onhold_lapsed  title 'Hold expired'        body 'The hold on REF… lapsed …'
 * Replacement order is load-bearing: reserved→interested BEFORE on hold→reserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Machine-readable kind first: onhold_lapsed → reserved_lapsed.
            DB::table('notifications')
                ->whereRaw("json_unquote(json_extract(data, '$.kind')) = 'onhold_lapsed'")
                ->update(['data' => DB::raw("json_set(data, '$.kind', 'reserved_lapsed')")]);

            // unit_status titles: '… — reserved' → '… — interested' first, then
            // '… — on hold' → '… — reserved'.
            DB::table('notifications')
                ->whereRaw("json_unquote(json_extract(data, '$.kind')) = 'unit_status'")
                ->update(['data' => DB::raw(<<<'SQL'
                    json_set(data, '$.title',
                        replace(
                            replace(json_unquote(json_extract(data, '$.title')), '— reserved', '— interested'),
                            '— on hold', '— reserved'
                        )
                    )
                SQL)]);

            // unit_status bodies start with the label — rewrite anchored to the
            // prefix so a location name containing the word is never touched.
            DB::table('notifications')
                ->whereRaw("json_unquote(json_extract(data, '$.kind')) = 'unit_status'")
                ->update(['data' => DB::raw(<<<'SQL'
                    json_set(data, '$.body',
                        case
                            when json_unquote(json_extract(data, '$.body')) like 'Reserved%'
                                then concat('Interested', substring(json_unquote(json_extract(data, '$.body')), 9))
                            when json_unquote(json_extract(data, '$.body')) like 'On hold%'
                                then concat('Reserved', substring(json_unquote(json_extract(data, '$.body')), 8))
                            else json_unquote(json_extract(data, '$.body'))
                        end
                    )
                SQL)]);

            // Lapsed-deposit records: 'Hold expired' / 'The hold on …'.
            DB::table('notifications')
                ->whereRaw("json_unquote(json_extract(data, '$.kind')) = 'reserved_lapsed'")
                ->update(['data' => DB::raw(<<<'SQL'
                    json_set(data,
                        '$.title',
                        case when json_unquote(json_extract(data, '$.title')) = 'Hold expired'
                             then 'Reservation expired'
                             else json_unquote(json_extract(data, '$.title')) end,
                        '$.body',
                        case when json_unquote(json_extract(data, '$.body')) like 'The hold on %'
                             then concat('The reservation on ', substring(json_unquote(json_extract(data, '$.body')), 13))
                             else json_unquote(json_extract(data, '$.body')) end
                    )
                SQL)]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('notifications')
                ->whereRaw("json_unquote(json_extract(data, '$.kind')) = 'reserved_lapsed'")
                ->update(['data' => DB::raw(<<<'SQL'
                    json_set(data,
                        '$.title',
                        case when json_unquote(json_extract(data, '$.title')) = 'Reservation expired'
                             then 'Hold expired'
                             else json_unquote(json_extract(data, '$.title')) end,
                        '$.body',
                        case when json_unquote(json_extract(data, '$.body')) like 'The reservation on %'
                             then concat('The hold on ', substring(json_unquote(json_extract(data, '$.body')), 20))
                             else json_unquote(json_extract(data, '$.body')) end
                    )
                SQL)]);

            // Reverse order: reserved→on hold first, then interested→reserved.
            DB::table('notifications')
                ->whereRaw("json_unquote(json_extract(data, '$.kind')) = 'unit_status'")
                ->update(['data' => DB::raw(<<<'SQL'
                    json_set(data, '$.title',
                        replace(
                            replace(json_unquote(json_extract(data, '$.title')), '— reserved', '— on hold'),
                            '— interested', '— reserved'
                        )
                    )
                SQL)]);

            DB::table('notifications')
                ->whereRaw("json_unquote(json_extract(data, '$.kind')) = 'unit_status'")
                ->update(['data' => DB::raw(<<<'SQL'
                    json_set(data, '$.body',
                        case
                            when json_unquote(json_extract(data, '$.body')) like 'Reserved%'
                                then concat('On hold', substring(json_unquote(json_extract(data, '$.body')), 9))
                            when json_unquote(json_extract(data, '$.body')) like 'Interested%'
                                then concat('Reserved', substring(json_unquote(json_extract(data, '$.body')), 11))
                            else json_unquote(json_extract(data, '$.body'))
                        end
                    )
                SQL)]);

            DB::table('notifications')
                ->whereRaw("json_unquote(json_extract(data, '$.kind')) = 'reserved_lapsed'")
                ->update(['data' => DB::raw("json_set(data, '$.kind', 'onhold_lapsed')")]);
        });
    }
};
