<?php

namespace App\Services\Release;

use App\Models\MenuChange;
use App\Models\MenuRelease;
use App\Models\User;

/**
 * 043 — menerapkan SATU jenis `menu_change` ke kolom hidup. Dipanggil ReleaseService di dalam transaksi
 * rilis; mengembalikan false bila subjeknya sudah tidak ada (perubahan lalu dibatalkan, bukan gagal diam-diam).
 */
interface ChangeApplier
{
    public function apply(MenuChange $change, User $by, ?MenuRelease $release): bool;
}
