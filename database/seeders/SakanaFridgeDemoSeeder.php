<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Material;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMaterialPrice;
use App\Services\OrderService;
use App\Services\PreorderService;
use App\Services\StockService;
use App\Support\ModeGate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 003-seed-demo-live (FR-001/FR-002/FR-003) — dataset dummy lengkap untuk
 * toko "Demo Sakana Fridge". TIDAK dipanggil otomatis dari DatabaseSeeder::run()
 * — dijalankan eksplisit lewat:
 *
 *   php artisan db:seed --class=SakanaFridgeDemoSeeder
 *
 * Seluruh baris yang dibuat di sini WAJIB berlabel data_mode='demo',
 * terlepas dari nilai `system_mode` yang sedang tersimpan — makanya
 * seluruh isi run() dibungkus ModeGate::runAs('demo', ...). Lihat
 * specs/003-seed-demo-live/research.md Decision 2 dan
 * specs/003-seed-demo-live/contracts/demo-seeder-cli.md untuk kontrak
 * idempotensinya.
 *
 * Setiap entitas dibuat lewat service/pola yang sama dengan yang dipakai
 * endpoint sungguhan (ProductCodeGenerator, StockService, OrderService,
 * PreorderService) — bukan raw insert — supaya data dummy ini benar-benar
 * lolos aturan bisnis yang sama seperti data asli (FR-002), bukan kasus
 * khusus yang cuma "terlihat benar".
 */
class SakanaFridgeDemoSeeder extends Seeder
{
    private StockService $stockService;

    private OrderService $orderService;

    private PreorderService $preorderService;

    public function run(): void
    {
        $this->stockService = app(StockService::class);
        $this->orderService = app(OrderService::class);
        $this->preorderService = app(PreorderService::class);

        ModeGate::runAs('demo', function () {
            // Follow-up (2026-09-03) — BUG YANG DITEMUKAN & DIPERBAIKI:
            // ini sebelumnya menulis ke key 'store_name' yang sama dipakai
            // mode LIVE, jadi menjalankan seeder ini diam-diam menimpa
            // nama toko SUNGGUHAN pemilik toko. 'store_name_demo' adalah
            // key terpisah, dibaca hanya saat ModeGate::isDemo() (lihat
            // OrderController::receipt(), SettingsView.vue) — settings
            // TIDAK memakai HasDataMode (data administratif bersama),
            // jadi pemisahannya lewat nama key, bukan kolom data_mode.
            Setting::updateOrCreate(
                ['key' => 'store_name_demo'],
                ['value' => 'Demo Sakana Fridge', 'type' => 'string', 'group' => 'receipt']
            );

            $this->seedDemoUsers();

            $event = $this->seedEvent();
            $categories = $this->seedCategories();
            $artists = $this->seedArtists();
            $variantsByLabel = $this->seedProductsAndVariants($artists, $categories);
            $customers = $this->seedCustomers();
            $vendors = $this->seedVendors();
            $materials = $this->seedMaterialsAndPrices($vendors);
            $this->seedBomLines($variantsByLabel, $materials);
            $this->seedSales($event, $customers, $variantsByLabel);
            $this->seedPreorders($event, $customers, $variantsByLabel);

            $this->command?->info('Seed data Demo Sakana Fridge (mode DEMO) selesai.');
        });
    }

    /**
     * Follow-up 2 (2026-09-03, FR-020) — akun demo terhubung ke ROLE
     * BERSAMA yang sudah ada (Owner/Admin/Kasir/Inventory, tidak
     * diduplikasi per mode — lihat Assumptions FR-020). Dibuat lewat
     * User::firstOrCreate untuk idempotensi (kontrak sama seperti entitas
     * lain di seeder ini), auto-berlabel data_mode='demo' lewat
     * User::booted() karena dibuat di dalam ModeGate::runAs('demo', ...).
     */
    private function seedDemoUsers(): void
    {
        $rows = [
            ['username' => 'kasir_demo', 'name' => 'Kasir Demo', 'role' => 'Kasir'],
            ['username' => 'admin_demo', 'name' => 'Admin Demo', 'role' => 'Admin'],
        ];

        foreach ($rows as $row) {
            $role = Role::where('name', $row['role'])->first();

            if (! $role) {
                continue; // role dasar belum ada — DatabaseSeeder belum dijalankan, lihat contracts/demo-seeder-cli.md
            }

            User::firstOrCreate(
                ['username' => $row['username']],
                [
                    'name' => $row['name'],
                    'password' => Hash::make('password123'),
                    'role_id' => $role->id,
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedEvent(): Event
    {
        return Event::firstOrCreate(
            ['name' => 'Demo Sakana Fridge Meet & Greet Vol.1'],
            [
                'location' => 'Jakarta Convention Center, Senayan',
                'start_date' => now()->subDays(3)->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'status' => 'active',
                'event_cost' => 3500000,
                'notes' => 'Event contoh (data DEMO) untuk menampilkan alur booth multi-artist.',
            ]
        );
    }

    /** @return array<string,Category> keyed by 2-huruf code */
    private function seedCategories(): array
    {
        $rows = [
            'KY' => 'Gantungan Kunci',
            'AC' => 'Standee Akrilik',
            'PN' => 'Pin Emamel',
        ];

        $categories = [];
        foreach ($rows as $code => $name) {
            $categories[$code] = Category::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_active' => true]
            );
        }

        return $categories;
    }

    /** @return array<string,Artist> keyed by 3-huruf code */
    private function seedArtists(): array
    {
        $rows = [
            'NEK' => 'Nekoyama Studio',
            'YUK' => 'Yukishiro Works',
            'HOS' => 'Hoshizora Craft',
        ];

        $artists = [];
        foreach ($rows as $code => $name) {
            $artists[$code] = Artist::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'contact_email' => strtolower($code).'@sakanafridge-demo.test',
                    'is_active' => true,
                ]
            );
        }

        return $artists;
    }

    /**
     * 9 produk (3 per artist x kategori KY/AC/PN), 3 varian per produk
     * dengan stok awal via StockService (movement type 'initial').
     *
     * @param  array<string,Artist>  $artists
     * @param  array<string,Category>  $categories
     * @return array<string,ProductVariant[]> varian dikelompokkan per label produk, dipakai seedBomLines/seedSales/seedPreorders
     */
    private function seedProductsAndVariants(array $artists, array $categories): array
    {
        // [artist_code, category_code, segment 3-huruf, nama produk, [harga_modal, harga_jual, stok] per 3 varian]
        $definitions = [
            ['NEK', 'KY', 'MIK', 'Gantungan Kunci Neko Mikael', [15000, 35000, 60], [17000, 39000, 45], [20000, 45000, 25]],
            ['NEK', 'AC', 'WAR', 'Standee Akrilik Neko Warrior', [22000, 55000, 40], [25000, 62000, 30], [30000, 75000, 15]],
            ['NEK', 'PN', 'GRD', 'Pin Emamel Neko Guardian', [12000, 30000, 70], [13000, 32000, 50], [15000, 36000, 30]],
            ['YUK', 'KY', 'FRO', 'Gantungan Kunci Yuki Frost', [15000, 35000, 60], [17000, 39000, 45], [20000, 45000, 25]],
            ['YUK', 'AC', 'BLD', 'Standee Akrilik Yuki Blade', [22000, 55000, 40], [25000, 62000, 30], [30000, 75000, 15]],
            ['YUK', 'PN', 'STR', 'Pin Emamel Yuki Star', [12000, 30000, 70], [13000, 32000, 50], [15000, 36000, 30]],
            ['HOS', 'KY', 'RGR', 'Gantungan Kunci Hoshi Ranger', [15000, 35000, 60], [17000, 39000, 45], [20000, 45000, 25]],
            ['HOS', 'AC', 'KNT', 'Standee Akrilik Hoshi Knight', [22000, 55000, 40], [25000, 62000, 30], [30000, 75000, 15]],
            ['HOS', 'PN', 'CMT', 'Pin Emamel Hoshi Comet', [12000, 30000, 70], [13000, 32000, 50], [15000, 36000, 30]],
        ];

        $variantLabels = ['Standard', 'Edisi Spesial', 'Edisi Glow'];
        $variantsByLabel = [];

        foreach ($definitions as [$artistCode, $categoryCode, $segment, $name, $v1, $v2, $v3]) {
            $artist = $artists[$artistCode];
            $category = $categories[$categoryCode];
            $prefix = $artist->code.$category->code.$segment;

            $product = Product::where('code_prefix', $prefix)->first();
            $isNewProduct = $product === null;

            if ($isNewProduct) {
                $product = Product::create([
                    'artist_id' => $artist->id,
                    'category_id' => $category->id,
                    'code_prefix' => $prefix,
                    'product_segment' => $segment,
                    'name' => $name,
                    'description' => "Merchandise {$category->name} bertema {$artist->name} (data contoh).",
                    'is_active' => true,
                ]);
            }

            $variants = [];
            foreach ([$v1, $v2, $v3] as $i => [$costPrice, $sellPrice, $stock]) {
                $variantName = $variantLabels[$i];
                $variant = $product->variants()->where('variant_name', $variantName)->first();

                if (! $variant) {
                    $sku = app(\App\Services\ProductCodeGenerator::class)->nextVariantSku($product);

                    $variant = $product->variants()->create([
                        'sku' => $sku,
                        'variant_name' => $variantName,
                        'cost_price' => $costPrice,
                        'sell_price' => $sellPrice,
                        'low_stock_alert' => 5,
                    ]);

                    $this->stockService->applyMovement(
                        variant: $variant,
                        type: 'initial',
                        qtyChange: $stock,
                        reason: 'Stok awal data contoh (SakanaFridgeDemoSeeder).',
                    );
                }

                $variants[] = $variant->fresh();
            }

            $variantsByLabel[$prefix] = $variants;
        }

        return $variantsByLabel;
    }

    /** @return Customer[] */
    private function seedCustomers(): array
    {
        $rows = [
            ['name' => 'Rara Anindya', 'phone' => '0812000001', 'social_handle' => '@rara.demo'],
            ['name' => 'Bagas Wirawan', 'phone' => '0812000002', 'social_handle' => '@bagas.demo'],
            ['name' => 'Citra Maheswari', 'phone' => '0812000003', 'social_handle' => '@citra.demo'],
        ];

        return collect($rows)->map(
            fn (array $row) => Customer::firstOrCreate(
                ['name' => $row['name'], 'phone' => $row['phone']],
                ['social_handle' => $row['social_handle'], 'notes' => 'Customer contoh (data DEMO).']
            )
        )->all();
    }

    /** @return array<string,Vendor> keyed by code */
    private function seedVendors(): array
    {
        $rows = [
            'VON-AKR' => ['name' => 'Akrilik Jaya Online', 'notes' => 'Vendor toko online — lembaran akrilik & PVC.'],
            'VON-PIN' => ['name' => 'Pin Supply Digital', 'notes' => 'Vendor toko online — pin blank & bahan emamel.'],
            'VON-KAN' => ['name' => 'Kanvas Kreatif E-Store', 'notes' => 'Vendor toko online — kain kanvas & sablon.'],
            'VOF-BHN' => ['name' => 'Toko Bahan Kreasi Jaya', 'notes' => 'Vendor toko offline — toko bahan kerajinan fisik.'],
            'VOF-PLS' => ['name' => 'Sumber Plastik Nusantara', 'notes' => 'Vendor toko offline — grosir plastik & PVC.'],
            'VOF-PRC' => ['name' => 'Percetakan Warna Abadi', 'notes' => 'Vendor toko offline — cetak stiker & photocard.'],
        ];

        $vendors = [];
        foreach ($rows as $code => $data) {
            $vendors[$code] = Vendor::firstOrCreate(
                ['code' => $code],
                ['name' => $data['name'], 'notes' => $data['notes'], 'is_active' => true]
            );
        }

        return $vendors;
    }

    /**
     * @param  array<string,Vendor>  $vendors
     * @return array<string,Material> keyed by code
     */
    private function seedMaterialsAndPrices(array $vendors): array
    {
        // [code, nama, satuan, [ [vendor_code, harga, is_preferred], ... ]]
        $rows = [
            ['MAT-AKR3', 'Akrilik Bening 3mm', 'lembar', [
                ['VON-AKR', 18000, true],
                ['VOF-PLS', 19500, false],
            ]],
            ['MAT-PVC1', 'PVC Lembaran 1mm', 'lembar', [
                ['VOF-PLS', 9000, true],
            ]],
            ['MAT-PINB', 'Pin Blank Emamel 32mm', 'pcs', [
                ['VON-PIN', 4500, true],
            ]],
            ['MAT-KVS', 'Kain Kanvas Totebag', 'meter', [
                ['VON-KAN', 32000, true],
            ]],
            ['MAT-VNL', 'Stiker Vinyl Glossy', 'lembar', [
                ['VOF-PRC', 6000, true],
            ]],
            ['MAT-PCD', 'Kertas Photocard Glossy', 'lembar', [
                ['VOF-PRC', 2500, true],
            ]],
            ['MAT-RNG', 'Gantungan Kunci Metal Ring', 'pcs', [
                ['VOF-BHN', 1200, true],
            ]],
            ['MAT-CSE', 'Case Handphone Polos', 'pcs', [
                ['VON-PIN', 8500, true],
            ]],
        ];

        $materials = [];
        foreach ($rows as [$code, $name, $unit, $prices]) {
            $material = Material::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'unit' => $unit, 'is_active' => true]
            );
            $materials[$code] = $material;

            foreach ($prices as [$vendorCode, $price, $isPreferred]) {
                VendorMaterialPrice::updateOrCreate(
                    ['vendor_id' => $vendors[$vendorCode]->id, 'material_id' => $material->id],
                    ['price' => $price, 'is_preferred' => $isPreferred]
                );
            }
        }

        return $materials;
    }

    /**
     * BOM untuk varian pertama tiap produk "Standee Akrilik" (kategori AC)
     * — cukup untuk mendemonstrasikan GET /variants/{variant}/cost-breakdown.
     *
     * @param  array<string,ProductVariant[]>  $variantsByLabel
     * @param  array<string,Material>  $materials
     */
    private function seedBomLines(array $variantsByLabel, array $materials): void
    {
        $akrilik = $materials['MAT-AKR3'];
        $ring = $materials['MAT-RNG'];

        foreach ($variantsByLabel as $prefix => $variants) {
            // Format prefix tetap: artist(3) + kategori(2) + segmen(3) —
            // posisi 3-4 adalah kode kategori (lihat seedProductsAndVariants).
            if (substr($prefix, 3, 2) !== 'AC') {
                continue;
            }

            $variant = $variants[0];

            ProductVariantBomLine::updateOrCreate(
                ['product_variant_id' => $variant->id, 'material_id' => $akrilik->id],
                ['qty_needed' => 1]
            );
            ProductVariantBomLine::updateOrCreate(
                ['product_variant_id' => $variant->id, 'material_id' => $ring->id],
                ['qty_needed' => 1]
            );
        }
    }

    /**
     * @param  Customer[]  $customers
     * @param  array<string,ProductVariant[]>  $variantsByLabel
     */
    private function seedSales(Event $event, array $customers, array $variantsByLabel): void
    {
        if (Order::where('event_id', $event->id)->exists()) {
            return; // idempotensi — sudah pernah di-seed sebelumnya
        }

        $owner = User::where('username', 'owner')->firstOrFail();

        $session = CashierSession::create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'opened_at' => now()->subHours(3),
            'opening_cash' => 500000,
            'status' => 'open',
        ]);

        $variantPool = collect($variantsByLabel)->flatten()->values();

        $orderPlan = [
            [$customers[0]->id, [0, 3]], // Keychain Neko Mikael Standard x1 + Edisi Glow x1 (indeks acak)
            [$customers[1]->id, [6]],
            [null, [12, 15]],
        ];

        foreach ($orderPlan as [$customerId, $variantIndexes]) {
            $items = collect($variantIndexes)
                ->filter(fn ($i) => $variantPool->has($i))
                ->map(fn ($i) => ['variant_id' => $variantPool[$i]->id, 'qty' => 1])
                ->values()
                ->all();

            if ($items === []) {
                continue;
            }

            $subtotalEstimate = collect($items)->sum(
                fn ($item) => (float) $variantPool->firstWhere('id', $item['variant_id'])->sell_price
            );

            $this->orderService->create([
                'session_id' => $session->id,
                'customer_id' => $customerId,
                'items' => $items,
                'payments' => [
                    ['method' => 'cash', 'amount' => $subtotalEstimate, 'purpose' => 'full'],
                ],
                'notes' => 'Transaksi contoh (data DEMO).',
            ], $owner);
        }

        $cashTotal = $session->orders()->where('status', 'completed')->sum('paid_amount');

        $session->update([
            'closed_at' => now(),
            'closing_cash' => 500000 + $cashTotal,
            'expected_cash' => 500000 + $cashTotal,
            'cash_difference' => 0,
            'status' => 'closed',
        ]);
    }

    /**
     * Dua pre-order dengan status berbeda (FR-001/US1 acceptance #9):
     * satu berhenti di 'dp_paid', satu lagi ditransisikan penuh sampai
     * 'handed_over' — mendemonstrasikan siklus penuh arrived (stok naik)
     * dan handed_over (stok turun) sekaligus (lihat CLAUDE.md "Stock
     * movement invariants").
     *
     * @param  Customer[]  $customers
     * @param  array<string,ProductVariant[]>  $variantsByLabel
     */
    private function seedPreorders(Event $event, array $customers, array $variantsByLabel): void
    {
        if (Preorder::where('event_id', $event->id)->exists()) {
            return; // idempotensi
        }

        $owner = User::where('username', 'owner')->firstOrFail();
        $variantPool = collect($variantsByLabel)->flatten()->values();

        // --- Pre-order #1: berhenti di 'dp_paid' ---------------------------
        $variant1 = $variantPool[1];
        $preorder1 = $this->preorderService->create([
            'event_id' => $event->id,
            'customer_id' => $customers[2]->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant1->id, 'qty' => 1]],
            'notes' => 'Pre-order contoh (data DEMO) — baru DP.',
        ], $owner);
        // PreorderService::create() tidak menyertakan 'status' di array
        // create()-nya (mengandalkan DEFAULT 'ordered' di kolom DB), jadi
        // objek yang dikembalikan BELUM memuat 'status' di memori — beda
        // dengan alur HTTP asli yang selalu mengambil ulang model via route
        // model binding pada request berikutnya. Di sini beberapa
        // pemanggilan service dirantai dalam satu proses PHP yang sama,
        // jadi di-refresh eksplisit supaya recordPayment()'s pengecekan
        // status tidak salah membaca null.
        $preorder1->refresh();

        $dpAmount = round((float) $preorder1->total_amount * 0.5, 2);
        $this->preorderService->recordPayment($preorder1, [
            'method' => 'cash',
            'amount' => $dpAmount,
            'purpose' => 'down_payment',
        ]);

        // --- Pre-order #2: siklus penuh sampai 'handed_over' ---------------
        $variant2 = $variantPool[4];
        $preorder2 = $this->preorderService->create([
            'event_id' => $event->id,
            'customer_id' => $customers[0]->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant2->id, 'qty' => 2]],
            'notes' => 'Pre-order contoh (data DEMO) — siklus penuh.',
        ], $owner);
        $preorder2->refresh(); // lihat komentar di preorder1 di atas

        $dp2 = round((float) $preorder2->total_amount * 0.4, 2);
        $preorder2 = $this->preorderService->recordPayment($preorder2, [
            'method' => 'cash',
            'amount' => $dp2,
            'purpose' => 'down_payment',
        ]);

        $preorder2 = $this->preorderService->transitionStatus($preorder2, 'arrived', null, $owner);

        $remaining = round((float) $preorder2->outstanding(), 2);
        $preorder2 = $this->preorderService->recordPayment($preorder2, [
            'method' => 'cash',
            'amount' => $remaining,
            'purpose' => 'settlement',
        ]);

        $this->preorderService->transitionStatus($preorder2, 'handed_over', null, $owner);
    }
}
