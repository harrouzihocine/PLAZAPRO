<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Controllers;

use App\Modules\Collaboration\Actions\RedactMessage;
use App\Modules\Collaboration\Actions\SendMessage;
use App\Modules\Collaboration\Http\Requests\SendMessageRequest;
use App\Modules\Collaboration\Http\Resources\MessageResource;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Messages within a conversation. Every action is participant-scoped; redacting a
 * message is limited to its author or a group admin. Redacted messages are kept
 * in the thread (shown as deleted), never physically removed.
 */
class MessageController extends Controller
{
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        abort_unless($conversation->hasParticipant($request->user()), 403);

        // Latest window, oldest-first for display. `before` (a message id) pages
        // backwards for "load older". Redacted messages are kept (shown deleted).
        $messages = $conversation->messages()
            ->with(['author', 'attachments', 'subject'])
            ->when($request->filled('before'), fn ($q) => $q->where('id', '<', $request->integer('before')))
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();

        return MessageResource::collection($messages);
    }

    public function store(SendMessageRequest $request, Conversation $conversation, SendMessage $action): MessageResource
    {
        $message = $action->handle(
            $conversation,
            $request->user(),
            $request->input('body'),
            $request->file('attachment'),
            $request->integer('duration_ms') ?: null,
        );

        return new MessageResource($message->load(['author', 'attachments']));
    }

    public function destroy(Request $request, Message $message, RedactMessage $action): MessageResource
    {
        $user = $request->user();
        $conversation = $message->conversation;

        abort_unless($conversation->hasParticipant($user), 403);
        abort_unless($message->user_id === $user->id || $conversation->isAdmin($user), 403);

        $reason = (string) $request->input('reason', 'Message deleted');

        return new MessageResource($action->handle($message, $reason)->load(['author', 'attachments']));
    }
}
