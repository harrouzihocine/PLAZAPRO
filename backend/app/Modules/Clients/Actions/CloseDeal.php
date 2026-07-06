<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\Deal;
use Illuminate\Support\Facades\DB;

/**
 * Close the WHOLE deal in one move — the bulk face of CloseDealUnit (each
 * apartment still resolves individually underneath, so the per-apartment
 * history and payments stay exact):
 *  - won  → every remaining apartment closes won; an agreed price per
 *           apartment is required (it covers the apartment and its boxes);
 *  - lost → every remaining apartment is released. A fully-lost deal MUST
 *           resolve: `reopen` steps the project back to negotiating so a fresh
 *           deal can start, or `archive` closes the project.
 */
class CloseDeal
{
    public function __construct(private CloseDealUnit $closeUnit) {}

    /**
     * @param  list<array{item_id: int, agreed_price: string}>  $items
     */
    public function handle(
        Deal $deal,
        string $outcome,
        array $items = [],
        ?string $resolution = null,
        ?string $note = null,
    ): Deal {
        abort_unless($deal->isActive(), 422, 'This deal is not active.');
        abort_if($deal->state->isClosed(), 422, 'This deal is already closed.');

        return DB::transaction(function () use ($deal, $outcome, $items, $resolution, $note) {
            $open = $deal->unitItems()->active()
                ->where('state', DealState::Open->value)
                ->get();

            abort_if($open->isEmpty(), 422, 'The deal has no open apartment left to close.');

            if ($outcome === 'won') {
                $prices = collect($items)->keyBy('item_id');
                $missing = $open->first(fn ($item) => ! $prices->has($item->id));
                abort_if(
                    $missing !== null,
                    422,
                    'An agreed price is required for every apartment (close them one by one for a partial win).',
                );

                foreach ($open as $item) {
                    $this->closeUnit->handle($item, 'won', (string) $prices[$item->id]['agreed_price']);
                }

                return $deal->fresh();
            }

            foreach ($open as $item) {
                $this->closeUnit->handle($item, 'lost', null, $resolution, $note);
            }

            return $deal->fresh();
        });
    }
}
