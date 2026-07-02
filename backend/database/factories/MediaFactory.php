<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Enums\MediaCollection;
use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'mediable_type' => 'location',
            'mediable_id' => Location::factory(),
            'collection' => MediaCollection::Photos->value,
            'type' => MediaType::Photo->value,
            'disk' => 'media',
            'path' => 'uploads/'.Str::uuid()->toString().'.jpg',
            'original_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'sort_order' => 0,
            'uploaded_by' => User::factory(),
        ];
    }
}
