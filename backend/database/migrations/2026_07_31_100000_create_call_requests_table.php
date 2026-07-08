<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * call_requests = "send this client to my phone" clicks (the web phone icon).
 * One row per handoff: created when the PC pushes the number to the Android
 * shell, advanced when the shell reports the dialer was opened (`dialed`), and
 * closed by the log-call prompt (`logged` when the call log was created,
 * `dismissed` when the agent said "not now"). The open statuses power the
 * cross-device reminder; the closed ones make "dialed but never logged"
 * auditable later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            // The project whose timeline the reminder deep-links to — resolved
            // at send time (the client's single active project, else null =
            // the client file).
            $table->foreignId('client_project_id')->nullable()->constrained('client_projects')->nullOnDelete();
            $table->string('phone', 32);
            $table->string('status', 16)->default('sent');
            $table->timestamp('dialed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_requests');
    }
};
