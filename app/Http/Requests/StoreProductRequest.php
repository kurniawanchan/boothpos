<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    public function rules(): array
    {
        return [
            ...\App\Support\OutletCarriedRule::rules(),
            // 070 — saat MEMBUAT, daftar outlet pembawa yang dikirim tidak boleh kosong (item tak dijual di mana pun);
            // tanpa `outlet_ids` semua outlet membawa (perilaku lama). Ubah item tetap memakai aturan umum.
            'outlet_ids' => ['sometimes', 'array', 'min:1'],
            'home_outlet_id' => ['required', 'integer', 'exists:outlets,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'product_segment' => ['nullable', 'string', 'size:3', 'alpha'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            // 043 — fakta item (tidak dibatasi rilis: berlaku seketika).
            'short_name' => ['sometimes', 'nullable', 'string', 'max:24'],
            'tax_category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'prep_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600'],
            'is_retail' => ['sometimes', 'boolean'],
            // 087 US6 — sumber stok produk; efeknya di semua modul ditentukan ProductVariant::tracksOwnStock().
            'stock_source' => ['sometimes', 'in:variant,modifier'],
            'is_active' => ['sometimes', 'boolean'],
            'station_id' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! \App\Models\Station::whereKey($value)->exists()) {
                    $fail(__('restaurant.station_not_found'));
                }
            }],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.variant_name' => ['required', 'string', 'max:100'],
            'variants.*.cost_price' => ['sometimes', 'numeric', 'min:0'],
            'variants.*.sell_price' => ['required', 'numeric', 'min:0'],
            'variants.*.low_stock_alert' => ['nullable', 'integer', 'min:0'],
            'variants.*.track_stock' => ['sometimes', 'boolean'],
            // 070 — harga khusus per outlet saat membuat (ditulis PriceService dalam transaksi store()).
            'variants.*.outlet_prices' => ['sometimes', 'array'],
            'variants.*.outlet_prices.*.outlet_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! app(\App\Support\OutletContext::class)->isPermitted((int) $value)) {
                    $fail(__('outlets.forbidden'));
                }
            }],
            'variants.*.outlet_prices.*.price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    /**
     * 070 — aturan lintas-baris: tiap outlet harga harus membawa item (outlet_ids bila dikirim, selain itu semua outlet
     * yang boleh dilihat) dan tidak boleh muncul dua kali pada satu varian.
     */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator) {
            $carried = $this->has('outlet_ids')
                ? array_map('intval', (array) $this->input('outlet_ids'))
                : app(\App\Support\OutletContext::class)->permittedIds();

            foreach ((array) $this->input('variants', []) as $i => $variant) {
                $seen = [];
                foreach ((array) ($variant['outlet_prices'] ?? []) as $j => $row) {
                    $id = (int) ($row['outlet_id'] ?? 0);
                    if (isset($seen[$id])) {
                        $validator->errors()->add("variants.{$i}.outlet_prices.{$j}.outlet_id", __('products.outlet_price_duplicate'));
                    } elseif (! in_array($id, $carried, true) && ! $validator->errors()->has("variants.{$i}.outlet_prices.{$j}.outlet_id")) {
                        $validator->errors()->add("variants.{$i}.outlet_prices.{$j}.outlet_id", __('products.outlet_price_not_carried'));
                    }
                    $seen[$id] = true;
                }
            }
        }];
    }
}
