<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rework the client identity/contract capture:
 *  - A client can now present more than one ID document, so the single
 *    id_document_type/id_document_number pair becomes an `id_documents` JSON
 *    list, each entry holding {type, number, issued_at, issued_place} — the
 *    issue date + place (تاريخ الإصدار و مكان الإصدار) are per document.
 *  - `id_number` captures the Algerian national identification number (NIN,
 *    رقم التعريف الوطني), which is distinct from the ID-card document number.
 *  - `nationality` and `occupation` are dropped — no longer captured.
 * Existing single documents are folded into the new list so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->json('id_documents')->nullable()->after('notes');
            $table->string('id_number', 100)->nullable()->after('id_documents');
        });

        // Fold any existing single document into the new list.
        DB::table('clients')
            ->whereNotNull('id_document_type')
            ->orWhereNotNull('id_document_number')
            ->select('id', 'id_document_type', 'id_document_number')
            ->orderBy('id')
            ->each(function ($client) {
                DB::table('clients')->where('id', $client->id)->update([
                    'id_documents' => json_encode([[
                        'type' => $client->id_document_type,
                        'number' => $client->id_document_number,
                        'issued_at' => null,
                        'issued_place' => null,
                    ]], JSON_UNESCAPED_UNICODE),
                ]);
            });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'id_document_type', 'id_document_number', 'nationality', 'occupation',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('id_document_type', 30)->nullable()->after('notes');
            $table->string('id_document_number', 100)->nullable()->after('id_document_type');
            $table->string('nationality', 100)->nullable()->after('birth_place');
            $table->string('occupation')->nullable()->after('address');
        });

        // Restore the first listed document into the single-column shape.
        DB::table('clients')
            ->whereNotNull('id_documents')
            ->select('id', 'id_documents')
            ->orderBy('id')
            ->each(function ($client) {
                $first = json_decode((string) $client->id_documents, true)[0] ?? null;
                if ($first) {
                    DB::table('clients')->where('id', $client->id)->update([
                        'id_document_type' => $first['type'] ?? null,
                        'id_document_number' => $first['number'] ?? null,
                    ]);
                }
            });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['id_documents', 'id_number']);
        });
    }
};
