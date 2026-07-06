<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use App\Modules\Settings\Actions\ProcessAvatar;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload a profile photo for the authenticated user. The image is re-encoded
 * and shrunk server-side (see {@see ProcessAvatar}),
 * so the ceiling here only guards against wildly oversized originals — anything
 * within it comes out a few KB.
 */
class UploadAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // max is in kilobytes: 8 MB original, re-compressed down to a few KB.
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ];
    }
}
