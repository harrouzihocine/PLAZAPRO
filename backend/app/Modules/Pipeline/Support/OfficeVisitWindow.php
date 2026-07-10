<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Enums\NextActionApproval;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Carbon;

/**
 * The office-visit scheduling window: a visit is expected within
 * `office_visit_max_days` of today (0 = today only, 1 = today or tomorrow…).
 * A plan beyond it is not rejected — it is created flagged
 * approval_status=pending and a visits.dispatch holder decides its fate
 * (DecideOfficeVisitApproval). Dispatchers' own plans are exempt: they ARE the
 * approvers. The single source for the rule — CreateNextAction and
 * CorrectNextAction both ask here.
 */
final class OfficeVisitWindow
{
    public const SETTING_KEY = 'office_visit_max_days';

    public const DEFAULT_DAYS = 1;

    public static function maxDays(): int
    {
        return AppSetting::integer(self::SETTING_KEY, self::DEFAULT_DAYS);
    }

    /** The last moment a plan may target without a manager's approval. */
    public static function lastAllowedAt(): Carbon
    {
        return now()->addDays(self::maxDays())->endOfDay();
    }

    /**
     * 'pending' when this plan must wait for a dispatcher's verdict, null when
     * no approval is involved (not an office visit, in-window, planned by a
     * dispatcher, or no planner — console/seeder work is never gated).
     */
    public static function approvalStatusFor(?User $planner, ?string $type, Carbon $dueAt): ?string
    {
        if ($type !== NextActionType::OfficeVisit->value) {
            return null;
        }

        if ($planner === null || $planner->can('visits.dispatch')) {
            return null;
        }

        return $dueAt->greaterThan(self::lastAllowedAt())
            ? NextActionApproval::Pending->value
            : null;
    }
}
