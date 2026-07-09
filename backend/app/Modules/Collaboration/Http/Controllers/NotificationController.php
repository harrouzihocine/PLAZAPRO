<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Controllers;

use App\Modules\Collaboration\Http\Resources\NotificationResource;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The in-app notification feed for the authenticated user. Every user reads only
 * their own notifications (scoped through the Notifiable relation), so no
 * per-row authorisation is needed beyond the notifications.view route gate.
 */
class NotificationController extends Controller
{
    /**
     * The bell feed. Routine chat traffic doesn't belong here anymore — it
     * lives in the dock (web) and the tray + Chat-tab badge (app); only
     * chat_first_message rows ring the bell. Rows written before that split
     * still carry kind=chat_message, so they're excluded from the list AND
     * from every unread count this controller returns.
     */
    private function bell(User $user): MorphMany
    {
        // NULL-tolerant: `!=` alone would silently drop any row whose data has
        // no kind key (none today, but a future notification class could).
        return $user->notifications()->where(fn ($q) => $q
            ->where('data->kind', '!=', 'chat_message')
            ->orWhereNull('data->kind'));
    }

    private function unreadCount(User $user): int
    {
        return $this->bell($user)->whereNull('read_at')->count();
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $notifications = $this->bell($user)
            ->when(
                $request->boolean('unread'),
                fn ($q) => $q->whereNull('read_at'),
            )
            ->paginate(10);

        // Top-level (not under `meta`) so the paginator's own meta is preserved.
        return NotificationResource::collection($notifications)
            ->additional(['unread_count' => $this->unreadCount($user)]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['unread_count' => $this->unreadCount($request->user())]);
    }

    public function unread(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsUnread();

        return response()->json(['unread_count' => $this->unreadCount($request->user())]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }
}
