<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * web_leads = the public showcase's inbox. Every visitor form submission lands
 * here first (never straight into `clients` — spam must not pollute the CRM);
 * staff with web.leads convert the real ones into clients from /web-leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 32);
            $table->text('message')->nullable();
            // interest (a unit/project caught their eye) | visit_request
            // (asked to come to the office) | callback (call me back).
            $table->string('type', 20)->default('interest');
            $table->foreignId('location_id')->nullable()
                ->constrained('locations')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()
                ->constrained('units')->nullOnDelete();
            $table->date('preferred_date')->nullable();
            $table->string('preferred_time', 8)->nullable();
            // The visitor's site language — agents open WhatsApp in it.
            $table->string('locale', 5)->nullable();
            // Audit trail without storing raw PII: the IP is hashed.
            $table->string('source_url', 500)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('lead_status', 16)->default('new');
            $table->foreignId('converted_client_id')->nullable()
                ->constrained('clients')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('web_leads')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('lead_status');
            $table->index('location_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_leads');
    }
};
