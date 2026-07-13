<?php

declare(strict_types=1);

namespace App\Modules\Web\Models;

use App\Modules\Inventory\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A morph anchor for media owned by the public site itself (not by a project):
 * the landing "hero" library the owner uploads slideshow photos or an intro
 * video into, and the "about" library behind the Who-we-are photo mosaic.
 * One row per well-known key, created lazily. Plain Model on purpose —
 * spaces are infrastructure, not auditable domain records.
 */
class WebsiteSpace extends Model
{
    public const HERO = 'hero';

    public const ABOUT = 'about';

    protected $fillable = ['key'];

    /** The landing-hero library (creates the anchor row on first use). */
    public static function hero(): self
    {
        return static::firstOrCreate(['key' => self::HERO]);
    }

    /** The Who-we-are photo library (creates the anchor row on first use). */
    public static function about(): self
    {
        return static::firstOrCreate(['key' => self::ABOUT]);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }
}
