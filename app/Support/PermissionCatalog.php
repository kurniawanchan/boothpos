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

    /** key => label manusiawi (Indonesia). */
    public const ALL = [
        self::ITEM_MANAGE => 'Mengelola item (diskontinu, aktifkan kembali)',
        self::ITEM_86 => 'Menandai item habis (86)',
        self::PRICE_BULK => 'Mengubah harga massal',
        self::PRICE_APPROVE => 'Menyetujui perubahan harga',
        self::RELEASE_PUBLISH => 'Menerbitkan rilis menu',
        self::RECIPE_EDIT => 'Mengubah resep',
    ];

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
            'inventory' => [self::ITEM_MANAGE, self::ITEM_86, self::RECIPE_EDIT],
            default => [],
        };
    }
}
