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
    public function label(): string
    {
        return app()->getLocale() === 'en' ? $this->label_en : $this->label_id;
    }
}
