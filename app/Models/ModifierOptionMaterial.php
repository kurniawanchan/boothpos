<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 087 — satu baris "pilihan modifier X memakai q satuan bahan B per porsi". Ditulis hanya oleh
 * ModifierGroupService (bersama pilihannya); dibaca ModifierMaterialConsumption saat pesanan selesai.
 * Pengembalian stok TIDAK membaca tabel ini (pemetaan bisa berubah setelah jual) — lihat research D7.
 */
class ModifierOptionMaterial extends Model
{
    use HasDataMode, HasFactory;

    protected $fillable = ['modifier_option_id', 'material_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ModifierOption::class, 'modifier_option_id');
    }

    /** Termasuk bahan terhapus: pemetaan lama tetap bisa ditampilkan dengan penanda nonaktif. */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class)->withTrashed();
    }
}
