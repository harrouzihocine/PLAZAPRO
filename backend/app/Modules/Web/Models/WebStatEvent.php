<?php

declare(strict_types=1);

namespace App\Modules\Web\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One anonymous showcase event (page view, project/unit view, contact click).
 * Append-only log written by POST /public/track and read by the website-stats
 * board; never updated, so the model is deliberately bare.
 */
class WebStatEvent extends Model
{
    /** The event names POST /public/track accepts — everything else is dropped. */
    public const EVENTS = [
        'page_view', 'project_view', 'unit_view',
        'whatsapp_click', 'phone_click', 'social_click', 'share_click',
        'lead_submit',
    ];

    public $timestamps = false;

    protected $fillable = [
        'session_key', 'event', 'location_id', 'unit_id',
        'path', 'referrer', 'locale', 'is_mobile', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_mobile' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
