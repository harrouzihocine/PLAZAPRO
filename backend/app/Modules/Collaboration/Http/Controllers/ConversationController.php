<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Actions\AddParticipants;
use App\Modules\Collaboration\Actions\CreateConversation;
use App\Modules\Collaboration\Actions\DeleteConversationForUser;
use App\Modules\Collaboration\Actions\EnsureProjectConversation;
use App\Modules\Collaboration\Actions\MarkConversationRead;
use App\Modules\Collaboration\Actions\RemoveParticipant;
use App\Modules\Collaboration\Actions\ShareRecord;
use App\Modules\Collaboration\Actions\ToggleConversationMute;
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
            // Threads the user "deleted" stay out of their inbox until a new
            // message resurrects them (SendMessage clears hidden_at).
            ->whereHas('participants', fn ($q) => $q->where('users.id', $user->id)
                ->whereNull('conversation_user.hidden_at'))
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
        // The chat exposes its participant list, so gate it like the rest of the
        // project's collaborator identity: members or detail-trusted users, plus
        // the oversight roles that may read/write any project chat. A name-only
        // looker never learns who is on the client's project.
        //
        // The identity branch also requires the project to be VISIBLE: a client
        // detail grant unlocks the client's contact, but must NOT expose the chat
        // of a project the user cannot otherwise see — this is what silos a
        // duplicate-resolution "separate project" both ways (the finder never sees
        // the original's chat; the client's own agent never sees the fork's).
        $user = $request->user();
        abort_unless(
            ($project->isVisibleTo($user) && $project->collaboratorsVisibleTo($user))
                || $user->can('chat.view_project_chats')
                || $user->can('chat.participate_project_chats'),
            404,
        );

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

    /** Flip the caller's own mute flag (notifications + chime off; access unchanged). */
    public function mute(Request $request, Conversation $conversation, ToggleConversationMute $action): JsonResponse
    {
        return response()->json(['muted' => $action->handle($conversation, $request->user())]);
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
     * "Delete" a conversation, Messenger-style: per-user only — it leaves MY
     * inbox and MY view of the history clears; the other participants keep
     * everything (zero-deletion). Project chats are not deletable: they follow
     * the project, not any one participant.
     */
    public function destroy(Request $request, Conversation $conversation, DeleteConversationForUser $action): JsonResponse
    {
        $me = $request->user();

        abort_if($conversation->type === ConversationType::Project, 422, 'Project chats cannot be deleted — they follow the project.');
        abort_unless($conversation->hasParticipant($me), 403);

        $action->handle($conversation, $me);

        return response()->json(['deleted' => true]);
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
