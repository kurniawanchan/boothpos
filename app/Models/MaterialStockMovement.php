<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialStockMovement extends Model
{
    use HasDataMode;

    public $timestamps = false;

    protected $fillable = [
        'material_id', 'outlet_id', 'type', 'qty_change', 'stock_before', 'stock_after',
        'reference_type', 'reference_id', 'user_id', 'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (MaterialStockMovement $m) {
            $m->outlet_id ??= \App\Support\OutletStockSync::defaultOutlet();
        });
    }

    public function outlet(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    protected function casts(): array
    {
        return [
            'qty_change' => 'decimal:3',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
