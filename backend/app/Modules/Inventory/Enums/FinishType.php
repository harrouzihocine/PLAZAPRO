<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * The delivery finish a unit is offered at. The historical single price was
 * always SemiFini (semi-finished); a unit may quote either finish or both — at
 * least one price always exists (DB check). When both exist the client picks
 * one (shortlist proposal → deal commitment, `finish_type` on both rows) and
 * that finish's price is THE price of the unit for the sale.
 */
enum FinishType: string
{
    case SemiFini = 'semi_fini';
    case Fini = 'fini';
}
