<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Notifications;

use App\Modules\Collaboration\Support\FcmClient;
use Illuminate\Notifications\Notification;

/**
 * Delivery channel for system-tray push (the Android shell). Fans one
 * notification out to every registered device of the notifiable and prunes
 * tokens FCM reports dead. Runs on the queue with the rest of the channels;
 * without FIREBASE_CREDENTIALS it is never in via() at all.
 */
class FcmChannel
{
    public function __construct(private FcmClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $this->client->configured() || ! method_exists($notification, 'toFcm')) {
            return;
        }

        /** @var array<string, string> $data */
        $data = $notification->toFcm($notifiable);

        foreach ($notifiable->deviceTokens ?? [] as $device) {
            if ($this->client->send($device->token, $data) === 'unregistered') {
                $device->delete();
            }
        }
    }
}
