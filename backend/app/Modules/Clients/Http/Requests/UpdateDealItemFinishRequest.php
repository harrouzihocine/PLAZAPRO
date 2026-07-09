<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Models\Deal;
use App\Modules\Inventory\Enums\FinishType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Switch the finish (semi-fini / fini) the client committed to on ONE open
 * apartment of the deal — flips which unit price the win dialog prefills.
 * That the unit actually offers the finish is enforced in SetDealItemFinish.
 * Same gate as the box edit: working the open deal (visits.conduct) on a
 * project the actor can SEE.
 */
class UpdateDealItemFinishRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('visits.conduct')) {
            return false;
        }

        $deal = $this->route('deal');

        return $deal instanceof Deal && $deal->clientProject->isVisibleTo($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'finish_type' => ['required', new Enum(FinishType::class)],
        ];
    }
}
