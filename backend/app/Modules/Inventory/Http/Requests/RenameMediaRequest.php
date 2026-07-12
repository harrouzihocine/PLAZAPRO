<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenameMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('media.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Display name only — never a path (the stored file keeps its
            // randomized name). Slashes are rejected so a crafted name can't
            // masquerade as one in Content-Disposition/download prompts.
            'original_name' => ['required', 'string', 'max:255', 'not_regex:#[/\\\\]#'],
        ];
    }
}
