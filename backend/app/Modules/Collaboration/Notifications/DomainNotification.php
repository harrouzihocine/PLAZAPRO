<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * The single, generic in-app notification. Every domain trigger (a due reminder,
 * an assigned visit, a new chat message, a recorded payment, a unit matching a
 * client's desire) builds one of these with a title/body/link and an optional
 * subject reference. Delivered on the queue via the database channel (the bell
 * feed) and the broadcast channel (live badge over Reverb).
 *
 * `kind` is a stable machine key the frontend can switch an icon/route on.
 */
class DomainNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $title,
        public string $body = '',
        public ?string $link = null,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
