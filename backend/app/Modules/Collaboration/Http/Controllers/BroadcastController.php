<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Controllers;

use App\Modules\Collaboration\Actions\SendBroadcast;
use App\Modules\Collaboration\Http\Requests\StoreBroadcastRequest;
use App\Modules\Collaboration\Http\Resources\BroadcastResource;
use App\Modules\Collaboration\Models\Broadcast;
use App\Modules\Settings\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Custom broadcast notifications: a permitted user composes a trilingual message
 * and sends it to selected users / a role / everyone (store), then reviews the
 * company-wide history (index) with delivery + read tracking (show). Read state
 * is derived live from each recipient's notification row (data->subject_id), so
 * marking the bell notification read is what counts a recipient as "read".
 */
class BroadcastController extends Controller
{
    public function store(StoreBroadcastRequest $request, SendBroadcast $action): JsonResponse
    {
        $broadcast = $action->handle($request->user(), $request->validated());
        $broadcast->load('sender', 'role');
        $broadcast->read_count = 0;

        return (new BroadcastResource($broadcast))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Company-wide history — every broadcast anyone sent, newest first. read_count
     * is filled from a single grouped query over the recipients' notifications.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $broadcasts = Broadcast::query()
            ->with('sender', 'role')
            ->latest()
            ->paginate(15);

        $reads = $this->readCounts($broadcasts->getCollection()->pluck('id')->all());
        $broadcasts->getCollection()->each(fn (Broadcast $b) => $b->read_count = $reads[$b->id] ?? 0);

        return BroadcastResource::collection($broadcasts);
    }

    /**
     * One broadcast's full detail. Broadcasters (notifications.broadcast) get the
     * audience, every language and the who-read list; a plain recipient gets the
     * message (in their language), the sender and the time only.
     */
    public function show(Request $request, Broadcast $broadcast): JsonResponse
    {
        $user = $request->user();
        $canManage = (bool) $user->can('notifications.broadcast');
        $isRecipient = $broadcast->recipients()->whereKey($user->id)->exists();

        abort_unless($canManage || $isRecipient, 403);

        $broadcast->loadMissing('sender', 'role');

        $payload = [
            'id' => $broadcast->id,
            'sender_name' => $broadcast->sender?->name,
            'audience_type' => $broadcast->audience_type,
            'role_name' => $broadcast->role?->name,
            'recipient_count' => $broadcast->recipient_count,
            'body' => $broadcast->bodyFor($user->preferredLocale()),
            'created_at' => $broadcast->created_at,
        ];

        // Broadcaster-only: the full message in every language + the who-read list.
        if ($canManage) {
            $readAt = $this->recipientReadMap($broadcast);

            $payload['body_translations'] = $broadcast->body_translations;
            $payload['delivered'] = $readAt->count();
            $payload['read_count'] = $readAt->filter()->count();
            $payload['recipients'] = $broadcast->recipients()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'read_at' => $readAt->get($u->id),
                ])
                ->all();
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * read_count per broadcast id, from the recipients' notification rows.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function readCounts(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return DB::table('notifications')
            ->where('data->subject_type', 'broadcast')
            ->whereIn('data->subject_id', $ids)
            ->whereNotNull('read_at')
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.subject_id')) AS UNSIGNED) as bid, COUNT(*) as c")
            ->groupBy('bid')
            ->pluck('c', 'bid')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * user_id => read_at (nullable) for one broadcast's delivered notifications.
     *
     * @return \Illuminate\Support\Collection<int, ?string>
     */
    private function recipientReadMap(Broadcast $broadcast): \Illuminate\Support\Collection
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('data->subject_type', 'broadcast')
            ->where('data->subject_id', $broadcast->id)
            ->pluck('read_at', 'notifiable_id');
    }
}
