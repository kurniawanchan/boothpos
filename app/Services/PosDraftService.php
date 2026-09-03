<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PosDraft;
use App\Models\ProductVariant;
use App\Models\User;

/**
 * 006-purchase-order-and-ops (US4) — CRUD tipis + kepemilikan (satu
 * kasir hanya melihat draft miliknya sendiri, sama seperti sesi kasir
 * sudah dilingkupi user_id). resume() SENGAJA tidak melempar galat untuk
 * referensi yang sudah tidak valid — draft tetap dikembalikan apa adanya
 * plus daftar `warnings` per baris, supaya kasir memutuskan sendiri
 * (hapus baris itu / lanjutkan) alih-alih draft gagal total. Lihat
 * research.md R8.
 */
class PosDraftService
{
    public function listForUser(User $user)
    {
        return PosDraft::where('user_id', $user->id)->orderByDesc('created_at')->get();
    }

    public function save(array $data, User $user): PosDraft
    {
        return PosDraft::create([
            'user_id' => $user->id,
            'event_id' => $data['event_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'cart_snapshot' => [
                'items' => $data['items'],
                'discount_amount' => $data['discount_amount'] ?? 0,
            ],
            'label' => $data['label'] ?? null,
        ]);
    }

    /**
     * @return array{draft: PosDraft, warnings: list<string>}
     */
    public function resume(PosDraft $draft): array
    {
        $warnings = [];
        $snapshot = $draft->cart_snapshot;
        $validItems = [];

        foreach ($snapshot['items'] ?? [] as $item) {
            $variant = ProductVariant::with('product.artist')->find($item['variant_id']);

            if (! $variant || ! $variant->is_active) {
                $warnings[] = __('pos_drafts.variant_unavailable', ['sku' => $item['sku'] ?? $item['variant_id']]);
                continue;
            }

            $validItems[] = [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->product->name.' — '.$variant->variant_name,
                'artist_name' => $variant->product->artist?->name,
                'sell_price' => (string) $variant->sell_price,
                'current_stock' => $variant->current_stock,
                'qty' => $item['qty'],
            ];
        }

        if ($draft->customer_id && ! Customer::find($draft->customer_id)) {
            $warnings[] = __('pos_drafts.customer_unavailable');
        }

        return [
            'items' => $validItems,
            'discount_amount' => $snapshot['discount_amount'] ?? 0,
            'customer_id' => $draft->customer_id,
            'warnings' => $warnings,
        ];
    }

    public function discard(PosDraft $draft): void
    {
        $draft->delete();
    }
}
