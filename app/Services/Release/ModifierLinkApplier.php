<?php

namespace App\Services\Release;

use App\Models\MenuChange;
use App\Models\MenuRelease;
use App\Models\Product;
use App\Models\User;
use App\Services\ModifierLinkService;

/** 043 — menerapkan perubahan tautan modifier saat rilis terbit (satu logika dengan ModifierLinkService). */
class ModifierLinkApplier implements ChangeApplier
{
    public function __construct(private ModifierLinkService $links) {}

    public function apply(MenuChange $change, User $by, ?MenuRelease $release): bool
    {
        $product = Product::query()->find($change->subject_id);
        if ($product === null) {
            return false;
        }

        $this->links->applyLinks($product, $change->payload['links'] ?? [], $by);

        return true;
    }
}
