<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;

/** 043-products-page-redesign — satu pengajuan ubah harga massal (cakupan, jenis, nilai, pembulatan). */
class PriceChangeBatch extends Model
{
    use HasDataMode;

    protected $fillable = ['scope', 'type', 'value', 'rounding', 'effective_from', 'created_by'];

    protected function casts(): array
    {
        return ['scope' => 'array', 'value' => 'decimal:4', 'effective_from' => 'date'];
    }
}
