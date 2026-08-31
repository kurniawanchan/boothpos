<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group'];
    public $timestamps = true;

    /**
     * BUG YANG DITEMUKAN LEWAT TEST (test_upgrading_to_pro_immediately_
     * unblocks_creation): Cache::rememberForever tanpa invalidasi berarti
     * perubahan setting via updateOrCreate/save tidak pernah terlihat
     * sampai cache di-flush manual — termasuk saat admin upgrade Pro ke
     * Master, perubahan tidak berlaku sampai restart aplikasi. Ditambal lewat
     * model event supaya berlaku untuk SEMUA jalur perubahan (seeder,
     * endpoint admin nanti, tinker), bukan cuma satu tempat.
     */
    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => Cache::forget("setting:{$setting->key}"));
        static::deleted(fn (Setting $setting) => Cache::forget("setting:{$setting->key}"));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("setting:{$key}", function () use ($key, $default) {
            $row = static::where('key', $key)->first();
            return $row ? $row->value : $default;
        });
    }
}
