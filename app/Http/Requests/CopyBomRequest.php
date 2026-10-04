<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 034-seller-po-bom — salin BOM. `mode=from` menyalin KE varian pada rute
 * dari `source_variant_id`; `mode=next|all` menyalin DARI varian pada rute.
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
            'mode' => ['required', 'in:from,next,all'],
            'source_variant_id' => ['required_if:mode,from', 'nullable', 'integer', 'exists:product_variants,id'],
            'confirm_replace' => ['sometimes', 'boolean'],
        ];
    }
}
