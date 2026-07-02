<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * The semantic bucket a media asset belongs to on its mediable (a project or a
 * unit). This is the display grouping — the tabs the user sees — and is distinct
 * from MediaType (the mime-derived technical kind that drives the viewer).
 *
 * A single source of truth for the allowed buckets: the upload request validates
 * against it and the frontend mirrors the labels for its tab bar.
 */
enum MediaCollection: string
{
    case Photos = 'photos';
    case Videos = 'videos';
    case Plans = 'plans';
    case Presentations = 'presentations';
    case Documents = 'documents';
    case Others = 'others';

    /** Human label for the tab bar. */
    public function label(): string
    {
        return match ($this) {
            self::Photos => 'Photos',
            self::Videos => 'Videos',
            self::Plans => 'Plans',
            self::Presentations => 'Presentations',
            self::Documents => 'Documents',
            self::Others => 'Others',
        };
    }

    /** The bucket an upload lands in when none is specified. */
    public static function default(): self
    {
        return self::Others;
    }

    /**
     * All backing values, in display order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
