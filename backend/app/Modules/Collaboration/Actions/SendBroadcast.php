<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Models\Broadcast;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Send a human-authored broadcast: record it, snapshot who it went to, then fan
 * it out through the normal per-recipient notification pipeline (bell feed +
 * live badge + Android push). Each recipient reads the message in their own
 * language (DomainNotification::bodyI18n), so a single fan-out serves a mixed-
 * language audience.
 */
class SendBroadcast
{
    /**
     * @param  array{audience_type: string, role_id?: int|null, user_ids?: list<int>, body: array<string, string>}  $data
     */
    public function handle(User $sender, array $data): Broadcast
    {
        $audienceType = $data['audience_type'];
        $roleId = $audienceType === 'role' ? ($data['role_id'] ?? null) : null;
        $userIds = $audienceType === 'users' ? ($data['user_ids'] ?? []) : [];

        // Trim + drop empty languages; the request guarantees at least one stays.
        $translations = collect($data['body'])
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->all();

        $recipients = $this->resolveRecipients($audienceType, $roleId, $userIds);

        return DB::transaction(function () use ($sender, $audienceType, $roleId, $translations, $recipients) {
            $broadcast = Broadcast::create([
                'sender_id' => $sender->id,
                'audience_type' => $audienceType,
                'role_id' => $roleId,
                'body_translations' => $translations,
                'recipient_count' => $recipients->count(),
            ]);

            $broadcast->recipients()->attach($recipients->pluck('id')->all());

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new DomainNotification(
                    kind: 'broadcast',
                    key: 'broadcast',            // title → notifications.broadcast.title (per recipient locale)
                    bodyI18n: $translations,     // free-text body, resolved per recipient locale
                    subjectType: 'broadcast',
                    subjectId: $broadcast->id,
                ));
            }

            return $broadcast;
        });
    }

    /**
     * The set of active users a broadcast targets. `users` is intersected with
     * the active set so a stale/hidden id can never widen the audience.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    private function resolveRecipients(string $audienceType, ?int $roleId, array $userIds): Collection
    {
        $query = User::query()->active()->where('is_active', true);

        return match ($audienceType) {
            'role' => $query->where('role_id', $roleId)->get(),
            'users' => $query->whereIn('id', $userIds)->get(),
            default => $query->get(), // 'all'
        };
    }
}
