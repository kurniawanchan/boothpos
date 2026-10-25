<?php

namespace App\Support;

use App\Models\Outlet;
use App\Models\User;

/**
 * 045 Stage 2 — konteks outlet untuk SATU permintaan (research.md D5, contracts/api-scope.md).
 *
 * - `permittedIds()`: outlet aktif yang BOLEH dilihat pengguna — penugasan (`outlet_user`),
 *   atau semua outlet aktif bila peran pengguna ber-flag `all_outlets` (Owner/Admin).
 * - `selectedId()`: pilihan dari header `X-Outlet-Id`. Hanya MENYEMPITKAN daftar di atas;
 *   middleware ResolveOutletContext menolak id di luar daftar dengan 403 sebelum sampai sini.
 * - `effectiveIds()`: yang dipakai untuk menyaring data — pilihan bila ada, selain itu
 *   seluruh daftar yang boleh dilihat.
 *
 * Tanpa pengguna (perintah konsol, antrean, tes yang tidak login) TIDAK ada penyaringan:
 * seluruh outlet aktif dianggap boleh. Hanya permintaan ber-login yang dipersempit.
 *
 * Kelas ini TIDAK menulis apa pun. Penstempelan `outlet_id` dan penyaringan query adalah
 * tugas BelongsToOutlet/OutletScope/OutletFilter (langkah berikutnya di Stage 2).
 */
final class OutletContext
{
    private ?User $user = null;

    private ?int $selected = null;

    /** @var list<int>|null */
    private ?array $permitted = null;

    /** Dipanggil middleware sekali per permintaan. Mengosongkan cache daftar yang boleh dilihat. */
    public function bind(?User $user, ?int $selected): void
    {
        $this->user = $user;
        $this->selected = $selected;
        $this->permitted = null;
    }

    public function reset(): void
    {
        $this->shiftOutlet = null;
        $this->bind(null, null);
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function selectedId(): ?int
    {
        return $this->selected;
    }

    /**
     * @return list<int> id outlet aktif yang boleh dilihat pengguna, diurutkan menurut id
     */
    public function permittedIds(): array
    {
        return $this->permitted ??= $this->computePermitted();
    }

    public function isPermitted(int $outletId): bool
    {
        return in_array($outletId, $this->permittedIds(), true);
    }

    /**
     * 045 Stage 6 — outlet yang pengguna ini BERHAK atas, termasuk yang sudah dinonaktifkan: transfer yang sudah dalam
     * perjalanan tetap harus bisa diterima/dibatalkan walau sebuah outlet dinonaktifkan sementara itu. null = semua
     * outlet (peran ber-all_outlets atau tanpa pengguna). Pilihan X-Outlet-Id menyempitkannya.
     *
     * @return list<int>|null
     */
    public function entitledIds(): ?array
    {
        if ($this->user === null || $this->user->hasAllOutlets()) {
            return $this->selected === null ? null : [$this->selected];
        }
        $ids = $this->user->outlets()->orderBy('outlets.id')->pluck('outlets.id')->map(fn ($id) => (int) $id)->all();

        return $this->selected === null ? $ids : array_values(array_intersect($ids, [$this->selected]));
    }

    public function isEntitled(int $outletId): bool
    {
        if ($this->user === null || $this->user->hasAllOutlets()) {
            return true;
        }

        return in_array($outletId, $this->entitledIds() ?? [], true);
    }

    /** @return list<int> */
    public function effectiveIds(): array
    {
        return $this->selected !== null ? [$this->selected] : $this->permittedIds();
    }

    /**
     * 071 — outlet yang DIMINTA (`outlet_id[]` pada daftar) dipotong dengan outlet yang berlaku. Permintaan hanya
     * menyempitkan, tidak pernah memperluas (Konstitusi IV); `[]` berarti tak satu pun boleh — pemanggil harus
     * mengembalikan hasil kosong, bukan "semua". Satu implementasi untuk daftar Meja dan Stasiun.
     *
     * @param  list<int|string>  $requested
     * @return list<int>
     */
    public function narrowedIds(array $requested): array
    {
        return array_values(array_intersect($this->effectiveIds(), array_unique(array_map('intval', $requested))));
    }

    /**
     * 071 — bentuk untuk parameter `outlet_id[]` pada daftar: tanpa nilai terisi = TIDAK ada filter (null, perilaku lama);
     * ada nilai = `narrowedIds`, yang `[]` bila tak satu pun boleh dilihat (pemanggil mengembalikan hasil kosong).
     *
     * @param  list<int|string|null>  $requested
     * @return list<int>|null
     */
    public function narrowedOrNull(array $requested): ?array
    {
        $filled = array_filter($requested, fn ($v) => $v !== null && $v !== '');

        return $filled === [] ? null : $this->narrowedIds($filled);
    }

    /** Satu-satunya outlet yang berlaku; null bila nol atau lebih dari satu. */
    public function singleId(): ?int
    {
        $ids = $this->effectiveIds();

        return count($ids) === 1 ? $ids[0] : null;
    }

    /** @var array{0: bool, 1: int|null}|null cache per permintaan */
    private ?array $shiftOutlet = null;

    /**
     * 045 Stage 4 — outlet shift TERBUKA milik pengguna yang login (null bila tak ada shift / tak ada pengguna).
     * Dibaca tanpa OutletScope: shift pengguna sendiri tetap ada walau header X-Outlet-Id mempersempit ke outlet lain.
     */
    public function shiftOutletId(): ?int
    {
        if ($this->shiftOutlet === null) {
            $id = $this->user === null ? null : \App\Models\CashierSession::query()
                ->withoutGlobalScope(\App\Models\Concerns\OutletScope::class)
                ->where('user_id', $this->user->id)->where('status', 'open')->value('outlet_id');
            $this->shiftOutlet = [true, $id === null ? null : (int) $id];
        }

        return $this->shiftOutlet[1];
    }

    /**
     * 045 Stage 4 — outlet yang dipakai layar PEMESANAN (POS/tab): outlet shift terbuka, selain itu satu-satunya outlet
     * yang berlaku. Server tetap menegakkan harga/ketersediaan dari outlet shift saat order; ini hanya agar tampilan
     * (harga, kartu, tanda 86) sama dengan yang akan ditagihkan.
     */
    public function orderingId(): ?int
    {
        return $this->shiftOutletId() ?? $this->singleId();
    }

    /** Lebih dari satu outlet yang TERLIHAT — dasar aturan "tersembunyi di satu outlet" di SPA. */
    public function isMulti(): bool
    {
        return count($this->permittedIds()) > 1;
    }

    /** Pengguna ini melihat semua outlet tanpa penugasan (atau tidak ada pengguna sama sekali). */
    public function canSeeAll(): bool
    {
        return $this->user === null || $this->user->hasAllOutlets();
    }

    /** @return list<int> */
    private function computePermitted(): array
    {
        // Outlet::query() memakai DataModeScope: daftar ikut mode data yang sedang aktif.
        if ($this->user === null || $this->user->hasAllOutlets()) {
            return Outlet::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->user->outlets()
            ->where('outlets.is_active', true)
            ->orderBy('outlets.id')
            ->pluck('outlets.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
