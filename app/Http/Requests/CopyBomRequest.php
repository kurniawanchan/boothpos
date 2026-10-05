<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 034-seller-po-bom — salin BOM. `mode=from` menyalin KE varian pada rute
 * dari `source_variant_id`; `mode=next|all` menyalin DARI varian pada rute.
 * 037: `mode=selected` menyalin DARI varian pada rute ke varian PILIHAN pengguna
 * (`variant_ids`); kecocokan produk/sumber/konfirmasi tetap dijaga VariantBomService::copy().
 * Gerbang menu ada di VariantBomController::authorizeBom().
 */
class CopyBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:from,next,all,selected'],
            'variant_ids' => ['required_if:mode,selected', 'array', 'min:1', 'max:200'],
            'variant_ids.*' => ['integer', 'distinct', 'exists:product_variants,id'],
            'source_variant_id' => ['required_if:mode,from', 'nullable', 'integer', 'exists:product_variants,id'],
            'confirm_replace' => ['sometimes', 'boolean'],
        ];
    }
}
