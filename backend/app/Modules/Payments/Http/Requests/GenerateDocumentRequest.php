<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards document generation. No body to validate — the figures are taken from
 * the versement itself and snapshotted server-side. Defense in depth alongside
 * the can:documents.generate route middleware.
 */
class GenerateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('documents.generate');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
