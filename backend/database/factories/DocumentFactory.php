<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Enums\DocumentType;
use App\Modules\Payments\Models\Document;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'documentable_type' => 'client_project',
            'documentable_id' => ClientProject::factory(),
            'type' => DocumentType::Receipt->value,
            'number' => 'REC-'.now()->year.'-'.Str::padLeft((string) fake()->unique()->numberBetween(1, 999999), 6, '0'),
            'template' => 'documents.receipt',
            'disk' => 'documents',
            'path' => null,
            'render_status' => 'pending',
            'version' => 1,
            'generated_by' => User::factory(),
            'generated_at' => null,
            'meta' => null,
        ];
    }
}
