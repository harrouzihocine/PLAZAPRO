<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Scalar app-wide settings (key → value). Plain rows, not BaseModel: a setting
 * is overwritten in place, its history lives in the activity log of whoever
 * changes it. Reads are cached per-request via remembered().
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    /** @var array<string, string>|null */
    private static ?array $remembered = null;

    /** All settings as key → value, loaded once per request. */
    public static function remembered(): array
    {
        return self::$remembered ??= self::query()->pluck('value', 'key')->all();
    }

    public static function integer(string $key, int $default): int
    {
        $value = self::remembered()[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function set(string $key, string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        self::$remembered = null;
    }

    /**
     * Drop the per-request cache. Tests must call this in setUp: the static
     * survives across tests in one PHP process, so values a previous test set
     * would otherwise outlive RefreshDatabase.
     */
    public static function flushRemembered(): void
    {
        self::$remembered = null;
    }
}
