<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 'code_prefix' dan 'product_segment' SENGAJA tidak ada di sini.
     * Keduanya hanya boleh diisi lewat ProductCodeGenerator saat create,
     * dan tidak pernah lewat mass assignment biasa (F19.4 — permanen).
     */
    protected $fillable = [
        'artist_id',
        'category_id',
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
