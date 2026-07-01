<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Controllers;

use App\Modules\Collaboration\Actions\CreateConversation;
use App\Modules\Collaboration\Actions\MarkConversationRead;
use App\Modules\Collaboration\Http\Requests\CreateConversationRequest;
use App\Modules\Collaboration\Http\Resources\ConversationResource;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Conversations inbox. Visibility is participant-scoped everywhere: index only
 * returns the user's threads, and single-conversation actions guard on
 * participation (not via Gate, so the super-admin shortcut can't widen it).
 */
class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->visibleTo($user)
            ->with(['participants', 'latestMessage.author'])
            ->withCount(['messages as unread_count' => function ($q) use ($user) {
                $q->where('messages.user_id', '!=', $user->id)
                    ->where('messages.status', 'active')
                    ->whereRaw(
                        'messages.created_at > COALESCE((select cu.last_read_at from conversation_user cu where cu.conversation_id = messages.conversation_id and cu.user_id = ?), ?)',
                        [$user->id, '1970-01-01 00:00:00'],
                    );
            }])
            ->orderByRaw('last_message_at is null, last_message_at desc')
            ->get();

        return ConversationResource::collection($conversations);
    }

    public function store(CreateConversationRequest $request, CreateConversation $action): ConversationResource
    {
        $conversation = $action->handle($request->user(), $request->validated());

        return new ConversationResource($conversation->load(['participants', 'latestMessage.author']));
    }

    public function read(Request $request, Conversation $conversation, MarkConversationRead $action): JsonResponse
    {
        abort_unless($conversation->hasParticipant($request->user()), 403);

        $action->handle($conversation, $request->user());

        return response()->json(['read' => true]);
    }

    /**
     * The people this user can start a conversation with: active users other than
     * themselves. Gated by chat.use (same as the rest of chat); returns a minimal
     * picker payload, not the full user directory.
     */
    public function contacts(Request $request): JsonResponse
    {
        $contacts = User::query()
            ->active()
            ->where('is_active', true)
            ->whereKeyNot($request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]);

        return response()->json(['data' => $contacts]);
    }
}
