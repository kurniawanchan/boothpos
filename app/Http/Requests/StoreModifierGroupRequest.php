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
            // 089 — jumlah per pilihan saat dipilih. Tanpa kunci = tidak berubah (baru: 1..1); `max_qty` null = tanpa batas atas.
            // Hubungan min ≤ maks diperiksa ModifierGroupService (bisa bergantung pada nilai tersimpan bila hanya satu dikirim).
            'options.*.min_qty' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'options.*.max_qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'options.*.display_order' => ['sometimes', 'integer', 'min:-32768', 'max:32767'],
            'options.*.is_active' => ['sometimes', 'boolean'],
            // 087 — bahan per porsi. Kunci ada = penggantian penuh untuk pilihan itu; tidak ada = tidak berubah.
            // Keberadaan/aktif/mode bahan diperiksa di ModifierGroupService (butuh global scope mode).
            'options.*.materials' => ['sometimes', 'array', 'max:20'],
            // BUG YANG DITEMUKAN & DIPERBAIKI (089): aturan `distinct` pada DUA wildcard membandingkan seluruh nilai lintas
            // pilihan, jadi Cabe di pilihan 1, 2, dan 4 ditolak walau tiap pilihan hanya memakainya sekali. Kembar hanya
            // dilarang DI DALAM satu pilihan (lihat withValidator); satu bahan di banyak pilihan sah (tabel
            // `modifier_option_materials` unik per (pilihan, bahan)).
            'options.*.materials.*.material_id' => ['required', 'integer'],
            'options.*.materials.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'options.*.materials.*.quantity.gt' => __('vendors_materials.option_material_quantity'),
        ];
    }

    /** Bahan yang sama tidak boleh ditulis dua kali di dalam SATU pilihan; galat menunjuk pilihan dan baris yang bersangkutan. */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('options', []) as $i => $option) {
                $seen = [];
                foreach ((array) ($option['materials'] ?? []) as $j => $row) {
                    $id = is_array($row) ? ($row['material_id'] ?? null) : null;
                    if ($id === null || $id === '') {
                        continue;
                    }
                    if (isset($seen[(int) $id])) {
                        $validator->errors()->add("options.{$i}.materials.{$j}.material_id", __('vendors_materials.option_material_duplicate', ['option' => (string) ($option['name'] ?? '')]));
                    }
                    $seen[(int) $id] = true;
                }
            }
        });
    }
}
