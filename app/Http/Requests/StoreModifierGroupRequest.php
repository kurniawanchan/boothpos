<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 028 US3 — gerbang `dining_setup`; aturan turunan dinormalisasi oleh ModifierGroupService. */
class StoreModifierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('dining_setup') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'selection_type' => ['required', 'in:single,multiple'],
            'is_required' => ['sometimes', 'boolean'],
            'min_select' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'max_select' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'portion_per_option' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:-32768', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['required', 'array', 'min:1', 'max:100'],
            'options.*.id' => ['sometimes', 'nullable', 'integer'],
            'options.*.name' => ['required', 'string', 'max:100'],
            'options.*.price_delta' => ['required', 'numeric', 'between:-9999999.99,9999999.99'],
            'options.*.display_order' => ['sometimes', 'integer', 'min:-32768', 'max:32767'],
            'options.*.is_active' => ['sometimes', 'boolean'],
            // 087 — bahan per porsi. Kunci ada = penggantian penuh untuk pilihan itu; tidak ada = tidak berubah.
            // Keberadaan/aktif/mode bahan diperiksa di ModifierGroupService (butuh global scope mode).
            'options.*.materials' => ['sometimes', 'array', 'max:20'],
            'options.*.materials.*.material_id' => ['required', 'integer', 'distinct'],
            'options.*.materials.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'options.*.materials.*.material_id.distinct' => __('vendors_materials.option_material_duplicate'),
            'options.*.materials.*.quantity.gt' => __('vendors_materials.option_material_quantity'),
        ];
    }
}
