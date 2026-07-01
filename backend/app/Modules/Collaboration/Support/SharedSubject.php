<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Support;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Model;

/**
 * The records that can be shared into a chat (client / deal / unit) and, for
 * each, the model, the RBAC permission needed to view it, and how to render its
 * card. One source of truth so both the share endpoint (author must be able to
 * view what they share) and the message resource (a card is only revealed to
 * participants who may view it) agree.
 */
final class SharedSubject
{
    /**
     * alias => [model class, view permission]
     *
     * @var array<string, array{0: class-string<Model>, 1: string}>
     */
    private const MAP = [
        'client' => [Client::class, 'clients.view'],
        'client_project' => [ClientProject::class, 'clients.view'],
        'unit' => [Unit::class, 'units.view'],
    ];

    /** @return list<string> */
    public static function aliases(): array
    {
        return array_keys(self::MAP);
    }

    public static function permissionFor(string $alias): ?string
    {
        return self::MAP[$alias][1] ?? null;
    }

    /** Resolve a shareable record by alias + id, or null if unknown/missing. */
    public static function resolve(string $alias, int $id): ?Model
    {
        $class = self::MAP[$alias][0] ?? null;

        return $class === null ? null : $class::query()->find($id);
    }

    /**
     * The card payload for a shared subject: type, id, label and deep-link. The
     * permission gate is applied by the caller, not here.
     *
     * @return array{type: string, id: int, label: string, link: ?string, permission: string}|null
     */
    public static function card(Model $subject): ?array
    {
        return match (true) {
            $subject instanceof Client => [
                'type' => 'client',
                'id' => $subject->id,
                'label' => trim($subject->first_name.' '.$subject->last_name),
                'link' => '/clients/'.$subject->id,
                'permission' => 'clients.view',
            ],
            $subject instanceof ClientProject => [
                'type' => 'client_project',
                'id' => $subject->id,
                'label' => 'Deal #'.$subject->id,
                'link' => '/clients/'.$subject->client_id,
                'permission' => 'clients.view',
            ],
            $subject instanceof Unit => [
                'type' => 'unit',
                'id' => $subject->id,
                'label' => 'Unit '.$subject->reference,
                'link' => '/inventory/units',
                'permission' => 'units.view',
            ],
            default => null,
        };
    }
}
