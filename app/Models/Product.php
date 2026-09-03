<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasDataMode, HasFactory, SoftDeletes;

    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI — 'code_prefix' dan 'product_segment'
     * awalnya sengaja dikeluarkan dari $fillable dengan niat mencegah
     * mass-assignment dari input klien. Niatnya benar, tapi caranya salah:
     * ProductController::store() sendiri butuh menulis KEDUA kolom ini
     * lewat Product::create() (nilainya berasal dari ProductCodeGenerator,
     * bukan dari klien) — akibatnya INSERT selalu gagal 500 "code_prefix
     * doesn't have a default value" karena Eloquent diam-diam membuang
     * kedua field itu. Proteksi sesungguhnya terhadap input klien sudah
     * cukup di lapisan validasi (StoreProductRequest tidak punya rule
     * 'code_prefix' sama sekali, dan UpdateProductRequest sengaja tidak
     * menerima keduanya), jadi $fillable tidak perlu jadi lapis proteksi
     * kedua yang malah memblokir jalur internal yang sah.
     */
    protected $fillable = [
        'artist_id',
        'category_id',
        'code_prefix',
        'product_segment',
        'name',
        'description',
        'image_path',
        'is_preorder',
        'preorder_eta',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_preorder' => 'boolean',
            'is_active' => 'boolean',
            'preorder_eta' => 'date',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
