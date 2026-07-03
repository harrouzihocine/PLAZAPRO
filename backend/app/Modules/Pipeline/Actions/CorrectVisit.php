<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Models\Visit;

/**
 * Correct a visit. Like CorrectCall, this cancels the original and inserts a new
 * version (supersedeWith) so the edit is captured with its reason and both versions
 * stay in history. Reassigning the agent stays with AssignVisit.
 */
class CorrectVisit
{
    /**
     * @param  array<string, mixed>  $data  changed fillable fields (type, unit_id, scheduled_at, notes, checklist, outcome_id)
     */
    public function handle(Visit $visit, array $data, string $reason): Visit
    {
        return $visit->supersedeWith($data, $reason);
    }
}
