<?php

namespace App\Support;

use App\Models\Outlet;
use App\Models\User;

/**
 * 045 Stage 2 — outlet bawaan untuk baris baru yang tidak punya outlet eksplisit.
 *
 * Urutan: outlet tunggal konteks permintaan → outlet pertama yang boleh dilihat pengguna →
 * outlet aktif pertama pada mode data aktif → bila belum ada sama sekali (instalasi baru),
 * SATU outlet bawaan dibuat. Pembuatan ini hanya terjadi bila memang tidak ada outlet, sehingga
 * instalasi satu outlet tetap berjalan tanpa langkah konfigurasi tambahan.
 */
final class OutletDefaults
{
    public static function forNewRecord(): int
    {
        $context = app(OutletContext::class);

        if ($context->user() !== null) {
            $single = $context->singleId();
            if ($single !== null) {
                return $single;
            }
            $permitted = $context->permittedIds();
            if ($permitted !== []) {
                return $permitted[0];
            }
        }

        $id = Outlet::query()->where('is_active', true)->orderBy('id')->value('id');

        return $id !== null ? (int) $id : self::createDefault();
    }

    /**
     * Outlet yang AKAN dipakai baris baru pada konteks ini, tanpa membuat apa pun. Dipakai validasi
     * keunikan (nama meja/stasiun per outlet); null bila belum ada outlet sama sekali, yang berarti
     * belum mungkin ada bentrok.
     */
    public static function peekForNewRecord(): ?int
    {
        $context = app(OutletContext::class);

        if ($context->user() !== null) {
            $single = $context->singleId();
            if ($single !== null) {
                return $single;
            }
            $permitted = $context->permittedIds();
            if ($permitted !== []) {
                return $permitted[0];
            }
        }

        $id = Outlet::query()->where('is_active', true)->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    private static function createDefault(): int
    {
        $code = collect(['UTM', 'UTD', 'OUT'])
            ->first(fn (string $candidate) => ! Outlet::withoutGlobalScopes()->where('code', $candidate)->exists());

        $outlet = new Outlet([
            'code' => $code ?? 'O'.str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT),
            'name' => __('outlets.default_name'),
            'is_active' => true,
        ]);
        $outlet->save();

        // Outlet bawaan baru dilihat pengguna non-admin yang sudah ada, supaya tidak ada yang kehilangan akses.
        User::with('role')->get()
            ->reject(fn (User $user) => $user->hasAllOutlets())
            ->each(fn (User $user) => $user->outlets()->syncWithoutDetaching([$outlet->id]));

        // Daftar outlet yang boleh dilihat sudah di-cache sebelum outlet ini ada; hitung ulang.
        $context = app(OutletContext::class);
        if ($context->user() !== null) {
            $context->bind($context->user(), $context->selectedId());
        }

        return (int) $outlet->id;
    }
}
