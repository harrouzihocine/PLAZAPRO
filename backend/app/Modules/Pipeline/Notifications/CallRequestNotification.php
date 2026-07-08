<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Notifications;

use App\Modules\Collaboration\Notifications\FcmChannel;
use App\Modules\Pipeline\Models\CallRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "Call this client" — the web app handing a number to the user's own phone.
 * FCM-only on purpose: no bell entry, no broadcast — it is a self-command the
 * user just issued on the PC, not news. Ignores push preferences for the same
 * reason (muting "calls you asked for" makes no sense).
 *
 * New shells recognise kind=call_request and open the dialer in one tap
 * (CallReceiver); older shells fall back to a general tray entry whose tap
 * still deep-links to the ?logcall page — degraded but never broken.
 */
class CallRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CallRequest $callRequest,
        public string $clientName,
    ) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        if (! config('services.fcm.credentials') || ! $notifiable->deviceTokens()->exists()) {
            return [];
        }

        return [FcmChannel::class];
    }

    /**
     * @return array<string, string>
     */
    public function toFcm(object $notifiable): array
    {
        return [
            'kind' => 'call_request',
            'title' => 'Call '.$this->clientName,
            'body' => $this->callRequest->phone,
            'link' => $this->callRequest->link(),
            // One tray entry per client: a re-click replaces, never stacks.
            'tag' => 'call_request-'.$this->callRequest->client_id,
            'phone' => $this->callRequest->phone,
            'call_request_id' => (string) $this->callRequest->id,
        ];
    }
}
