<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 045 Stage 5 — saldo stok bahan di satu outlet. Hanya MaterialStockService yang menulis. */
class MaterialOutletStock extends Model
{
    protected $table = 'material_outlet_stock';

    protected $fillable = ['material_id', 'outlet_id', 'current_stock'];

    protected function casts(): array
    {
        return ['current_stock' => 'decimal:3'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
