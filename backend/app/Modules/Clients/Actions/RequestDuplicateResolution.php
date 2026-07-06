<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientDuplicateRequest;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Settings\Models\User;

/**
 * Open a supervised duplicate-resolution request and notify every resolver
 * (clients.duplicates.resolve holders). Raised when a user tries to add a client
 * whose phone already belongs to a client they cannot see — the supervisor then
 * denies it or shares a project, so no client is silently taken.
 */
class RequestDuplicateResolution
{
    public function handle(Client $existing, array $attempted, User $finder): ClientDuplicateRequest
    {
        // Fold identity from the attempted payload for the resolver's context;
        // never store the phone twice (the existing client is the source of truth).
        $request = ClientDuplicateRequest::create([
            'existing_client_id' => $existing->id,
            'requested_by' => $finder->id,
            'attempted_data' => array_intersect_key($attempted, array_flip([
                'first_name', 'last_name', 'email', 'notes', 'referrer_name',
            ])),
            'status' => 'pending',
        ]);

        $resolvers = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $finder->id)
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'clients.duplicates.resolve'))
            ->get();

        foreach ($resolvers as $resolver) {
            $resolver->notify(new DomainNotification(
                kind: 'duplicate',
                title: 'Duplicate client attempt',
                body: $finder->name.' tried to add a client that already exists.',
                link: '/oversight/duplicates',
            ));
        }

        return $request;
    }
}
