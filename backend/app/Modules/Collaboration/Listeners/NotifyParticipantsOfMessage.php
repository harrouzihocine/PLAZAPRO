<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Collaboration\Events\MessageSent;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Collaboration\Notifications\DomainNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Notify a conversation's other participants that a message arrived. Skips the
 * author and anyone who muted the conversation. Runs on the queue.
 *
 * Chat traffic deliberately stays OUT of the bell feed: the routine
 * chat_message goes broadcast+fcm only — the web dock (heads + pop sound), the
 * Android tray and the Chat-tab badge own it. The one exception is first
 * contact: the first message a sender has EVER sent to a given recipient also
 * lands a chat_first_message bell row, so a brand-new correspondent is never
 * missed once the tray entry is swiped away.
 */
class NotifyParticipantsOfMessage implements ShouldQueue
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message->loadMissing(['conversation.participants', 'author']);
        $conversation = $message->conversation;

        $recipients = $conversation->participants
            ->where('id', '!=', $message->user_id)
            ->filter(fn ($u) => ! $u->pivot->muted);

        if ($recipients->isEmpty()) {
            return;
        }

        $title = $conversation->type === ConversationType::Group
            ? ($conversation->title ?? '@notifications.chat_group')
            : ($message->author?->name ?? '@notifications.chat_new_message');

        $preview = $message->body !== null && $message->body !== ''
            ? Str::limit($message->body, 120)
            : '@notifications.chat_attachment';

        Notification::send($recipients, new DomainNotification(
            kind: 'chat_message',
            key: 'chat_message',
            params: ['title' => $title, 'preview' => $preview],
            link: '/chat/'.$conversation->id,
            subjectType: 'conversation',
            subjectId: $conversation->id,
            channels: ['broadcast', 'fcm'],
        ));

        // Skip anyone whose read cursor already passed this message: this job
        // runs on the queue, so a recipient with the thread open may have read
        // it before the row would be written — MarkConversationRead's sweep
        // can't retire a bell entry that doesn't exist yet, and the row would
        // be born permanently unread.
        $firstTimers = $this->firstTimeRecipients($message, $recipients)
            ->reject(fn ($u) => $u->pivot->last_read_at !== null
                && Carbon::parse($u->pivot->last_read_at)->gte($message->created_at))
            ->values();
        if ($firstTimers->isNotEmpty()) {
            Notification::send($firstTimers, new DomainNotification(
                kind: 'chat_first_message',
                key: 'chat_first_message',
                params: [
                    'name' => $message->author?->name ?? '@notifications.chat_new_message',
                    'preview' => $preview,
                ],
                link: '/chat/'.$conversation->id,
                subjectType: 'conversation',
                subjectId: $conversation->id,
                // No fcm: the chat_message push above already pinged the phone
                // for this same message.
                channels: ['database', 'broadcast'],
            ));
        }
    }

    /**
     * Recipients this sender has never messaged before: no earlier message of
     * theirs exists in any conversation the recipient participates in.
     */
    private function firstTimeRecipients(Message $message, Collection $recipients): Collection
    {
        $priorConversationIds = Message::query()
            ->where('user_id', $message->user_id)
            ->where('id', '<', $message->id)
            ->distinct()
            ->pluck('conversation_id');

        if ($priorConversationIds->isEmpty()) {
            return $recipients;
        }

        $alreadyContacted = DB::table('conversation_user')
            ->whereIn('conversation_id', $priorConversationIds)
            ->whereIn('user_id', $recipients->pluck('id'))
            ->distinct()
            ->pluck('user_id');

        return $recipients->whereNotIn('id', $alreadyContacted)->values();
    }
}
