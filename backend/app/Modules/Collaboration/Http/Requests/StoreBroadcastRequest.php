<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Compose + send a custom broadcast notification. Gated by notifications.broadcast.
 * Audience is one of: everyone, a whole role, or a hand-picked list of users. The
 * message is trilingual (en/fr/ar) — at least one language must be filled; each
 * recipient reads it in their own language, falling back to a filled one.
 */
class StoreBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('notifications.broadcast');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'audience_type' => ['required', 'in:all,role,users'],
            'role_id' => ['required_if:audience_type,role', 'nullable', 'integer', 'exists:roles,id'],
            'user_ids' => ['required_if:audience_type,users', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'body' => ['required', 'array'],
            'body.en' => ['nullable', 'string', 'max:2000'],
            'body.fr' => ['nullable', 'string', 'max:2000'],
            'body.ar' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $body = $this->input('body', []);
            $filled = collect(['en', 'fr', 'ar'])
                ->contains(fn ($lang) => is_string($body[$lang] ?? null) && trim($body[$lang]) !== '');

            if (! $filled) {
                $v->errors()->add('body', __('notifications.broadcast.language_required'));
            }
        });
    }
}
