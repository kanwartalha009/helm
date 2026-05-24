<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value store for workspace-wide settings. Use the static get()/set()
 * helpers — never instantiate directly outside this class.
 */
class WorkspaceSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    /** Defaults applied when a key has never been set. */
    public const DEFAULTS = [
        'workspace_name'     => 'Nova Solution',
        'primary_currency'   => 'USD',
        'daily_sync_time'    => '13:00',   // UTC, hardcoded per spec but kept here for visibility
    ];

    public static function getValue(string $key, mixed $fallback = null): mixed
    {
        $row = static::query()->where('key', $key)->first();
        if ($row) {
            return $row->value;
        }
        return self::DEFAULTS[$key] ?? $fallback;
    }

    public static function setValue(string $key, mixed $value): self
    {
        return static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function allAsMap(): array
    {
        // IMPORTANT: must iterate models, not pluck — pluck() returns the raw
        // DB value and bypasses the `'value' => 'array'` cast. With JSONB,
        // raw bytes look like `"Nova Solution"` (with literal quotes), which
        // breaks every consumer downstream.
        $stored = static::query()
            ->get()
            ->mapWithKeys(fn (self $row) => [$row->key => $row->value])
            ->all();

        return array_merge(self::DEFAULTS, $stored);
    }
}
