<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
        'source_id', 'rating_id', 'referrer_name', 'referrer_phone',
        'assigned_agent_id', 'notes', 'interests',
        'id_documents', 'id_number', 'birth_date', 'birth_place', 'address',
    ];

    /** Shown wherever a client has no captured name yet. */
    public const NO_NAME = 'No name';

    /** @var list<string> Ids of the `property_interests` items the client wants. */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'interests' => 'array',
            'id_documents' => 'array',
            'birth_date' => 'date:Y-m-d',
        ]);
    }

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

    /** Display name, last name first: "Dupont Jean". Names are optional — a
     * client captured with only a phone shows as "No name" everywhere. */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => trim("{$this->last_name} {$this->first_name}") ?: self::NO_NAME,
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

    /**
     * Visibility rule (clients.view_all): without the grant a user sees only
     * the clients they created or are assigned to follow up — plus clients
     * whose PROJECT they contribute to (creator or non-hidden viewer): being
     * shared a project must include its client, or the project workspace 404s
     * on its own client.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('clients.view_all')) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('created_by', $user->id)
            ->orWhere('assigned_agent_id', $user->id)
            ->orWhereHas('projects', fn (Builder $p) => $p
                ->where('created_by', $user->id)
                ->orWhereHas('viewers', fn (Builder $v) => $v
                    ->whereKey($user->id)
                    ->whereNull('client_project_viewers.hidden_at'))));
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

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /**
     * Workflow rule: a call log is the very first entity captured for a client —
     * visits, deals and desires all come after the qualifying phone call.
     */
    public function hasActiveCall(): bool
    {
        return $this->calls()->active()->exists();
    }

    /** The client-level desire (the one not tied to a specific deal). */
    public function desire(): HasOne
    {
        return $this->hasOne(Desire::class)->whereNull('client_project_id');
    }
}
