<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Models\Call;

/**
 * Correct a logged call. Nothing is edited in place: the original is cancelled and
 * a new version is inserted (HasVersions::supersedeWith), linked by supersedes_id
 * and audited — so every version of a log is kept with its reason and timeline.
 */
class CorrectCall
{
    /**
     * @param  array<string, mixed>  $data  changed fillable fields (direction, outcome_id, notes, topics, called_at)
     */
    public function handle(Call $call, array $data, string $reason): Call
    {
        return $call->supersedeWith($data, $reason);
    }
}
