<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Tidak ada UPDATE/DELETE terhadap tabel ini di aplikasi
 * manapun — koreksi selalu berupa baris baru bertipe 'adjustment'.
 */
class StockMovement extends Model
{
    use HasDataMode;

    public $timestamps = false; // hanya created_at, diisi manual di service

    protected $fillable = [
        'variant_id', 'type', 'qty_change', 'stock_before', 'stock_after',
        'reference_type', 'reference_id', 'reason', 'user_id', 'created_at',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 048 — satu-satunya implementasi pencarian/filter layar Stok (pola sama dengan
     * Product::scopeMatchingListFilters): `search` mencocokkan SKU, nama varian, atau
     * nama produk; `artist_id[]`/`category_id[]` menyaring lewat produk varian itu.
     *
     * Semua whereHas memakai withTrashed(): riwayat varian/produk yang sudah dihapus
     * tetap harus tampil (lihat StockController::movements), jadi pencarian tidak
     * boleh diam-diam menjatuhkannya. Wildcard LIKE (% dan _) dari input pengguna
     * di-escape supaya "%" berarti karakter "%", bukan "cocokkan semuanya".
     *
     * @param  array{search?: mixed, artist_id?: mixed, category_id?: mixed}  $filters
     */
    public function scopeMatchingListFilters(Builder $query, array $filters): Builder
    {
        $term = trim((string) ($filters['search'] ?? ''));
        $artistIds = self::numericIds($filters['artist_id'] ?? []);
        $categoryIds = self::numericIds($filters['category_id'] ?? []);

        return $query
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->whereHas('variant', fn (Builder $v) => $v->withTrashed()->where(function (Builder $w) use ($like) {
                    $w->where('sku', 'like', $like)
                        ->orWhere('variant_name', 'like', $like)
                        ->orWhereHas('product', fn (Builder $p) => $p->withTrashed()->where('name', 'like', $like));
                }));
            })
            ->when($artistIds !== [], fn (Builder $q) => $q->whereHas('variant', fn (Builder $v) => $v->withTrashed()
                ->whereHas('product', fn (Builder $p) => $p->withTrashed()->whereIn('artist_id', $artistIds))))
            ->when($categoryIds !== [], fn (Builder $q) => $q->whereHas('variant', fn (Builder $v) => $v->withTrashed()
                ->whereHas('product', fn (Builder $p) => $p->withTrashed()->whereIn('category_id', $categoryIds))));
    }

    /** @return list<int> hanya nilai numerik; entri sampah diabaikan, bukan error. */
    private static function numericIds(mixed $value): array
    {
        return array_values(array_map('intval', array_filter((array) $value, 'is_numeric')));
    }
}
