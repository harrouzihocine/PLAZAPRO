<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Start a conversation. Direct threads are deduped — reopening a 1:1 returns the
 * existing thread rather than creating a second. The creator is added as a group
 * admin (or a plain member of a direct thread). Validation (valid users, title
 * for groups) is the FormRequest's job.
 */
class CreateConversation
{
    public function handle(User $creator, array $data): Conversation
    {
        $type = ConversationType::from($data['type']);

        $participantIds = collect($data['participant_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === $creator->id)
            ->unique()
            ->values();

        return DB::transaction(function () use ($creator, $data, $type, $participantIds) {
            if ($type === ConversationType::Direct) {
                abort_unless($participantIds->count() === 1, 422, 'A direct conversation needs exactly one other participant.');

                $existing = Conversation::query()
                    ->where('type', ConversationType::Direct->value)
                    ->whereHas('participants', fn ($q) => $q->where('users.id', $creator->id))
                    ->whereHas('participants', fn ($q) => $q->where('users.id', $participantIds->first()))
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $conversation = Conversation::create([
                'type' => $type->value,
                'title' => $type === ConversationType::Group ? ($data['title'] ?? null) : null,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'created_by' => $creator->id,
            ]);

            $now = now();
            $attach = [
                $creator->id => [
                    'role' => $type === ConversationType::Group ? 'admin' : 'member',
                    'joined_at' => $now,
                ],
            ];
            foreach ($participantIds as $id) {
                $attach[$id] = ['role' => 'member', 'joined_at' => $now];
            }
            $conversation->participants()->attach($attach);

            return $conversation;
        });
    }
}
