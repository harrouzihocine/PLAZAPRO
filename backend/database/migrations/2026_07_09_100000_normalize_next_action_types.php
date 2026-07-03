<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The workflow spec allows exactly three next steps: call, office visit, in-site
// visit. Fold the retired follow_up/send_docs types into 'call' (a follow-up IS
// a call; docs are sent during one) so historical rows stay valid for the enum.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('next_actions')
            ->whereIn('type', ['follow_up', 'send_docs'])
            ->update(['type' => 'call']);
    }

    public function down(): void
    {
        // Irreversible data normalization; the original distinction is not kept.
    }
};
