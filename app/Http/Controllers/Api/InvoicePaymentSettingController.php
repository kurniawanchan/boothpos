<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvoicePaymentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengaturan informasi pembayaran invoice — satu baris singleton (id selalu
 * 1), dijadikan default `payment_information` saat invoice baru dibuat
 * (FR-008/FR-015, 019-billing-system). Digerbang dengan menu key `settings`
 * yang sudah ada (bukan menu key baru — research.md R7').
 */
class InvoicePaymentSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('settings')) {
            return response()->json(['message' => __('orders_payments.not_authorized')], 403);
        }

        $setting = InvoicePaymentSetting::firstOrCreate(['id' => 1]);

        return response()->json($this->present($setting));
    }

    public function update(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('settings')) {
            return response()->json(['message' => __('orders_payments.not_authorized')], 403);
        }

        $validated = $request->validate([
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'account_holder' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
        ]);

        $setting = InvoicePaymentSetting::updateOrCreate(['id' => 1], $validated);

        return response()->json($this->present($setting));
    }

    private function present(InvoicePaymentSetting $s): array
    {
        return [
            'id' => $s->id,
            'bank_name' => $s->bank_name,
            'account_number' => $s->account_number,
            'account_holder' => $s->account_holder,
            'instructions' => $s->instructions,
        ];
    }
}
