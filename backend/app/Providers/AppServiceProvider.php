<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Models live in feature modules (App\Modules\...), but their factories
        // all live flat in Database\Factories. Map any model to its factory by
        // class basename so HasFactory resolves across the modular structure.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Stable morph aliases so polymorphic types (media.mediable_type, ...) are
        // stored as short keys, decoupled from PHP class paths. Non-enforcing: the
        // app also stores full class names elsewhere (activity_log subject), so we
        // don't force every polymorphic model into the map.
        Relation::morphMap([
            'location' => Location::class,
            'unit' => Unit::class,
            'client' => Client::class,
            'client_project' => ClientProject::class,
            'call' => Call::class,
            'visit' => Visit::class,
        ]);
    }
}
