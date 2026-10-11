<?php

namespace App\Services\Release;

use App\Models\MenuChange;
use App\Models\MenuRelease;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductLifecycleService;

/** 043 — menerapkan terbit / diskontinu / aktifkan lagi lewat ProductLifecycleService. */
class ItemLifecycleApplier implements ChangeApplier
{
    public function __construct(private ProductLifecycleService $lifecycle) {}

    public function apply(MenuChange $change, User $by, ?MenuRelease $release): bool
    {
        $product = Product::query()->find($change->subject_id);
        if ($product === null) {
            return false;
        }

        match ($change->kind) {
            MenuChange::KIND_ITEM_PUBLISH => $this->lifecycle->applyPublish($product, $by),
            MenuChange::KIND_ITEM_DISCONTINUE => $this->lifecycle->applyDiscontinue($product, $by, $change->payload['reason_code_id'] ?? null, $change->payload['note'] ?? null),
            MenuChange::KIND_ITEM_REACTIVATE => $this->lifecycle->applyReactivate($product, $by),
        };

        return true;
    }
}
