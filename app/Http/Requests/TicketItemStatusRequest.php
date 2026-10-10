<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 028 US4 — mengubah status satu item tiket. `cancelled` SENGAJA tidak boleh dikirim:
 * pembatalan lewat DELETE /tabs/{id}/items/{item} supaya aturan stok & izin hidup di
 * satu tempat. Izin per-transisi diperiksa di KitchenService (kitchen vs floor).
 */
class TicketItemStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->canAccessMenu('kitchen') || $user->canAccessMenu('floor'));
    }

    public function rules(): array
    {
        return ['status' => ['required', 'in:preparing,ready,served']];
    }
}
