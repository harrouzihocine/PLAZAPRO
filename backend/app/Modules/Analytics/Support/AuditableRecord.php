<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\Visit;

/**
 * The records whose audit trail can be read in place (on the record's own
 * page) and, for each, the model plus the RBAC permission required to view
 * that record at all. One source of truth so the per-record activity endpoint
 * can never leak a trail to someone who may not see the record itself.
 * Mirrors the Collaboration SharedSubject pattern.
 */
final class AuditableRecord
{
    /**
     * alias => [model class, view permission]
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    private const MAP = [
        'location' => [Location::class, 'units.view'],
        'unit' => [Unit::class, 'units.view'],
        'box' => [Box::class, 'units.view'],
        'client' => [Client::class, 'clients.view'],
        'client_project' => [ClientProject::class, 'clients.view'],
        'deal' => [Deal::class, 'clients.view'],
        'call' => [Call::class, 'clients.view'],
        'visit' => [Visit::class, 'clients.view'],
        'payment_schedule' => [PaymentSchedule::class, 'versements.view'],
        'versement' => [Versement::class, 'versements.view'],
    ];

    /** @return class-string|null */
    public static function modelFor(string $alias): ?string
    {
        return self::MAP[$alias][0] ?? null;
    }

    public static function permissionFor(string $alias): ?string
    {
        return self::MAP[$alias][1] ?? null;
    }
}
