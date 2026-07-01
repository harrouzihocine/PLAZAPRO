<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\MediaType;
use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
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
            'file' => [
                'required', 'file',
                'max:'.(200 * 1024), // 200 MB ceiling (nginx client_max_body_size must match)
                // `mimetypes` validates the file's *detected* MIME (content-based),
                // never the extension — per the security baseline.
                'mimetypes:'.implode(',', MediaType::allowedMimes()),
            ],
            'collection' => ['nullable', 'string', 'max:255'],
        ];
    }
}
