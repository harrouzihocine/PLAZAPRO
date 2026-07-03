<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Support;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a domain subject (client / deal / unit) into the deep-link + morph
 * reference a notification carries, so every listener builds links the same way
 * and the frontend can route on subject_type/subject_id.
 */
final class NotificationLink
{
    /**
     * @return array{0: ?string, 1: ?string, 2: ?int} [link, subjectType, subjectId]
     */
    public static function forSubject(?Model $subject): array
    {
        return match (true) {
            $subject instanceof Client => ['/clients/'.$subject->id, 'client', $subject->id],
            // A project links to its own workspace page, not the client file.
            $subject instanceof ClientProject => ['/clients/'.$subject->client_id.'/projects/'.$subject->id, 'client_project', $subject->id],
            $subject instanceof Unit => ['/inventory/units/'.$subject->id, 'unit', $subject->id],
            default => [null, null, null],
        };
    }
}
