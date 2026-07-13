<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public "tell us what you're looking for" form: a `desire` lead type
 * whose structured criteria (wilayas/communes, project types, rooms, budget)
 * land here as validated JSON. Ids only — labels are resolved at display time
 * and the criteria become a real client Desire on convert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_leads', function (Blueprint $table) {
            $table->json('criteria')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('web_leads', function (Blueprint $table) {
            $table->dropColumn('criteria');
        });
    }
};
