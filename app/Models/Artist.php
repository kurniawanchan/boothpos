<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Artist extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'contact_phone',
        'contact_email',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 'code' selalu dinormalisasi ke uppercase. Ini satu-satunya tempat
     * normalisasi terjadi, agar tidak ada jalur lain yang menyimpan code
     * dalam bentuk campuran huruf.
     */
    protected function code(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            set: fn (string $value) => strtoupper($value),
        );
    }

    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI — relasi ini tidak pernah ditulis,
     * padahal ArtistController::index()/show() memanggil withCount
     * ('products') dan loadCount('products'). Tanpa ini, setiap endpoint
     * artist yang menyertakan jumlah produk fatal error 500 (BadMethod
     * CallException) — tidak pernah ketahuan karena kode ini belum pernah
     * benar-benar dieksekusi sebelum sesi ini.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
