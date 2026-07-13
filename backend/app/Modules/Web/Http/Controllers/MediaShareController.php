<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Modules\Clients\Models\Client;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Web\Http\Requests\StoreMediaShareRequest;
use App\Modules\Web\Models\MediaShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the tokened share an agent sends a client over WhatsApp ("here are
 * the photos of your apartment"). No backend send — like the office invite,
 * the frontend composes the message and opens wa.me; this only mints the link.
 * The client must be in the agent's own reach (Client::visibleTo) and every
 * media id must be an active, shareable (photos/videos/plans) asset of the
 * named gallery — one bad id fails the whole request rather than silently
 * sending a thinner bundle than the agent picked.
 */
class MediaShareController extends Controller
{
    /**
     * The public share page, under the showcase's base path (the frontend's
     * PUBLIC_BASE in features/showcase/routes.js — keep the two in sync).
     * Built from APP_URL so links sent from the APK on the office LAN
     * (office.plaza-pro.com / bare-IP failover origins) still point clients
     * at the canonical public host.
     */
    private const SHARE_PATH = '/plaza/share/';

    public function store(StoreMediaShareRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // Out-of-reach client ids read as absent, like everywhere else.
        $client = Client::query()
            ->active()
            ->visibleTo($user)
            ->findOrFail($validated['client_id']);

        $mediable = $validated['mediable_type'] === 'locations'
            ? Location::query()->findOrFail($validated['mediable_id'])
            : Unit::query()->with('location')->findOrFail($validated['mediable_id']);

        $media = $mediable->media()
            ->active()
            ->whereIn('id', $validated['media_ids'])
            ->whereIn('collection', array_map(
                fn ($c) => $c->value,
                PublicProjectController::PUBLIC_COLLECTIONS,
            ))
            ->orderBy('collection')
            ->orderBy('sort_order')
            ->get();

        if ($media->count() !== count($validated['media_ids'])) {
            throw ValidationException::withMessages([
                'media_ids' => __('app.media_share_bad_items'),
            ]);
        }

        $share = DB::transaction(function () use ($user, $client, $mediable, $media) {
            $share = MediaShare::create([
                'token' => MediaShare::generateToken(),
                'user_id' => $user->id,
                'client_id' => $client->id,
                'title' => $this->title($mediable),
                'expires_at' => now()->addDays(MediaShare::TTL_DAYS),
            ]);

            // Gallery order (collection, then manual sort), not click order.
            $share->media()->attach(
                $media->values()->mapWithKeys(fn ($m, $i) => [$m->id => ['sort_order' => $i]])->all(),
            );

            return $share;
        });

        return response()->json(['data' => [
            'id' => $share->id,
            'token' => $share->token,
            'url' => rtrim((string) config('app.url'), '/').self::SHARE_PATH.$share->token,
            'title' => $share->title,
            'count' => $media->count(),
            'expires_at' => $share->expires_at,
        ]], 201);
    }

    /** The share page heading: the project name, plus the unit reference. */
    private function title(Location|Unit $mediable): string
    {
        $title = $mediable instanceof Unit
            ? trim(($mediable->location?->name ?? '').' — '.$mediable->reference, ' —')
            : $mediable->name;

        return mb_substr($title, 0, 200);
    }
}
