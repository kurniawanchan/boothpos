<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 048 / T058 — DELETE /tabs/{id}/discounts. Gerbang menu SAMA dengan pemberian diskon (`floor`): tanpa ini siapa
 * pun yang sudah login bisa menghapus diskon order. Order di luar outlet tetap 404 lewat scope BelongsToOutlet.
 */
class ClearDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
