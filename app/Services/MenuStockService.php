<?php

namespace App\Services;

use App\Exceptions\RecipeStockException;
use App\Models\Concerns\OutletScope;
use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\VariantOutletStock;
use App\Support\IngredientVariants;
use App\Support\RecipeCost;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 092 — SATU-SATUNYA penghitung "stok menu berbasis resep" dan penjaga kapasitasnya (research D1, D3–D5).
 *
 * Stok menu = porsi utuh terbesar yang bisa dibuat dari saldo bahan di OUTLET pemesanan, yaitu kapasitas bahan
 * TERENDAH di antara bahan blok DASAR resep aktif varian itu. Saldo yang dipakai = saldo − bahan yang DITAHAN tab
 * terbuka (bahan baru dipotong saat pesanan selesai — 087 — jadi tanpa tahanan dua tab bisa sama-sama lolos).
 * Nilai ini TURUNAN: tidak disimpan di mana pun, sehingga tidak bisa menyimpang dari saldo bahan.
 *
 * Rumus kebutuhan per porsi = RecipeCost::perPortionNeed, yang juga dipakai pemotongan stok (FR-002).
 */
class MenuStockService
{
    /** Toleransi buku besar bahan (tiga desimal): mencegah 0,3 ÷ 0,1 menjadi 2 karena pecahan biner. */
    private const EPSILON = 0.0005;

    public function __construct(
        private RecipeService $recipes,
        private ModifierMaterialConsumption $consumption,
    ) {}

    /**
     * Jalankan blok kerja dalam transaksi yang membaca data TERBARU (READ COMMITTED) bila ini transaksi terluar.
     *
     * Mengapa: penjaga kapasitas menunggu kunci baris bahan; transaksi yang menunggu pada REPEATABLE READ (bawaan
     * MySQL) tetap memakai snapshot LAMA, jadi tidak melihat baris tab yang baru di-commit transaksi lain dan dua
     * terminal sama-sama lolos. Pada READ COMMITTED setiap SELECT melihat commit terbaru. `SET TRANSACTION`
     * hanya boleh sebelum transaksi dimulai, jadi hanya berlaku bila belum ada transaksi (dalam tes yang dibungkus
     * transaksi, tidak ada keserentakan sehingga dilewati).
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if (DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        // 093: percobaan ulang bila MySQL memilih transaksi ini sebagai korban deadlock (urutan kunci global menutup siklus
        // yang diketahui; ini jaring pengaman untuk kombinasi yang belum terbayang). Hanya berlaku di transaksi terluar.
        return DB::transaction($work, 3);
    }

    /**
     * Stok menu tiap varian BERESEP di outlet ini. Varian tanpa resep aktif tidak ada di hasil (pemanggil memakai
     * stok varian). Jumlah kueri tetap terhadap banyaknya varian (FR-018).
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{portions: int|null, limiting: array<string, mixed>|null}> variant_id => info;
     *                                                                                    `portions` null = tak ada bahan pembatas aktif (tanpa angka, tanpa batas qty)
     */
    public function forVariants(array $variantIds, int $outletId, ?int $exceptOrderId = null): array
    {
        $driven = array_values(array_filter(array_unique(array_map('intval', $variantIds)), fn (int $id) => isset($this->recipes->recipeDrivenVariantIds()[$id])));
        if ($driven === []) {
            return [];
        }

        [$perPortion, $materials, $variantPerPortion, $variantModels] = $this->perPortionNeeds($driven);

        $materialIds = [];
        foreach ($perPortion as $needs) {
            foreach (array_keys($needs) as $materialId) {
                $materialIds[$materialId] = $materialId;
            }
        }
        $materialIds = array_values($materialIds);
        $onHand = $materialIds === [] ? [] : MaterialOutletStock::query()
            ->where('outlet_id', $outletId)->whereIn('material_id', $materialIds)
            ->pluck('current_stock', 'material_id')->map(fn ($n) => (float) $n)->all();
        // 093: saldo varian bahan produk dibaca dari saldo outlet YANG SAMA dengan penjualan langsung (tanpa saldo kedua).
        $variantIds = array_values(array_unique(array_merge(...array_map('array_keys', array_values($variantPerPortion ?: [[]])))));
        $variantOnHand = $variantIds === [] ? [] : VariantOutletStock::query()
            ->where('outlet_id', $outletId)->whereIn('variant_id', $variantIds)
            ->pluck('current_stock', 'variant_id')->map(fn ($n) => (float) $n)->all();
        [$held, $variantHeld] = $this->heldAll($outletId, $exceptOrderId);

        $out = [];
        foreach ($driven as $variantId) {
            $needs = $perPortion[$variantId] ?? [];
            ksort($needs);

            $best = null;
            foreach ($needs as $materialId => $need) {
                $available = max(0.0, ($onHand[$materialId] ?? 0.0) - ($held[$materialId] ?? 0.0));
                $portions = (int) floor(($available + self::EPSILON) / $need);
                // `<` estrik: seri jatuh ke bahan ber-id terkecil (ksort) sehingga penghambat deterministik.
                if ($best === null || $portions < $best['portions']) {
                    $best = ['portions' => $portions, 'type' => 'material', 'id' => $materialId, 'available' => $available, 'need' => $need];
                }
            }
            // 093: lalu bahan berupa PRODUK. Setelah bahan baku dan `<` estrik, sehingga seri jatuh ke bahan baku dulu.
            $variantNeeds = $variantPerPortion[$variantId] ?? [];
            ksort($variantNeeds);
            foreach ($variantNeeds as $ingredientId => $need) {
                $available = max(0.0, ($variantOnHand[$ingredientId] ?? 0.0) - (float) ($variantHeld[$ingredientId] ?? 0));
                $portions = (int) floor(($available + self::EPSILON) / $need);
                if ($best === null || $portions < $best['portions']) {
                    $best = ['portions' => $portions, 'type' => 'variant', 'id' => $ingredientId, 'available' => $available, 'need' => $need];
                }
            }

            $out[$variantId] = $best === null
                ? ['portions' => null, 'limiting' => null]
                : ['portions' => $best['portions'], 'limiting' => $this->limitingFor($best, $materials, $variantModels)];
        }

        return $out;
    }

    /**
     * Bentuk `stock_limiting` satu bahan penghambat. `material_id`/`variant_id` saling lawan (salah satu null); klien lama
     * yang hanya membaca `material_id` tetap benar untuk bahan baku. Saldo = SETELAH tahanan tab terbuka.
     *
     * @param  array{type: string, id: int, available: float, need: float}  $best
     * @param  array<int, Material>  $materials
     * @param  array<int, ProductVariant>  $variantModels
     * @return array<string, mixed>
     */
    private function limitingFor(array $best, array $materials, array $variantModels): array
    {
        if ($best['type'] === 'variant') {
            $v = $variantModels[$best['id']];

            return [
                'ingredient_type' => 'variant', 'material_id' => null, 'variant_id' => $best['id'],
                'name' => $this->variantLabel($v), 'unit' => $v->product?->ingredient_unit ?: 'unit',
                'on_hand' => $this->fmt($best['available']), 'per_portion' => $this->fmt($best['need']),
            ];
        }

        return [
            'ingredient_type' => 'material', 'material_id' => $best['id'], 'variant_id' => null,
            'name' => $materials[$best['id']]->name, 'unit' => $materials[$best['id']]->unit,
            'on_hand' => $this->fmt($best['available']), 'per_portion' => $this->fmt($best['need']),
        ];
    }

    /** Nama produk bahan untuk pesan dan `stock_limiting`: nama produk, plus nama varian bila produknya punya lebih dari satu. */
    private function variantLabel(ProductVariant $v): string
    {
        return $v->product === null ? $v->sku : $v->lineName();
    }

    /**
     * 093 — tahanan varian produk bahan untuk SATU respons: dimuat sekali (satu hitungan tab terbuka), lalu dibaca per varian
     * oleh ProductVariantResource tanpa kueri per kartu (Konstitusi V). Hanya varian yang dirujuk resep yang punya tahanan.
     *
     * @param  array<int, int>  $ids
     */
    private function primeIngredientHolds(Request $request, array $ids, ?int $outletId): void
    {
        $relevant = $outletId === null ? [] : array_filter($ids, fn (int $id) => app(IngredientVariants::class)->has($id));
        $request->attributes->set('ingredient_held', $relevant === [] ? [] : $this->heldVariantsBy($outletId, null));
        $request->attributes->set('ingredient_held_primed', array_fill_keys($ids, true));
    }

    /** Jumlah varian ini yang ditahan tab terbuka (0 bila bukan bahan resep atau tak ada outlet pemesanan). */
    public function ingredientHeldForRequest(Request $request, int $variantId): int
    {
        if (! app(IngredientVariants::class)->has($variantId)) {
            return 0;
        }
        if (isset(($request->attributes->get('ingredient_held_primed') ?? [])[$variantId])) {
            return (int) (($request->attributes->get('ingredient_held') ?? [])[$variantId] ?? 0);
        }
        $outletId = app(\App\Support\OutletPriceBook::class)->orderingOutletId();

        return $outletId === null ? 0 : (int) ($this->heldVariantsBy($outletId, null)[$variantId] ?? 0);
    }

    /**
     * 093 — saldo outlet pemesanan untuk SATU varian di payload POS, dikurangi tahanan tab terbuka bila varian itu dirujuk
     * resep. Satu aturan untuk semua pembaca (ProductVariantResource dan `GET /variants/lookup`). Tanpa tahanan hasilnya
     * persis saldo (perilaku lama); null bila tak ada outlet pemesanan.
     */
    public function availableStockForRequest(Request $request, int $variantId): ?int
    {
        $balance = app(\App\Support\OutletPriceBook::class)->orderingStockOrNull($variantId);
        if ($balance === null) {
            return null;
        }
        $held = $this->ingredientHeldForRequest($request, $variantId);

        return $held === 0 ? $balance : max(0, $balance - $held);
    }

    /**
     * Pemeriksaan awal untuk kiriman PUBLIK pesan mandiri (sebelum pelanggan membayar): jumlah per varian beresep
     * tidak boleh melebihi stok menunya. Ini hanya penyaring ramah; yang menentukan tetap `assertCanFulfil`
     * saat staf mengirim ke dapur (kebutuhan bahan bersama lintas varian dihitung di sana).
     *
     * @param  array<int, int>  $qtyByVariant  variant_id => jumlah
     * @param  array<int, string>  $names  variant_id => nama menu untuk pesan
     *
     * @throws RecipeStockException
     */
    public function assertQuantitiesAvailable(array $qtyByVariant, int $outletId, array $names = []): void
    {
        $stocks = $this->forVariants(array_keys($qtyByVariant), $outletId);
        foreach ($qtyByVariant as $variantId => $qty) {
            $s = $stocks[$variantId] ?? null;
            if ($s === null || $s['portions'] === null || $qty <= $s['portions']) {
                continue;
            }
            $menu = $names[$variantId] ?? '';
            $l = $s['limiting'];

            throw new RecipeStockException(
                __('orders_payments.insufficient_recipe_stock', ['menu' => $menu, 'material' => $l['name'], 'available' => $this->trim((float) $l['on_hand']), 'unit' => $l['unit'], 'required' => $this->trim((float) $l['per_portion'] * $qty)]),
                limiting: ['material_id' => $l['material_id'], 'name' => $l['name'], 'unit' => $l['unit'], 'on_hand' => $l['on_hand'], 'required' => $this->fmt((float) $l['per_portion'] * $qty)],
                availablePortions: (int) $s['portions'],
                menu: $menu,
            );
        }
    }

    /**
     * Isi stok menu SEKALI untuk seluruh varian satu respons daftar (menu POS, pencarian), disimpan di atribut
     * permintaan supaya `ProductVariantResource` membacanya tanpa kueri per varian (FR-018). Atribut permintaan
     * (bukan memo di service) dipilih karena hidup tepat selama satu permintaan, sehingga tidak pernah basi.
     *
     * @param  iterable<int>  $variantIds
     */
    public function prime(Request $request, iterable $variantIds): void
    {
        $outletId = app(\App\Support\OutletPriceBook::class)->orderingOutletId();
        $ids = collect($variantIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        $request->attributes->set('menu_stock', $outletId === null ? [] : $this->forVariants($ids, $outletId));
        $request->attributes->set('menu_stock_primed', array_fill_keys($ids, true));
        $this->primeIngredientHolds($request, $ids, $outletId);
    }

    /**
     * Stok menu satu varian untuk respons: dari hasil `prime()` bila varian itu termasuk, selain itu dihitung
     * sendiri (respons satu-varian: N = 1). null = bukan varian beresep, atau tak ada outlet pemesanan.
     *
     * @return array{portions: int|null, limiting: array<string, mixed>|null}|null
     */
    public function forRequest(Request $request, int $variantId): ?array
    {
        if (! isset($this->recipes->recipeDrivenVariantIds()[$variantId])) {
            return null;
        }
        if (isset(($request->attributes->get('menu_stock_primed') ?? [])[$variantId])) {
            return ($request->attributes->get('menu_stock') ?? [])[$variantId] ?? null;
        }
        $outletId = app(\App\Support\OutletPriceBook::class)->orderingOutletId();

        return $outletId === null ? null : ($this->forVariants([$variantId], $outletId)[$variantId] ?? null);
    }

    /**
     * 093 (research D6) — ambil kunci bahan SEBELUM pergerakan stok pertama sebuah alur, dalam urutan global bahan baku →
     * saldo bahan baku → varian (id naik) → saldo varian. Tanpa ini, alur yang memotong stok langsung dulu lalu menjaga
     * (OrderService::create, TabService::updateLine) mengunci dalam urutan terbalik terhadap alur lain dan bisa deadlock.
     *
     * Hanya bahan yang benar-benar akan disentuh: kebutuhan resep baris yang ada, ditambah varian langsung yang termasuk
     * `IngredientVariants` (yang penjualannya diperiksa terhadap tahanan). Produk biasa tidak mengambil kunci tambahan.
     *
     * @param  Collection<int, OrderItem>  $lines  baris pesanan ini sesudah perubahan
     * @param  list<int>  $extraVariantIds  varian yang akan dijual langsung
     */
    public function lockIngredients(Order $order, Collection $lines, array $extraVariantIds = []): void
    {
        [$need, , $variantNeed] = $this->consumption->recipeNeed($lines);
        $outletId = (int) $order->outlet_id;

        $materialIds = array_keys($need);
        sort($materialIds);
        $direct = array_filter(array_map('intval', $extraVariantIds), fn (int $id) => app(IngredientVariants::class)->has($id));
        $variantIds = array_values(array_unique(array_merge(array_keys($variantNeed), $direct)));
        sort($variantIds);

        if ($materialIds !== []) {
            $this->lockBalances($materialIds, $outletId);
        }
        if ($variantIds !== []) {
            $this->lockVariantBalances($variantIds, $outletId);
        }
    }

    /**
     * Penjaga kapasitas: tolak (409) bila kebutuhan RESEP seluruh baris pesanan ini melebihi saldo bahan setelah
     * tahanan pesanan terbuka LAIN di outlet yang sama. Hanya kebutuhan resep yang dijaga; bahan pilihan modifier
     * tetap peringatan (087, keputusan pemilik 2026-11-06).
     *
     * Harus dipanggil di dalam `transaction()` ini: kunci baris bahan diambil berurutan (Material lalu saldo outlet,
     * urutan id — sama dengan MaterialStockService, tanpa deadlock) dan dilepas saat commit. Karena SEMUA penulis
     * baris yang menahan bahan mengambil kunci ini sebelum commit, pemeriksaan berikutnya selalu melihat baris yang
     * sudah di-commit pendahulunya.
     *
     * @param  Collection<int, OrderItem>  $lines  seluruh baris pesanan ini SESUDAH perubahan
     *
     * @throws RecipeStockException
     */
    public function assertCanFulfil(Order $order, Collection $lines): void
    {
        [$need, $materials, $variantNeed, $variants] = $this->consumption->recipeNeed($lines);
        if ($need === [] && $variantNeed === []) {
            return;
        }

        $outletId = (int) $order->outlet_id;
        $totals = array_map(fn (array $perLine) => array_sum($perLine), $need);
        $ids = array_keys($totals);
        sort($ids);
        $variantTotals = array_map(fn (array $perLine) => array_sum($perLine), $variantNeed);
        $variantIds = array_keys($variantTotals);
        sort($variantIds);

        // Urutan kunci global (093 D6): bahan baku → saldo bahan baku → varian → saldo varian. Sama dengan StockService
        // (varian lalu saldonya), sehingga penjualan langsung produk bahan dan penjaga ini tidak saling menunggu melingkar.
        if ($ids !== []) {
            $this->lockBalances($ids, $outletId);
        }
        if ($variantIds !== []) {
            $this->lockVariantBalances($variantIds, $outletId);
        }

        $onHand = $ids === [] ? [] : MaterialOutletStock::query()->where('outlet_id', $outletId)->whereIn('material_id', $ids)
            ->pluck('current_stock', 'material_id')->map(fn ($n) => (float) $n)->all();
        $variantOnHand = $variantIds === [] ? [] : VariantOutletStock::query()->where('outlet_id', $outletId)->whereIn('variant_id', $variantIds)
            ->pluck('current_stock', 'variant_id')->map(fn ($n) => (float) $n)->all();
        [$held, $variantHeld] = $this->heldAll($outletId, (int) $order->id);

        foreach ($ids as $materialId) {
            $available = ($onHand[$materialId] ?? 0.0) - ($held[$materialId] ?? 0.0);
            if ($totals[$materialId] <= $available + self::EPSILON) {
                continue;
            }

            $this->refuse($order, $lines, $need[$materialId], $outletId, $totals[$materialId], $available, [
                'ingredient_type' => 'material', 'material_id' => $materialId, 'variant_id' => null,
                'name' => $materials[$materialId]->name, 'unit' => $materials[$materialId]->unit,
            ]);
        }
        foreach ($variantIds as $variantId) {
            $available = ($variantOnHand[$variantId] ?? 0.0) - (float) ($variantHeld[$variantId] ?? 0);
            if ($variantTotals[$variantId] <= $available + self::EPSILON) {
                continue;
            }

            $v = $variants[$variantId];
            $this->refuse($order, $lines, $variantNeed[$variantId], $outletId, (float) $variantTotals[$variantId], $available, [
                'ingredient_type' => 'variant', 'material_id' => null, 'variant_id' => $variantId,
                'name' => $this->variantLabel($v), 'unit' => $v->product?->ingredient_unit ?: 'unit',
            ]);
        }
    }

    /**
     * Lempar 409 untuk bahan penghambat: pesan memuat menu pertama yang memakainya dan jumlah menu yang MASIH bisa dibuat.
     *
     * @param  array<int, int|float>  $perLine  kebutuhan bahan itu per baris pesanan
     * @param  array<string, mixed>  $ingredient  ingredient_type, material_id, variant_id, name, unit
     *
     * @throws RecipeStockException
     */
    private function refuse(Order $order, Collection $lines, array $perLine, int $outletId, float $required, float $available, array $ingredient): never
    {
        $lineId = array_key_first($perLine);
        $line = $lines->first(fn (OrderItem $l) => $l->id === $lineId);
        $menu = $line?->name_snapshot ?? '';
        $portions = $line ? ($this->forVariants([(int) $line->variant_id], $outletId, (int) $order->id)[(int) $line->variant_id]['portions'] ?? 0) : 0;
        $shown = max(0.0, $available);

        throw new RecipeStockException(
            __('orders_payments.insufficient_recipe_stock', [
                'menu' => $menu, 'material' => $ingredient['name'], 'available' => $this->trim($shown), 'unit' => $ingredient['unit'], 'required' => $this->trim($required),
            ]),
            limiting: $ingredient + ['on_hand' => $this->fmt($shown), 'required' => $this->fmt($required)],
            availablePortions: (int) $portions,
            menu: $menu,
        );
    }

    // --- internal ----------------------------------------------------------------------------------

    /**
     * Kebutuhan per porsi tiap varian dari blok DASAR resep aktif (blok bermodifier tidak menentukan stok kartu:
     * pilihannya baru diketahui saat dipesan — Asumsi spec). Bahan yang sama di beberapa resep dijumlahkan sebelum
     * dibagi; bahan nonaktif/terhapus dilewati seperti saat pemotongan.
     *
     * @param  list<int>  $variantIds
     * 093: elemen ke-3/4 = kebutuhan per porsi bahan berupa PRODUK `[variant_id menu => [variant_id bahan => per porsi]]` dan modelnya.
     *
     * @return array{0: array<int, array<int, float>>, 1: array<int, Material>, 2: array<int, array<int, float>>, 3: array<int, ProductVariant>} [variant_id => [material_id => per porsi], bahan, varian bahan, modelnya]
     */
    private function perPortionNeeds(array $variantIds): array
    {
        $perPortion = [];
        $materials = [];
        $variantPerPortion = [];
        $variantModels = [];
        foreach ($this->recipes->activeRecipesForVariants($variantIds) as $variantId => $recipes) {
            foreach ($recipes as $recipe) {
                $yield = (float) $recipe->yield;
                if ($yield <= 0) {
                    continue;
                }
                foreach ($recipe->blocks as $block) {
                    if ((int) $block->variant_id !== (int) $variantId || $block->modifier_option_id !== null) {
                        continue;
                    }
                    foreach ($block->lines as $line) {
                        if ($line->variant_id !== null) {
                            $ingredient = $line->variant;
                            $need = $ingredient === null ? 0.0 : RecipeCost::perPortionNeed((float) $line->quantity, 0.0, $yield);
                            if ($need > 1e-9) {
                                $variantPerPortion[$variantId][$ingredient->id] = ($variantPerPortion[$variantId][$ingredient->id] ?? 0.0) + $need;
                                $variantModels[$ingredient->id] ??= $ingredient;
                            }

                            continue;
                        }

                        $material = $line->material;
                        if ($material === null || $material->trashed() || ! $material->is_active) {
                            continue;
                        }
                        $need = RecipeCost::perPortionNeed((float) $line->quantity, (float) $line->shrinkage_pct, $yield);
                        if ($need <= 1e-9) {
                            continue; // kebutuhan 0 tidak membatasi
                        }
                        $perPortion[$variantId][$material->id] = ($perPortion[$variantId][$material->id] ?? 0.0) + $need;
                        $materials[$material->id] ??= $material;
                    }
                }
            }
        }

        return [$perPortion, $materials, $variantPerPortion, $variantModels];
    }

    /**
     * Bahan resep yang DITAHAN pesanan terbuka di outlet ini (kecuali pesanan yang sedang diperiksa): belum
     * terpotong, tetapi sudah dijanjikan. Tanpa tabel reservasi: dihitung dari baris tab yang ada, sehingga
     * hapus/ubah/batal baris otomatis melepasnya (research D4).
     *
     * OutletScope dilewati dengan sengaja: scope itu menyempit mengikuti header `X-Outlet-Id`, padahal tahanan
     * harus mencakup SEMUA tab terbuka di outlet pemesanan agar tidak terhitung kurang. Yang dibaca hanya agregat
     * jumlah bahan, bukan data pesanan.
     *
     * 093: satu hitungan menghasilkan DUA peta — bahan baku (float) dan varian produk bahan (int) — karena keduanya
     * berasal dari kebutuhan resep baris tab yang sama.
     *
     * @return array{0: array<int, float>, 1: array<int, int>} [material_id => jumlah, variant_id => jumlah]
     */
    private function heldAll(int $outletId, ?int $exceptOrderId): array
    {
        $orderIds = Order::query()->withoutGlobalScope(OutletScope::class)
            ->where('outlet_id', $outletId)->where('status', Order::STATUS_OPEN)
            ->when($exceptOrderId !== null, fn ($q) => $q->where('id', '!=', $exceptOrderId))
            ->pluck('id');
        if ($orderIds->isEmpty()) {
            return [[], []];
        }

        [$need, , $variantNeed] = $this->consumption->recipeNeed(OrderItem::query()->whereIn('order_id', $orderIds)->get());

        return [
            array_map(fn (array $perLine) => (float) array_sum($perLine), $need),
            array_map(fn (array $perLine) => (int) array_sum($perLine), $variantNeed),
        ];
    }

    /** @return array<int, float> material_id => jumlah ditahan */
    private function heldBy(int $outletId, ?int $exceptOrderId): array
    {
        return $this->heldAll($outletId, $exceptOrderId)[0];
    }

    /**
     * 093 — varian produk bahan yang DITAHAN pesanan terbuka di outlet ini; dasar ketersediaan varian itu untuk penjualan
     * langsung, menu beresep lain, dan pilihan bahan di resep.
     *
     * @return array<int, int> variant_id => jumlah ditahan
     */
    public function heldVariantsBy(int $outletId, ?int $exceptOrderId): array
    {
        return $this->heldAll($outletId, $exceptOrderId)[1];
    }

    /** @param list<int> $materialIds terurut naik */
    private function lockBalances(array $materialIds, int $outletId): void
    {
        DB::transaction(function () use ($materialIds, $outletId) {
            Material::query()->whereIn('id', $materialIds)->orderBy('id')->lockForUpdate()->get(['id']);
            foreach ($materialIds as $materialId) {
                MaterialOutletStock::query()->insertOrIgnore(['material_id' => $materialId, 'outlet_id' => $outletId, 'current_stock' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
            MaterialOutletStock::query()->where('outlet_id', $outletId)->whereIn('material_id', $materialIds)->orderBy('material_id')->lockForUpdate()->get(['id']);
        });
    }

    /**
     * 093 — kunci varian bahan produk (id naik) lalu saldo-saldonya, SAMA dengan urutan StockService::applyMovement
     * (varian → saldo). Dipanggil sesudah kunci bahan baku (urutan global research D6).
     *
     * @param  list<int>  $variantIds  terurut naik
     */
    private function lockVariantBalances(array $variantIds, int $outletId): void
    {
        DB::transaction(function () use ($variantIds, $outletId) {
            ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get(['id']);
            foreach ($variantIds as $variantId) {
                VariantOutletStock::query()->insertOrIgnore(['variant_id' => $variantId, 'outlet_id' => $outletId, 'current_stock' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
            VariantOutletStock::query()->where('outlet_id', $outletId)->whereIn('variant_id', $variantIds)->orderBy('variant_id')->lockForUpdate()->get(['id']);
        });
    }

    private function fmt(float $n): string
    {
        return number_format($n, 3, '.', '');
    }

    /** 3 desimal tanpa nol di belakang untuk pesan manusia: "3", "0.5", "2.125". */
    private function trim(float $n): string
    {
        return rtrim(rtrim($this->fmt($n), '0'), '.') ?: '0';
    }
}
