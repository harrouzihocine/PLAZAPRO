<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A prospective or existing buyer. Source and rating are dynamic-list items; the
 * assigned agent is the sales agent (calls.log) who follows the client up, set by
 * back-office (clients.manage). created_by records who added the client. Clients
 * are cancelled (no-delete) and audited (BaseModel: Cancellable + LogsActivity).
 */
class Client extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'email',
        'source_id', 'rating_id', 'assigned_agent_id', 'notes',
    ];

    /** Title-case each name on read and write so names are always standardized. */
    protected function firstName(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => self::normalizeName($value),
            set: fn (?string $value) => self::normalizeName($value),
        );
    }

    protected function lastName(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => self::normalizeName($value),
            set: fn (?string $value) => self::normalizeName($value),
        );
    }

    /** Display name, last name first: "Dupont Jean". */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => trim("{$this->last_name} {$this->first_name}"),
        );
    }

    /**
     * Standardize a person name: collapse whitespace and title-case each word,
     * preserving hyphens and apostrophes ("jean-paul o'brien" => "Jean-Paul O'Brien").
     */
    public static function normalizeName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($value === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\p{L}[\p{L}\p{M}]*/u',
            fn (array $m) => Str::ucfirst(Str::lower($m[0])),
            $value,
        );
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'source_id');
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'rating_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /** The user who created this client (set on create; back-office visibility only). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(ClientProject::class);
    }

    /** The client-level desire (the one not tied to a specific deal). */
    public function desire(): HasOne
    {
        return $this->hasOne(Desire::class)->whereNull('client_project_id');
    }
}
