<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * An app-wide setting an admin changed from a screen.
 *
 * Config (and so .env) holds every default; a row here overrides one. Reading a
 * key that has no row gives the default back, so deleting the row is "reset".
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    public static function get(string $key, mixed $default = null): mixed
    {
        // The scheduler and console read settings at boot, sometimes on a
        // checkout that has not run this migration yet. Missing table means
        // "no overrides", not a crash before any command can run.
        if (! Schema::hasTable('settings')) {
            return $default;
        }

        return static::find($key)?->value ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function forget(string $key): void
    {
        static::whereKey($key)->delete();
    }
}
