<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Archiving a deal requires an explicit reason picked from the archive_reasons
 * dynamic list (the "Lost / Archived" outcome), optionally with a free note.
 */
class ArchiveClientProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clients.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archive_reason_id' => ['required', 'integer', 'exists:dynamic_list_items,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
