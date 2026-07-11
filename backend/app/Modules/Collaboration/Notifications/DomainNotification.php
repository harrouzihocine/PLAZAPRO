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
        'visits' => [
            'visit_assigned', 'visit_agent_assigned', 'office_visit_scheduled', 'dispatch_request',
            'office_visit_approval', 'office_visit_approved', 'office_visit_denied', 'office_visit_rescheduled',
            'visit_completed', 'visit_declined', 'visit_unaccepted', 'visit_late',
            'visit_offroute', 'agent_idle', 'visit_log_prompt',
            'duty_reminder', 'duty_nudge', 'duty_gps_lost',
        ],
        'payments' => ['payment', 'reserved_lapsed', 'reservation_next', 'reservation_cancelled'],
        'reminders' => ['reminder', 'upcoming_digest'],
        'listings' => ['unit_published', 'unit_updated', 'unit_sold', 'unit_status', 'unit_match', 'box_published', 'box_updated', 'units_imported'],
        'workflow' => ['project', 'desire_assigned', 'duplicate', 'work_transferred', 'task_assigned', 'task_completed'],
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
        public string $title = '',
        public string $body = '',
        public ?string $link = null,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        // Localizable variant: when `key` is set, title/body resolve per
        // RECIPIENT at send time (Laravel renders each channel inside the
        // notifiable's preferredLocale) from lang/xx/notifications.php:
        // "notifications.{key}.title" / ".body". Params starting with '@' are
        // themselves translation keys, resolved in the recipient's language —
        // for words that vary (visit types, digest groups) inside a fan-out
        // where one instance serves users with different languages.
        public ?string $key = null,
        public array $params = [],
        // Per-send channel routing (subset of 'database' | 'broadcast' | 'fcm';
        // null = all three). Chat traffic uses it: the routine chat_message is
        // broadcast+fcm only (dock / tray / tab badge — never a bell row), while
        // its first-contact companion is database+broadcast (a bell row with no
        // second tray ping). 'fcm' stays subject to the runtime gates in via().
        public ?array $channels = null,
    ) {}

    private function resolvedParams(): array
    {
        return array_map(
            fn ($v) => is_string($v) && str_starts_with($v, '@') ? __(substr($v, 1)) : $v,
            $this->params,
        );
    }

    private function resolvedTitle(): string
    {
        return $this->key ? __("notifications.{$this->key}.title", $this->resolvedParams()) : $this->title;
    }

    private function resolvedBody(): string
    {
        if ($this->key === null) {
            return $this->body;
        }

        $key = "notifications.{$this->key}.body";
        $body = __($key, $this->resolvedParams());

        return $body === $key ? $this->body : $body;
    }

    /**
     * @return list<class-string|string>
     */
    public function via(object $notifiable): array
    {
        $wanted = $this->channels ?? ['database', 'broadcast', 'fcm'];
        $channels = array_values(array_intersect(['database', 'broadcast'], $wanted));

        // System-tray push (Android shell): only when FCM is configured, the
        // user has a registered device, and they haven't muted this kind's
        // category — an optional layer, never a dependency. The bell and the
        // live broadcast above are deliberately not gated.
        if (in_array('fcm', $wanted, true)
            && config('services.fcm.credentials')
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
            'title' => $this->resolvedTitle(),
            'body' => $this->resolvedBody(),
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
            'title' => $this->resolvedTitle(),
            'body' => $this->resolvedBody(),
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
