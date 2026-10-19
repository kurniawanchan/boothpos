<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 043-products-page-redesign — alasan yang bisa diatur admin untuk 86, diskontinu, dan penolakan
 * persetujuan (research.md D13). Tidak memakai HasDataMode (data administratif). Kode yang sudah dipakai
 * tidak dihapus, hanya dinonaktifkan.
 */
class ReasonCode extends Model
{
    public const SOLD_OUT = 'sold_out';

    public const DISCONTINUE = 'discontinue';

    public const APPROVAL_REJECT = 'approval_reject';

    protected $fillable = ['category', 'code', 'label_id', 'label_en', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Label dalam bahasa aplikasi saat ini (id bawaan, en bila locale en). */
    /** @var array<string, ?string> cache per permintaan agar daftar order tidak menjalankan satu kueri per baris (057). */
    private static array $discountLabels = [];

    /** 057 / T008 — label alasan diskon untuk payload order; null bila kodenya kosong atau tidak dikenal. */
    public static function discountLabel(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        if (! array_key_exists($code, self::$discountLabels)) {
            self::$discountLabels[$code] = self::query()->where('category', 'discount')->where('code', $code)->first()?->label();
        }

        return self::$discountLabels[$code];
    }

    public function label(): string
    {
        return app()->getLocale() === 'en' ? $this->label_en : $this->label_id;
    }
}
