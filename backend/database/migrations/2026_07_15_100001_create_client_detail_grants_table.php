<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-user unlock of ONE client's identity/contact details, independent of the
 * global clients.view_details permission. Granted by a supervisor when resolving
 * a duplicate ("share the client's details too"): the finder can then see the
 * shared client's phone/profile even though they don't hold view_details globally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_detail_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_detail_grants');
    }
};
