<?php

namespace App\Http\Requests;

use App\Rules\WholeBomQuantity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 036-bom-variant-stock-ux — tombol Simpan pada dialog BOM: kumpulan jumlah
 * per unit yang berubah, disimpan sekaligus (semua-atau-tidak-sama-sekali).
 * Gerbang menu (products + purchase_orders) dipasang controller lewat helper
 * yang sama dengan semua aksi BOM lain; kepemilikan baris oleh varian ini
 * diperiksa service (409), bukan di sini.
 */
class UpdateBomQuantitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.qty_needed' => ['required', new WholeBomQuantity],
        ];
    }
}
