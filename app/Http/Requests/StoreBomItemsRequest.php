<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 034-seller-po-bom — tambah beberapa baris PO sekaligus ke BOM satu varian.
 * Gerbang otorisasi (products + purchase_orders) sengaja di
 * VariantBomController::authorizeBom() — satu tempat untuk semua aksi BOM.
 * Biaya satuan TIDAK diterima dari klien: server menyalinnya dari baris PO.
 */
class StoreBomItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct', 'exists:purchase_order_items,id'],
            // Jumlah per SATU unit produk jadi: > 0, maksimal 4 desimal
            // (kolom decimal(12,4)); kosong = 1.
            'items.*.qty' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999'],
        ];
    }
}
