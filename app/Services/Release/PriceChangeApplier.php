<?php

namespace App\Services\Release;

use App\Models\MenuChange;
use App\Models\MenuRelease;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\PriceService;

/** 043 — menerapkan perubahan harga dasar lewat PriceService (satu penulis `sell_price`). */
class PriceChangeApplier implements ChangeApplier
{
    public function __construct(private PriceService $prices) {}

    public function apply(MenuChange $change, User $by, ?MenuRelease $release): bool
    {
        $variant = ProductVariant::query()->find($change->subject_id);
        if ($variant === null) {
            return false;
        }

        $new = round((float) $change->payload['new_price'], 2);
        $old = round((float) $variant->sell_price, 2);

        if ($new !== $old) {
            $this->prices->apply($variant, $old, $new, $by, 'release', [
                'release_id' => $release?->id, 'change_id' => $change->id,
                'approved_by' => $change->approval?->decided_by, 'note' => $change->payload['note'] ?? null,
            ]);
        }

        return true;
    }
}
