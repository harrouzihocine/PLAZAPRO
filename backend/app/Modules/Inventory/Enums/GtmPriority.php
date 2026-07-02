<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * Go-to-market (sales) priority of a project — how hard the sales team should
 * push to sell it. Higher degrees are surfaced and sorted first so the vente
 * team focuses on them before lower-priority projects. Distinct from the
 * record `status` and from a unit's commercial `sale_status`.
 */
enum GtmPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Sort weight — higher means "sell this first". Used to order project lists
     * so high-priority projects lead.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
