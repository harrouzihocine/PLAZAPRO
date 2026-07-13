<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMediaShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Sharing = seeing inventory media + addressing one of your clients.
        // Both grants are baseline for agents; route middleware re-checks them.
        return (bool) $this->user()?->can('units.view')
            && (bool) $this->user()?->can('clients.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The gallery the selection was made in — every media id must
            // belong to it (checked in the controller against the DB).
            'mediable_type' => ['required', Rule::in(['locations', 'units'])],
            'mediable_id' => ['required', 'integer'],
            'client_id' => ['required', 'integer'],
            // A WhatsApp share is a curated handful, not a bulk export.
            'media_ids' => ['required', 'array', 'min:1', 'max:40'],
            'media_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
