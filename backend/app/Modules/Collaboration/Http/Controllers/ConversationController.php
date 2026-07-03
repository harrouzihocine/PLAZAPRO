<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Actions\AddParticipants;
use App\Modules\Collaboration\Actions\CreateConversation;
use App\Modules\Collaboration\Actions\EnsureProjectConversation;
use App\Modules\Collaboration\Actions\MarkConversationRead;
use App\Modules\Collaboration\Actions\RemoveParticipant;
use App\Modules\Collaboration\Actions\ShareRecord;
use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Collaboration\Http\Requests\AddParticipantsRequest;
use App\Modules\Collaboration\Http\Requests\CreateConversationRequest;
use App\Modules\Collaboration\Http\Requests\ShareRecordRequest;
use App\Modules\Collaboration\Http\Resources\ConversationResource;
use App\Modules\Collaboration\Http\Resources\MessageResource;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Support\SharedSubject;
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
            ->with(['participants', 'latestMessage.author', 'subject'])
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

        return new ConversationResource($conversation->load(['participants', 'latestMessage.author', 'subject']));
    }

    /**
     * The project's dedicated chat — the thread every contributor shares. Access
     * follows the project's visibility, and the participant list is reconciled on
     * the way in (self-healing after any missed sync).
     */
    public function forProject(Request $request, ClientProject $project, EnsureProjectConversation $ensure): ConversationResource
    {
        abort_unless($project->isVisibleTo($request->user()), 404);

        $conversation = $ensure->handle($project);

        return new ConversationResource($conversation->load(['participants', 'latestMessage.author', 'subject']));
    }

    /**
     * One conversation, for deep links (e.g. an overseer opening a project chat
     * that is not in their participant inbox). Read-scoped like the messages.
     */
    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        abort_unless($conversation->isReadableBy($request->user()), 403);

        return new ConversationResource($conversation->load(['participants', 'latestMessage.author', 'subject']));
    }

    public function read(Request $request, Conversation $conversation, MarkConversationRead $action): JsonResponse
    {
        // Read-scoped: for a non-participant overseer the cursor update is a
        // harmless no-op (updateExistingPivot on a missing row does nothing).
        abort_unless($conversation->isReadableBy($request->user()), 403);

        $action->handle($conversation, $request->user());

        return response()->json(['read' => true]);
    }

    /** Add members to a group (admin only — enforced in AddParticipantsRequest). */
    public function addParticipants(AddParticipantsRequest $request, Conversation $conversation, AddParticipants $action): ConversationResource
    {
        $action->handle($conversation, $request->validated('user_ids'));

        return new ConversationResource($conversation->fresh()->load(['participants', 'latestMessage.author', 'subject']));
    }

    /** Remove a member (an admin removing someone, or a member leaving). Groups only. */
    public function removeParticipant(Request $request, Conversation $conversation, User $user, RemoveParticipant $action): JsonResponse
    {
        $me = $request->user();

        abort_unless($conversation->type === ConversationType::Group, 422, 'Only group participants can be managed.');
        abort_unless($conversation->hasParticipant($me), 403);
        abort_unless($user->id === $me->id || $conversation->isAdmin($me), 403);

        $action->handle($conversation, $user->id);

        return response()->json(['removed' => true]);
    }

    /** Share a record (client/deal/unit) into the conversation as a system message. */
    public function share(ShareRecordRequest $request, Conversation $conversation, ShareRecord $action): MessageResource
    {
        $subject = SharedSubject::resolve($request->validated('subject_type'), $request->integer('subject_id'));
        abort_if($subject === null, 404, 'Shared record not found.');

        $message = $action->handle($conversation, $request->user(), $subject, $request->input('note'));

        return new MessageResource($message->load(['author', 'attachments', 'subject']));
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
