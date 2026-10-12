<?php

namespace App\Support;

/**
 * 045 Stage 3 — aturan validasi `outlet_ids` (outlet yang MEMBAWA produk) untuk membuat/mengubah produk.
 * Setiap id harus termasuk outlet yang boleh dilihat pengguna (OutletContext); yang lain tidak pernah diubah.
 */
final class OutletCarriedRule
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'outlet_ids' => ['sometimes', 'array'],
            'outlet_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! app(OutletContext::class)->isPermitted((int) $value)) {
                    $fail(__('outlets.forbidden'));
                }
            }],
        ];
    }
}
