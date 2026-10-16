<?php

namespace App\Support;

/**
 * 043-products-page-redesign — izin tingkat-AKSI di samping `menu_keys` (research.md D9; rancangan 041
 * Keputusan 6). `menu_keys` menjawab "layar mana yang boleh dibuka"; izin di sini menjawab "aksi mana yang
 * boleh dilakukan di dalamnya" (menandai 86, menyetujui harga, menerbitkan rilis, ...).
 *
 * Satu-satunya sumber daftar kunci dan bawaan peran sistem. `roles.permissions` NULL berarti "pakai
 * bawaan menurut nama peran" — peran buatan pabrik tes dan peran lama tetap bekerja tanpa backfill.
 * Penegakan sebenarnya di server (User::hasPermission); UI hanya menyembunyikan.
 */
class PermissionCatalog
{
    public const ITEM_MANAGE = 'menu.item.manage';

    public const ITEM_86 = 'menu.item.86';

    public const PRICE_BULK = 'menu.price.bulk';

    public const PRICE_APPROVE = 'menu.price.approve';

    public const RELEASE_PUBLISH = 'menu.release.publish';

    public const RECIPE_EDIT = 'menu.recipe.edit';

    /** 045 Stage 6 — transfer stok antar outlet: tiga aksi terpisah (selain penugasan outlet). */
    public const TRANSFER_DISPATCH = 'stock.transfer.dispatch';

    public const TRANSFER_RECEIVE = 'stock.transfer.receive';

    public const TRANSFER_CANCEL = 'stock.transfer.cancel';

    /** 048 / S3 — izin penyetuju di titik aksi (research D11). Pemegang izin tidak butuh token persetujuan. */
    public const ORDER_VOID = 'order.void';

    public const ORDER_CANCEL = 'order.cancel';

    public const CASH_OUT_APPROVE = 'cash.out.approve';

    public const ORDER_REPRINT_APPROVE = 'order.reprint.approve';

    public const PAYMENT_QRIS_VERIFY = 'payment.qris.verify';

    public const ORDER_REFUND = 'order.refund';

    public const MENU_AVAILABILITY_SET = 'menu.availability.set';

    public const SHIFT_VARIANCE_APPROVE = 'shift.variance.approve';

    /** 048 / T050 — menyetujui diskon di atas batas peran (batas persennya ada di kolom roles.discount_limit_pct). */
    public const ORDER_DISCOUNT_APPROVE = 'order.discount.approve';

    /** key => label manusiawi (Indonesia). */
    public const ALL = [
        self::ITEM_MANAGE => 'Mengelola item (diskontinu, aktifkan kembali)',
        self::ITEM_86 => 'Menandai item habis (86)',
        self::PRICE_BULK => 'Mengubah harga massal',
        self::PRICE_APPROVE => 'Menyetujui perubahan harga',
        self::RELEASE_PUBLISH => 'Menerbitkan rilis menu',
        self::RECIPE_EDIT => 'Mengubah resep',
        self::TRANSFER_DISPATCH => 'Mengirim transfer stok antar outlet',
        self::TRANSFER_RECEIVE => 'Menerima transfer stok antar outlet',
        self::TRANSFER_CANCEL => 'Membatalkan transfer stok yang belum diterima',
        self::ORDER_VOID => 'Membatalkan (void) baris yang sudah dikirim ke dapur',
        self::ORDER_CANCEL => 'Membatalkan seluruh tab terbuka',
        self::CASH_OUT_APPROVE => 'Menyetujui kas keluar di atas batas',
        self::ORDER_REPRINT_APPROVE => 'Menyetujui cetak ulang struk',
        self::PAYMENT_QRIS_VERIFY => 'Memverifikasi atau menandai tidak ditemukan pembayaran QRIS',
        self::ORDER_REFUND => 'Refund penuh order lunas',
        self::MENU_AVAILABILITY_SET => 'Menandai item habis (sold out) tanpa persetujuan',
        self::SHIFT_VARIANCE_APPROVE => 'Menyetujui selisih kas tutup shift di atas toleransi',
        self::ORDER_DISCOUNT_APPROVE => 'Menyetujui diskon di atas batas peran',
    ];

    /** @return list<string> */
    public static function transferKeys(): array
    {
        return [self::TRANSFER_DISPATCH, self::TRANSFER_RECEIVE, self::TRANSFER_CANCEL];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    /**
     * Bawaan per peran sistem (nama tanpa memperhatikan huruf besar/kecil). Peran lain: tidak ada izin.
     *
     * @return list<string>
     */
    public static function defaultsFor(string $roleName): array
    {
        return match (mb_strtolower(trim($roleName))) {
            'owner', 'admin' => self::keys(),
            'inventory' => [self::ITEM_MANAGE, self::ITEM_86, self::RECIPE_EDIT, self::TRANSFER_DISPATCH, self::TRANSFER_RECEIVE, self::MENU_AVAILABILITY_SET],
            default => [],
        };
    }
}
