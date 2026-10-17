<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/** Semua perubahan nomor urut SKU satu produk dalam satu permintaan (spec 045). */
class UpdateVariantSequencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Sama dengan mengubah produk: yang boleh menyunting produk boleh menyunting nomor SKU-nya.
        return $this->user()?->can('update', $this->route('product') ?? Product::class) ?? false;
    }

    /** Nomor dipotong spasinya; pemeriksaan aturan ada di VariantSkuService. */
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }

        $this->merge([
            'items' => array_map(function ($item) {
                if (is_array($item) && is_string($item['sequence'] ?? null)) {
                    $item['sequence'] = trim($item['sequence']);
                }

                return $item;
            }, $items),
        ]);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:500'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.sequence' => ['nullable', 'string', 'max:20'],
        ];
    }
}
