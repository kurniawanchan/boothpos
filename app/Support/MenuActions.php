<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * 057 — registri tunggal AKSI per menu (pasangan dari MenuKeys, yang mendaftar menunya).
 *
 * View selalu ada dan implisit untuk menu yang dimiliki peran, jadi tak terdaftar di sini. `standard` = subset
 * dari create/edit/delete yang bermakna bagi menu itu; `named` = aksi bernama untuk tombol yang bukan CRUD biasa
 * (Void, Verify, Export, …). Setiap rute mutasi/ekspor di routes/api.php dipetakan ke TEPAT satu pasangan
 * (menu, aksi) lewat middleware `ability`; MenuActionsRouteCoverageTest menggagalkan build bila ada yang terlewat.
 *
 * Menambah tombol/rute baru ⇒ tambahkan aksinya di sini (satu tempat), jangan membuat pemeriksaan sendiri.
 */
class MenuActions
{
    public const VIEW = 'view';

    public const STANDARD = ['create', 'edit', 'delete'];

    private const CRUD = ['create', 'edit', 'delete'];

    /** menu => ['standard' => list, 'named' => list]; menu yang tak ada di sini hanya punya View. */
    private const MENUS = [
        'dashboard' => ['standard' => [], 'named' => []],
        'pos' => ['standard' => ['create'], 'named' => ['draft']],
        'session' => ['standard' => ['create', 'edit'], 'named' => []],
        'events' => ['standard' => self::CRUD, 'named' => ['status']],
        // `publish` (customer commerce): terbit/tarik produk dan varian di etalase publik, terpisah dari Ubah.
        'products' => ['standard' => self::CRUD, 'named' => ['bom', 'catalog', 'import', 'export', 'publish']],
        'artists' => ['standard' => self::CRUD, 'named' => ['import', 'export']],
        'categories' => ['standard' => self::CRUD, 'named' => ['import', 'export']],
        'stock' => ['standard' => [], 'named' => ['adjust', 'import', 'export']],
        'vendors' => ['standard' => self::CRUD, 'named' => ['import', 'export']],
        'materials' => ['standard' => self::CRUD, 'named' => ['import', 'export']],
        'purchase_orders' => ['standard' => self::CRUD, 'named' => ['status', 'payment']],
        'customers' => ['standard' => self::CRUD, 'named' => ['import', 'export']],
        'preorders' => ['standard' => self::CRUD, 'named' => ['status', 'dispatch', 'payment', 'verify', 'split', 'duplicate', 'notify', 'import', 'export']],
        // Customer commerce: tanpa CRUD (pesanan dibuat pelanggan lewat aplikasi); satu aksi bernama per transisi
        // status supaya "boleh konfirmasi" bisa dipisah dari "boleh kirim" atau "boleh batalkan".
        'customer_orders' => ['standard' => [], 'named' => ['confirm', 'pack', 'ready', 'pickup', 'ship', 'deliver', 'cancel']],
        'companies' => ['standard' => self::CRUD, 'named' => ['activate']],
        'licenses' => ['standard' => self::CRUD, 'named' => []],
        'invoices' => ['standard' => self::CRUD, 'named' => ['mark_paid', 'cancel', 'import', 'export']],
        'sales' => ['standard' => [], 'named' => ['void', 'payment', 'payment_method', 'verify', 'reference', 'export', 'receipts', 'approve']],
        'reports' => ['standard' => [], 'named' => ['export']],
        'settlements' => ['standard' => ['create', 'delete'], 'named' => ['refresh', 'finalize', 'reopen', 'adjust', 'acknowledge', 'payout']],
        'users' => ['standard' => self::CRUD, 'named' => ['import', 'export']],
        'roles' => ['standard' => self::CRUD, 'named' => ['import', 'export']],
        'settings' => ['standard' => ['edit'], 'named' => ['backup', 'restore', 'branding']],
    ];

    /** Sheet/entitas master data => menu pemiliknya (ekspor/impor master data memeriksa aksi di menu ini). */
    public const MASTER_DATA_MENU = [
        'artists' => 'artists', 'categories' => 'categories', 'products' => 'products', 'stock' => 'stock',
        'vendors' => 'vendors', 'materials' => 'materials', 'vendor_prices' => 'materials', 'bom' => 'products',
        'roles' => 'roles', 'users' => 'users',
    ];

    /**
     * Impor master data berupa SATU berkas yang menyentuh banyak entitas, jadi aksi `import` harus dimiliki pada SETIAP
     * menu master data yang dipegang pengguna (lebih sempit atau sama dengan gerbang lama, tak pernah lebih lebar).
     */
    public static function userMayImportMasterData(\App\Models\User $user): bool
    {
        $held = array_values(array_unique(array_filter(self::MASTER_DATA_MENU, fn ($menu) => $user->canAccessMenu($menu))));

        return $held !== [] && collect($held)->every(fn ($menu) => $user->canDo($menu, 'import'));
    }

    /** Aksi selain View yang tersedia bagi menu (standar dulu, lalu bernama). @return list<string> */
    public static function for(string $menu): array
    {
        $def = self::MENUS[$menu] ?? ['standard' => [], 'named' => []];

        return array_values(array_merge($def['standard'], $def['named']));
    }

    /** Semua aksi menu termasuk View. @return list<string> */
    public static function withView(string $menu): array
    {
        return array_merge([self::VIEW], self::for($menu));
    }

    public static function isStandard(string $action): bool
    {
        return in_array($action, self::STANDARD, true);
    }

    /** Pasangan (menu, aksi) valid — View valid untuk menu terdaftar mana pun. */
    public static function isValid(string $menu, string $action): bool
    {
        return MenuKeys::isValid($menu) && ($action === self::VIEW || in_array($action, self::for($menu), true));
    }

    /** Lempar untuk pasangan tak dikenal supaya salah ketik tak diam-diam menolak/memberi akses. */
    public static function assertValid(string $menu, string $action): void
    {
        if (! self::isValid($menu, $action)) {
            throw new InvalidArgumentException("Aksi '{$action}' tidak dikenal untuk menu '{$menu}'.");
        }
    }

    /** Aksi standar yang dipetakan dari metode HTTP bila middleware dipakai tanpa nama aksi. */
    public static function fromHttpMethod(string $method): string
    {
        return match (strtoupper($method)) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'edit',
            'DELETE' => 'delete',
            default => self::VIEW,
        };
    }

    /** Bentuk {key,label,standard}[] untuk GET /menu-keys (label mengikuti bahasa pengguna). */
    public static function describe(string $menu): array
    {
        return array_map(fn (string $a) => [
            'key' => $a,
            'label' => __('permissions.actions.'.$a),
            'standard' => self::isStandard($a),
        ], self::for($menu));
    }
}
