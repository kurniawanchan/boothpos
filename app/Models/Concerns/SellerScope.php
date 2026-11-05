<?php

namespace App\Models\Concerns;

use App\Support\SellerGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * 058 — scope BERNAMA (seperti DataModeScope) supaya bisa dilepas eksplisit dengan
 * `withoutGlobalScope(SellerScope::class)` pada pengecekan keberadaan yang memang harus lintas seller.
 *
 * Aturan per tabel ada DI SINI saja (satu tempat, data-model.md). Header order/pre-order tampak bila ADA baris
 * milik seller (keputusan Q1:B) — relasi item sengaja tidak di-scope supaya order tampil utuh.
 */
class SellerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = SellerGate::current();
        if ($id === null) {
            return;
        }

        $t = $model->getTable();
        $variantsOfSeller = fn ($q) => $q->select('id')->from('product_variants')
            ->whereIn('product_id', SellerGate::productIdsSubquery($id));

        match ($t) {
            'artists' => $builder->where("$t.id", $id),
            'products', 'purchase_orders', 'seller_settlements', 'artist_settlements' => $builder->where("$t.artist_id", $id),
            'product_variants' => $builder->whereIn("$t.product_id", SellerGate::productIdsSubquery($id)),
            'stock_movements' => $builder->whereIn("$t.variant_id", $variantsOfSeller),
            'product_variant_bom_lines' => $builder->whereIn("$t.product_variant_id", $variantsOfSeller),
            'orders' => $builder->whereIn("$t.id", SellerGate::orderIdsSubquery($id)),
            'preorders' => $builder->whereIn("$t.id", SellerGate::preorderIdsSubquery($id)),
            'customers' => $builder->where(function ($w) use ($t, $id) {
                $w->where("$t.artist_id", $id)
                    ->orWhereIn("$t.id", fn ($q) => $q->select('customer_id')->from('orders')
                        ->whereNotNull('customer_id')
                        ->whereIn('id', SellerGate::orderIdsSubquery($id)))
                    ->orWhereIn("$t.id", fn ($q) => $q->select('customer_id')->from('preorders')
                        ->whereNotNull('customer_id')
                        ->whereIn('id', SellerGate::preorderIdsSubquery($id)));
            }),
            // Pembayaran mengikuti order/pre-order/PO yang terlihat (tak punya seller sendiri).
            'payments' => $builder->where(function ($w) use ($t, $id) {
                $w->whereIn("$t.order_id", SellerGate::orderIdsSubquery($id))
                    ->orWhereIn("$t.preorder_id", SellerGate::preorderIdsSubquery($id))
                    ->orWhereIn("$t.purchase_order_id", fn ($q) => $q->select('id')->from('purchase_orders')->where('artist_id', $id));
            }),
            default => throw new \LogicException("SellerScope belum punya aturan untuk tabel {$t}."),
        };
    }
}
