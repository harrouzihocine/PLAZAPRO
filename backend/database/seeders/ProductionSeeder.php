<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Everything a production deploy must reconcile after `migrate --force` —
 * scripts/deploy.sh runs this on every deploy, so a permission split ships
 * together with the code that gates on it and is never left as a manual
 * post-deploy step.
 *
 * Rules for what may live here:
 *  - idempotent one-shots only: updateOrCreate, with role backfills gated on
 *    wasRecentlyCreated so an owner unticking a grant later is respected;
 *  - no model factories (prod vendors are installed --no-dev, faker is absent);
 *  - never data the owner curates in the app (dynamic lists, geo tables,
 *    departments) — re-seeding those would resurrect rows they deleted.
 *
 * The full RbacSeeder stays out on purpose: it creates demo accounts with a
 * default password and force-syncs the baseline roles' grants.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UnlockPermissionSeeder::class,
            TransferPermissionSeeder::class,
            ReservationPermissionSeeder::class,
            OfficeProgramPermissionSeeder::class,
            TasksAssignPermissionSeeder::class,
            ClientEditPermissionSeeder::class,
            WebLeadsPermissionSeeder::class,
        ]);
    }
}
