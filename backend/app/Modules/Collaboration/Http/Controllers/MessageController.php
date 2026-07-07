<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Controllers;

use App\Modules\Collaboration\Actions\EditMessage;
use App\Modules\Collaboration\Actions\ForwardMessage;
use App\Modules\Collaboration\Actions\RedactMessage;
use App\Modules\Collaboration\Actions\SendMessage;
use App\Modules\Collaboration\Actions\ToggleMessageReaction;
use App\Modules\Collaboration\Events\MessageUpdated;
use App\Modules\Collaboration\Http\Requests\SendMessageRequest;
use App\Modules\Collaboration\Http\Resources\MessageResource;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Collaboration\Models\MessageReaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Messages within a conversation. Every action is participant-scoped; redacting a
 * message is limited to its author or a group admin. Redacted messages are kept
 * in the thread (shown as deleted), never physically removed.
 */
class MessageController extends Controller
{
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        // Participants — plus project-chat oversight (chat.view_project_chats).
        abort_unless($conversation->isReadableBy($request->user()), 403);

        // If the caller "deleted" this thread, history up to that instant stays
        // hidden from them — even after a new message resurrected the thread.
        $clearedAt = $conversation->participants()
            ->where('users.id', $request->user()->id)
            ->first()?->pivot->cleared_at;

        // Latest window, oldest-first for display. `before` (a message id) pages
        // backwards for "load older". Redacted messages are kept (shown deleted).
        $messages = $conversation->messages()
            ->with(['author', 'attachments', 'subject', 'replyTo.author', 'reactions.user'])
            ->when($clearedAt !== null, fn ($q) => $q->where('created_at', '>', $clearedAt))
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
            $request->integer('reply_to_id') ?: null,
        );

        return new MessageResource($message->load(['author', 'attachments', 'replyTo.author']));
    }

    /** Toggle the caller's emoji reaction (write-scoped — observers can't react). */
    public function react(Request $request, Message $message, ToggleMessageReaction $action): MessageResource
    {
        // Same gate as posting: participants minus downgraded observers, plus
        // participate-overseers on project chats. View-only readers get 403.
        abort_unless($message->conversation->isWritableBy($request->user()), 403);

        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in(MessageReaction::EMOJIS)],
        ]);

        $action->handle($message, $request->user(), $validated['emoji']);

        return new MessageResource($message->load(['author', 'attachments', 'replyTo.author', 'reactions.user']));
    }

    /** Edit a text message in place — the author only, Messenger-style. */
    public function update(Request $request, Message $message, EditMessage $action): MessageResource
    {
        abort_unless($message->conversation->isWritableBy($request->user()), 403);
        abort_unless($message->user_id === $request->user()->id, 403);

        $validated = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        return new MessageResource($action->handle($message, $validated['body'])->load(['author', 'attachments', 'replyTo.author', 'reactions.user']));
    }

    /** Forward a message into other conversations the caller can post in. */
    public function forward(Request $request, Message $message, ForwardMessage $action): AnonymousResourceCollection
    {
        abort_unless($message->conversation->isReadableBy($request->user()), 403);

        $validated = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1', 'max:10'],
            'conversation_ids.*' => ['integer'],
        ]);

        $messages = $action->handle($message, $request->user(), $validated['conversation_ids']);

        return MessageResource::collection($messages->map->load(['author', 'attachments']));
    }

    public function destroy(Request $request, Message $message, RedactMessage $action): MessageResource
    {
        $user = $request->user();
        $conversation = $message->conversation;

        // Write-scoped (a participate-overseer may redact their OWN message);
        // beyond your own, only a conversation admin redacts.
        abort_unless($conversation->isWritableBy($user), 403);
        abort_unless($message->user_id === $user->id || $conversation->isAdmin($user), 403);

        $reason = (string) $request->input('reason', 'Message deleted');
        $redacted = $action->handle($message, $reason);

        // Patch open threads live — the bubble flips to "Message deleted"
        // everywhere without a reload.
        MessageUpdated::dispatch($redacted);

        return new MessageResource($redacted->load(['author', 'attachments']));
    }
}
