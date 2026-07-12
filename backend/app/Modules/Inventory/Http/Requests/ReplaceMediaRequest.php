<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Rules\SupportedMediaFile;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceMediaRequest extends FormRequest
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
                // Ceiling from config/media.php — see the Cloudflare edge-cap note there.
                'max:'.(int) config('media.max_upload_kb', 200 * 1024),
                new SupportedMediaFile,
            ],
        ];
    }
}
