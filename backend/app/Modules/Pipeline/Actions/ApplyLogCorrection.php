<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Actions\CancelWaitingDeal;
use App\Modules\Clients\Models\Deal;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Settings\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The workflow that surrounds a log correction (a call or a visit rapport edit).
 * The rapport edit itself is a plain supersedeWith (CorrectCall / CorrectVisit),
 * passed in as `$supersede`; this action owns the two side-effects that make the
 * edit safe when the log had already driven the pipeline forward:
 *
 *  - Deal provenance. A deal born from this log (call_id / visit_id) blocks the
 *    edit once it carries money or a sale (Deal::isJustWaiting is false) — the
 *    deal must be resolved by hand first. While it is still only waiting,
 *    editing the log cancels it (CancelWaitingDeal, releasing the held units) —
 *    a lever of its own (logs.cancel_deal), so a user without it cannot edit a
 *    log that opened a deal.
 *  - Next-action provenance. Superseding the log mints a new row; the still-open
 *    plan the log created is re-pointed onto it, so "this is the last log that
 *    owns the open plan" stays true across repeated edits.
 */
class ApplyLogCorrection
{
    public function __construct(private CancelWaitingDeal $cancelWaitingDeal) {}

    /**
     * @template TModel of BaseModel
     *
     * @param  'call'|'visit'  $sourceType  the log's morph alias (for the next-action link)
     * @param  Closure(): TModel  $supersede  runs the model-specific supersedeWith, returns the new version
     * @return TModel the new (active) version of the log
     */
    public function handle(
        User $actor,
        ?Deal $deal,
        string $sourceType,
        int $sourceId,
        string $dealReason,
        Closure $supersede,
    ): BaseModel {
        if ($deal !== null) {
            abort_unless(
                $deal->isJustWaiting(),
                422,
                'This log opened a deal that already has a payment or a sale — resolve the deal before editing the log.',
            );
            abort_unless(
                $actor->can('logs.cancel_deal'),
                403,
                'Editing this log would cancel the deal it opened, which you are not allowed to do.',
            );
        }

        return DB::transaction(function () use ($deal, $sourceType, $sourceId, $dealReason, $supersede) {
            if ($deal !== null) {
                $this->cancelWaitingDeal->handle($deal, $dealReason);
            }

            $new = $supersede();

            // Keep the still-open plan attached to the log's newest version so
            // the "last log" detection (which offers the next-action change)
            // survives repeated edits. Query update — no model events, no
            // lazy-load tripwire.
            NextAction::query()->active()->pending()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->update(['source_id' => $new->getKey()]);

            return $new;
        });
    }
}
