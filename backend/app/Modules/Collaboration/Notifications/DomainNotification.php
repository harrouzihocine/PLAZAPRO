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

    /**
     * The push-preference matrix: every kind belongs to one category a user
     * can silence on their phone (User::wantsPushFor). A kind absent from
     * every list (account_locked, future additions) always pushes — new
     * notification types default to audible, and security ones stay so.
     */
    public const PUSH_CATEGORIES = [
        'chat' => ['chat_message'],
        'visits' => ['visit_assigned', 'visit_agent_assigned', 'office_visit_scheduled', 'dispatch_request'],
        'payments' => ['payment', 'reserved_lapsed'],
        'reminders' => ['reminder', 'upcoming_digest'],
        'listings' => ['unit_published', 'unit_updated', 'unit_sold', 'unit_status', 'unit_match', 'box_published', 'box_updated'],
        'workflow' => ['project', 'desire_assigned', 'duplicate', 'work_transferred'],
    ];

    public static function pushCategoryFor(string $kind): ?string
    {
        foreach (self::PUSH_CATEGORIES as $category => $kinds) {
            if (in_array($kind, $kinds, true)) {
                return $category;
            }
        }

        return null;
    }

    public function __construct(
        public string $kind,
        public string $title,
        public string $body = '',
        public ?string $link = null,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
    ) {}

    /**
     * @return list<class-string|string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        // System-tray push (Android shell): only when FCM is configured, the
        // user has a registered device, and they haven't muted this kind's
        // category — an optional layer, never a dependency. The bell and the
        // live broadcast above are deliberately not gated.
        if (config('services.fcm.credentials')
            && method_exists($notifiable, 'deviceTokens')
            && $notifiable->deviceTokens()->exists()
            && (! method_exists($notifiable, 'wantsPushFor') || $notifiable->wantsPushFor($this->kind))) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    /**
     * The FCM data payload (all values must be strings). Data-only on purpose:
     * the shell's PlazaMessagingService renders the tray notification itself —
     * channel by kind, tag-based stacking (one entry per conversation), and the
     * deep `link` that MainActivity routes on tap.
     *
     * @return array<string, string>
     */
    public function toFcm(object $notifiable): array
    {
        $tag = $this->kind === 'chat_message'
            ? 'chat-'.($this->subjectId ?? 0)
            : $this->kind.'-'.($this->subjectId ?? 0);

        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'link' => (string) ($this->link ?? ''),
            'tag' => $tag,
            // Chat pushes carry the conversation id so the shell's quick-reply
            // action can POST /conversations/{id}/messages without opening the app.
            'subject_id' => (string) ($this->subjectId ?? ''),
        ];
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
