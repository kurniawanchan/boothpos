<?php

namespace App\Support;

use App\Models\CashierSession;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Station;

/**
 * 045 Stage 2 (T036) — SATU daftar: tabel mana yang dibatasi per outlet dan jalur kueri mentah mana yang menyentuhnya.
 *
 * Mengapa daftar tertulis: global scope Eloquent (`OutletScope`) TIDAK berlaku untuk `DB::table(...)`, jadi kueri mentah
 * adalah tempat kebocoran antar-outlet paling mungkin (pelajaran yang sama dengan DataModeScope). Tes
 * `OutletScopeRegistryTest` memindai `app/` dan GAGAL bila ada berkas baru yang membuat kueri mentah ke tabel ber-outlet
 * tanpa tercatat di sini — penulisnya dipaksa memutuskan: `filtered` (memakai OutletFilter) atau `exempt` dengan alasan.
 */
final class OutletScopeRegistry
{
    /** Model yang memakai BelongsToOutlet (kolom `outlet_id` NOT NULL). */
    public const MODELS = [
        CashierSession::class, Order::class, Payment::class, KitchenTicket::class,
        DiningTable::class, Station::class,
    ];

    /** Tabel ber-outlet (nama tabel), diturunkan dari MODELS agar tak ada daftar kedua. */
    public static function tables(): array
    {
        return array_map(fn (string $model) => (new $model)->getTable(), self::MODELS);
    }

    /**
     * Tabel LAIN yang punya kolom `outlet_id` tetapi tidak memakai OutletScope otomatis — tiap tabel WAJIB punya alasan dan
     * jalur pembatasnya. Tes `OutletScopeRegistryTest` memindai information_schema dan GAGAL bila ada tabel ber-outlet_id
     * yang tidak ada di MODELS maupun di sini (tabel baru yang terlupa).
     */
    public const OTHER_TABLES = [
        'item_availability_marks' => 'tanda 86: dibaca lewat ItemAvailabilityMark::appliesTo + penyaringan outlet di controller/poll',
        'material_outlet_stock' => 'saldo bahan: dibaca lewat OutletFilter (MaterialController, StockTransfer, laporan)',
        'variant_outlet_stock' => 'saldo item: dibaca lewat OutletFilter (StockController, LowStockHistory, laporan)',
        'stock_movements' => 'buku besar stok: StockController::movements memakai OutletFilter',
        'material_stock_movements' => 'buku besar bahan: hanya dibaca lewat jejak transfer/detail yang sudah terbatas',
        'menu_changes' => 'perubahan menu menunggu rilis: payload membawa outlet; dikelola pemegang izin menu',
        'outlet_item_settings' => 'relasi outlet × item: dikelola lewat endpoint produk yang membatasi outlet yang boleh dilihat',
        'variant_outlet_prices' => 'harga khusus outlet: endpoint harga membatasi outlet yang boleh dilihat',
        'outlet_settlements' => 'snapshot settlement: rekap menyaring outlet yang berhak (entitledIds)',
        'outlet_user' => 'penugasan pengguna ke outlet (sumber hak akses itu sendiri)',
        'price_history' => 'riwayat harga: ProductController::priceHistory menyaring outlet yang boleh dilihat',
        'preorders' => 'riwayat diam sejak 046 (modul pre-order dihapus): tidak ada jalur baca/tulis lagi; tabel dipertahankan apa adanya',
        'purchase_orders' => 'outlet = PENJUAL PO (atribusi item), bukan outlet operasional; menu purchase_orders',
        'session_opening_cash_entries' => 'kas awal per outlet ASAL (penjual) milik satu shift; ikut aksesnya',
    ];

    /**
     * Berkas ber-kueri-mentah ke order/payment/shift/tiket → keputusannya.
     * `filtered` = memakai OutletFilter::apply; `exempt:` = sengaja organisasi-wide, dengan alasan.
     */
    public const RAW_QUERY_FILES = [
        'app/Http/Controllers/Api/ReportController.php' => 'filtered',
        'app/Services/SalesFigures.php' => 'filtered',
        'app/Services/DashboardOrdersQuery.php' => 'exempt: hanya memuat ulang id yang sudah disaring kueri Eloquent ber-OutletScope',
        'app/Services/SettlementService.php' => 'exempt: rekap per periode dikelompokkan per outlet pelaporan; hanya outlet yang berhak ditampilkan pemanggilnya (ReportController)',
        'app/Services/StockMovementReferences.php' => 'exempt: menerjemahkan referensi pergerakan stok (verifikasi varian), tidak menampilkan angka',
        'app/Services/ModifierGroupService.php' => 'exempt: penjaga hapus grup modifier — harus melihat pemakaian di SEMUA outlet',
        'app/Http/Controllers/Api/DiningTableController.php' => 'exempt: penjaga hapus meja — keberadaan order di outlet manapun',
        'app/Http/Controllers/Api/StationController.php' => 'exempt: penjaga hapus stasiun — keberadaan tiket di outlet manapun',
        'app/Http/Controllers/Api/PaymentChannelController.php' => 'exempt: penjaga hapus kanal pembayaran — pemakaian di outlet manapun',
    ];
}
