<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Collaboration\Events\MessageSent;
use App\Modules\Collaboration\Notifications\DomainNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Notify a conversation's other participants that a message arrived — the bell
 * for people who don't have the thread open. Skips the author and anyone who
 * muted the conversation. Runs on the queue.
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
            ? ($conversation->title ?? 'Group chat')
            : ($message->author?->name ?? 'New message');

        $preview = $message->body !== null && $message->body !== ''
            ? Str::limit($message->body, 120)
            : 'Sent an attachment';

        Notification::send($recipients, new DomainNotification(
            kind: 'chat_message',
            title: $title,
            body: $preview,
            link: '/chat/'.$conversation->id,
            subjectType: 'conversation',
            subjectId: $conversation->id,
        ));
    }
}
