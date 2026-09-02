<?php

use App\Support\MenuKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Langkah 1 dari 2 (lihat research.md Keputusan 5 dan
 * 2026_10_09_000003_finalize_users_role_id.php untuk langkah 2). Kolom
 * 'role' enum lama TIDAK dihapus di sini secara sengaja — hanya
 * ditambahkan role_id (nullable dulu) di sampingnya, lalu di-backfill.
 * Menghapus enum lama menunggu migrasi bertanggal lebih baru yang
 * terbukti aman dijalankan setelah backfill ini diverifikasi (quickstart.md
 * langkah 1).
 *
 * Baris Role yang di-seed di sini (Owner/Admin/Kasir/Inventory) HARUS
 * mereproduksi persis apa yang bisa diakses tiap peran HARI INI — dibaca
 * langsung dari seluruh call site otorisasi yang ada sebelum migrasi ini
 * ditulis:
 *
 * - User::isOwnerOrAdmin() (owner+admin) menggerbangi: ReportController
 *   (profit/artistSettlements/artistSettlementTransactions/artistProfit/
 *   recordSettlementPayment), ActivityLogController::index,
 *   PaymentChannelController (index masking/store/update),
 *   OrderController::void, SettingPolicy (viewAny/update) — semuanya
 *   dipetakan ke kunci menu 'reports'/'settings' pada Fase 2 (lihat
 *   catatan pengecualian pada EventPolicy/OrderController::void di bawah).
 * - User::canManageMasterData() (owner+admin+inventory) menggerbangi:
 *   ArtistPolicy/CategoryPolicy/ProductPolicy/VendorPolicy/MaterialPolicy
 *   (create/update/delete), StockAdjustmentRequest, StoreBomLineRequest/
 *   UpdateBomLineRequest + BOM inline checks (MaterialController),
 *   StoreVendorMaterialPriceRequest/UpdateVendorMaterialPriceRequest +
 *   inline destroyVendorPrice, MasterDataImportController,
 *   MasterDataExportController, ImportMasterDataRequest — dipetakan ke
 *   'products'/'artists'/'categories'/'stock'/'vendors'/'materials'.
 * - AppSidebar.vue NAV_DEFS 'roles' array (sebelum migrasi ini) memvonis
 *   'vendors'/'materials' untuk owner/admin/inventory, dan
 *   'reports'/'settings' untuk owner/admin saja — SEMUA menu lain
 *   (dashboard, pos, session, events, products, artists, categories,
 *   stock, customers, preorders, sales) TIDAK dibatasi peran di sidebar,
 *   meski aksi CUD di baliknya (produk/artist/kategori/stok) tetap
 *   digerbang canManageMasterData() di server.
 *
 * KEPUTUSAN PENTING (dicatat di sini karena berlawanan dengan sidebar
 * lama): kasir dan inventory TIDAK diberi kunci
 * 'products'/'artists'/'categories'/'stock' meski item itu tampil di
 * sidebar mereka SEBELUM migrasi ini. Di model lama, "tampil di sidebar"
 * dan "boleh mengubah data" adalah dua hal terpisah (sidebar hanya
 * kosmetik, lihat CLAUDE.md); di model baru, canAccessMenu() adalah
 * SATU-SATUNYA gerbang untuk keduanya sekaligus. Memberi kasir kunci
 * 'products' supaya sidebar-nya identik akan DIAM-DIAM memberi kasir izin
 * menulis produk lewat API — sebuah eskalasi hak akses nyata yang
 * bertentangan langsung dengan syarat Constitution Principle IV ("tidak
 * ada endpoint yang menjadi lebih permisif selama transisi"). Karena
 * mencegah eskalasi adalah prioritas yang tidak bisa ditawar, kasir dan
 * inventory akan kehilangan beberapa item sidebar yang dulu tampil
 * sebagai info visual saja — didokumentasikan di laporan implementasi
 * sebagai satu-satunya penyimpangan yang disengaja dari kriteria "sidebar
 * identik".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('role')
                ->constrained('roles')->restrictOnDelete();
            $table->string('photo_path')->nullable()->after('role_id');
        });

        $roleDefinitions = [
            'owner' => [
                'name' => 'Owner',
                'menu_keys' => MenuKeys::keys(),
            ],
            'admin' => [
                'name' => 'Admin',
                // Identik dengan Owner: setiap call site isOwnerOrAdmin()/
                // canManageMasterData() yang dibaca untuk migrasi ini tidak
                // pernah membedakan owner dari admin.
                'menu_keys' => MenuKeys::keys(),
            ],
            'cashier' => [
                'name' => 'Kasir',
                'menu_keys' => ['dashboard', 'pos', 'session', 'events', 'customers', 'preorders', 'sales'],
            ],
            'inventory' => [
                'name' => 'Inventory',
                'menu_keys' => [
                    'dashboard', 'pos', 'session', 'events',
                    'products', 'artists', 'categories', 'stock', 'vendors', 'materials',
                    'customers', 'preorders', 'sales',
                ],
            ],
        ];

        $roleIdsByEnumValue = [];

        foreach ($roleDefinitions as $enumValue => $definition) {
            // Idempoten: bila migrasi ini pernah dijalankan sebagian
            // (mis. gagal di tengah jalan lalu diulang), baris yang sudah
            // ada dipakai ulang, bukan diduplikasi.
            $existing = DB::table('roles')->where('name', $definition['name'])->first();

            if ($existing) {
                $roleIdsByEnumValue[$enumValue] = $existing->id;
                continue;
            }

            $roleIdsByEnumValue[$enumValue] = DB::table('roles')->insertGetId([
                'name' => $definition['name'],
                'menu_keys' => json_encode($definition['menu_keys']),
                'is_system_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($roleIdsByEnumValue as $enumValue => $roleId) {
            DB::table('users')->where('role', $enumValue)->update(['role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('photo_path');
        });
    }
};
