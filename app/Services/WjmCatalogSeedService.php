<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Station;
use App\Models\VariantOutletStock;
use App\Support\LicenseGate;
use App\Support\ModeGate;
use App\Support\OutletStockSync;
use App\Support\WjmCatalog;
use App\Support\WjmCatalogSeedReport;
use Illuminate\Support\Facades\DB;

/**
 * 068 — SATU-SATUNYA pengisi katalog WJM (ekspor Odoo) ke mode LIVE dan DEMO (research D1, D3). Dipanggil
 * perintah app:seed-wjm-catalog dan WjmCatalogSeeder; tidak pernah ikut DatabaseSeeder (FR-001).
 *
 * Setiap mode adalah satu transaksi (FR-018) di bawah ModeGate::runAs(), dan semua tulisan lewat jalur yang
 * sudah sah: kode lewat ProductCodeGenerator, riwayat harga lewat hook ProductVariant → PriceService, grup
 * lewat ModifierGroupService, outlet penjual lewat OutletMenuService, foto lewat ImageUploadService, audit
 * lewat ActivityLogger. Tidak ada stok yang ditulis dan tidak ada kueri mentah atas tabel ber-outlet.
 */
class WjmCatalogSeedService
{
    /** LIVE selalu lebih dulu, supaya katalog sungguhan mendapat kode inisial yang rapi (research D4). */
    private const MODES = [ModeGate::LIVE, ModeGate::DEMO];

    /** @var list<string> jalur foto yang sudah ditulis jalan mode ini; dihapus bila mode dibatalkan (D8) */
    private array $storedPhotos = [];

    public function __construct(
        private ProductCodeGenerator $codes,
        private ImageUploadService $images,
        private OutletMenuService $menu,
        private ModifierGroupService $groups,
        private ActivityLogger $activityLogger,
        private StockService $stock,
    ) {}

    /**
     * @param  list<string>  $modes
     * @return array<string, WjmCatalogSeedReport> laporan per mode, LIVE lebih dulu
     */
    public function run(WjmCatalog $catalog, array $modes, ?string $outletCode = null, bool $dryRun = false): array
    {
        if ($modes === [] || array_diff($modes, self::MODES) !== []) {
            throw new \InvalidArgumentException('Mode harus live dan/atau demo.');
        }

        $reports = [];
        foreach (array_values(array_intersect(self::MODES, $modes)) as $mode) {
            $report = $reports[$mode] = new WjmCatalogSeedReport($mode);

            if ($catalog->problems() !== []) {
                $report->status = WjmCatalogSeedReport::REFUSED;
                $report->problems = $catalog->problems();

                continue;
            }
            if (! $catalog->isApproved()) {
                if (! $dryRun) {
                    $report->status = WjmCatalogSeedReport::REFUSED;
                    $report->problems = ['Katalog masih DRAF: setujui catalog-mapping.md lalu bangkitkan ulang catalog.json.'];

                    continue;
                }
                $report->warnings[] = 'Katalog masih DRAF — pengisian sungguhan akan ditolak.';
            }

            $report->fillFromCatalog($catalog);
            $this->runMode($catalog, $report, $outletCode, $dryRun);
        }

        return $reports;
    }

    private function runMode(WjmCatalog $catalog, WjmCatalogSeedReport $report, ?string $outletCode, bool $dryRun): void
    {
        $this->storedPhotos = [];

        try {
            ModeGate::runAs($report->mode, function () use ($catalog, $report, $outletCode, $dryRun) {
                if (! $dryRun) {
                    DB::transaction(fn () => $this->apply($catalog, $report, $outletCode, writePhotos: true));

                    return;
                }
                // research D9: pratinjau menjalankan jalur yang SAMA lalu membatalkannya, karena alokasi kode dan
                // pencocokan bergantung pada isi DB; simulasi terpisah bisa melenceng dari hasil sungguhan.
                DB::beginTransaction();
                try {
                    $this->apply($catalog, $report, $outletCode, writePhotos: false);
                } finally {
                    DB::rollBack();
                }
            });
            $report->status = $dryRun ? WjmCatalogSeedReport::PREVIEWED : WjmCatalogSeedReport::APPLIED;
            $report->accountFor($catalog);
        } catch (\DomainException $e) {
            // Penolakan yang disengaja (mis. outlet tujuan ambigu): bukan galat sistem.
            $this->discardRun($report);
            $report->status = WjmCatalogSeedReport::REFUSED;
            $report->problems[] = $e->getMessage();
        } catch (\Throwable $e) {
            $this->discardRun($report);
            $report->status = WjmCatalogSeedReport::FAILED;
            $report->problems[] = 'Pengisian mode '.strtoupper($report->mode).' gagal dan dibatalkan seluruhnya: '.$e->getMessage();
            report($e);
        }
    }

    /** Langkah pengisian satu mode, di dalam transaksi dan ModeGate::runAs(). */
    private function apply(WjmCatalog $catalog, WjmCatalogSeedReport $report, ?string $outletCode, bool $writePhotos): void
    {
        $outlet = $this->resolveOutlet($catalog, $report, $outletCode);
        $stations = $this->ensureStations($catalog, $outlet, $report);
        $categories = $this->ensureCategories($catalog, $stations, $report);
        $groups = $this->ensureOptionGroups($catalog, $report);
        [$created, $catalogProductIds] = $this->createItems($catalog, $outlet, $categories, $stations, $report, $writePhotos);
        $this->attachNewGroups($catalog, $groups, $created);
        if ($catalog->withdrawOthers($report->mode)) {
            $this->withdrawOtherItems($outlet, $catalogProductIds, $report);
        }
        $this->logRun($catalog, $outlet, $report);
    }

    /**
     * 068 FR-003 / research D5 dan 069 research D8: pilihan operator → outlet aktif bernama sama dengan outlet
     * katalog → kebijakan katalog. `mode_empty` (WJM 3): satu-satunya outlet aktif dipakai, dan outlet dibuat bila
     * mode belum punya outlet sama sekali. `demo_missing` (WJM 2): LIVE harus sudah punya outletnya, DEMO membuatnya
     * bila belum ada, dan outlet lain TIDAK PERNAH dipakai: katalog outlet kedua yang jatuh ke satu-satunya outlet
     * aktif akan memasang menu di outlet pertama. Selain itu ditolak (DomainException), karena menebak outlet di
     * instalasi multi-outlet bisa memasang menu di tempat yang salah.
     */
    private function resolveOutlet(WjmCatalog $catalog, WjmCatalogSeedReport $report, ?string $outletCode): Outlet
    {
        $active = Outlet::query()->where('is_active', true)->orderBy('id')->get();
        $mode = strtoupper($report->mode);
        $policy = $catalog->outlet();

        if ($outletCode !== null) {
            $outlet = $active->first(fn (Outlet $o) => strtoupper($o->code) === strtoupper($outletCode));
            if ($outlet === null) {
                throw new \DomainException("Outlet {$outletCode} tidak ada atau tidak aktif di mode {$mode}.");
            }
        } else {
            $wanted = mb_strtolower(trim($policy['name']));
            $named = $active->filter(fn (Outlet $o) => mb_strtolower(trim($o->name)) === $wanted)->values();
            if ($named->count() > 1) {
                $codes = $named->pluck('code')->implode(', ');
                throw new \DomainException("Ada {$named->count()} outlet bernama \"{$policy['name']}\" di mode {$mode} ({$codes}). Pilih dengan --outlet=KODE bersama satu --mode.");
            }
            $outlet = $named->first();

            if ($outlet === null && $policy['create_when'] === 'demo_missing') {
                if ($report->mode === ModeGate::LIVE) {
                    throw new \DomainException("Outlet \"{$policy['name']}\" tidak ada di mode LIVE. Buat outlet itu lebih dulu lewat layar Outlet.");
                }

                return $this->createOutlet($catalog, $report);
            }
            if ($outlet === null && $policy['create_when'] === 'mode_empty') {
                $outlet = $active->count() === 1 ? $active->first() : null;
                if ($outlet === null && ! Outlet::query()->exists()) {
                    return $this->createOutlet($catalog, $report);
                }
            }
            if ($outlet === null) {
                $list = $active->map(fn (Outlet $o) => "{$o->code} — {$o->name}")->implode(', ') ?: 'tidak ada outlet aktif';
                throw new \DomainException("Outlet tujuan di mode {$mode} tidak bisa ditentukan (tidak ada \"{$policy['name']}\"). Pilih dengan --outlet=KODE bersama satu --mode. Outlet aktif: {$list}.");
            }
        }

        $report->outlet = ['code' => $outlet->code, 'name' => $outlet->name, 'created' => false];

        return $outlet;
    }

    private function createOutlet(WjmCatalog $catalog, WjmCatalogSeedReport $report): Outlet
    {
        if (! LicenseGate::canCreateOutlet()) {
            throw new \DomainException('Lisensi tidak mengizinkan membuat outlet baru di mode '.strtoupper($report->mode).'.');
        }

        // Kode usulan katalog lebih dulu, lalu WJ[A-Z0-9] (research D5). Kode outlet unik lintas mode.
        $proposed = $catalog->outlet()['codes'];
        $taken = Outlet::withTrashed()->withoutGlobalScopes()->pluck('code')->map(fn ($code) => strtoupper((string) $code))->flip();
        $code = collect([...$proposed, ...array_map(fn ($c) => 'WJ'.$c, [...range('A', 'Z'), ...range(0, 9)])])
            ->first(fn (string $candidate) => ! $taken->has($candidate));
        if ($code === null) {
            throw new \DomainException('Tidak ada kode outlet yang bebas ('.implode(', ', $proposed).', WJA–WJ9).');
        }

        $outlet = Outlet::create(['code' => $code, 'name' => $catalog->outlet()['name'], 'is_active' => true]);
        $report->outlet = ['code' => $outlet->code, 'name' => $outlet->name, 'created' => true];

        return $outlet;
    }

    /**
     * 069 research D6: stasiun dikenali menurut PERAN (Bar/Dapur) lewat daftar nama katalog, bukan nama tetap,
     * karena stasiun yang sudah ada boleh sudah diganti nama pemilik ("Dapur Minuman"/"Dapur Makanan"). Outlet yang
     * belum punya stasiun sama sekali mendapat dua stasiun bernama nama pertama tiap peran. Outlet yang sudah punya
     * stasiun tetapi tidak mengenali sebuah peran DITOLAK, karena membuat stasiun tambahan akan menggandakan dapur.
     *
     * @return array<string, Station> peran (Bar/Dapur) → stasiun di outlet tujuan
     */
    private function ensureStations(WjmCatalog $catalog, Outlet $outlet, WjmCatalogSeedReport $report): array
    {
        $present = Station::withoutGlobalScopes()->where('outlet_id', $outlet->id)->orderBy('id')->get();
        $stations = [];
        $unmatched = [];

        $roles = $catalog->stations();
        foreach (array_keys($roles) as $order => $role) {
            $names = $roles[$role];
            $match = null;
            foreach ($names as $name) {
                $match = $present->first(fn (Station $s) => $s->is_active && mb_strtolower(trim($s->name)) === mb_strtolower(trim($name)));
                if ($match !== null) {
                    break;
                }
            }

            if ($match !== null) {
                $report->existing('stations');
                $stations[$role] = $match;
            } elseif ($present->isEmpty()) {
                $station = new Station(['name' => $names[0], 'display_order' => $order, 'is_active' => true]);
                // research D6 (068): outlet_id tidak pernah fillable. Perintah Artisan berjalan "guarded" (berbeda
                // dengan db:seed), jadi diisi eksplisit; BelongsToOutlet menghormati nilai yang sudah di-set.
                $station->outlet_id = $outlet->id;
                $station->save();
                $report->created('stations');
                $stations[$role] = $station;
            } else {
                $unmatched[] = $role;
            }
        }

        if ($unmatched !== []) {
            $list = $present->map(fn (Station $s) => $s->name.($s->is_active ? '' : ' (nonaktif)'))->implode(', ');
            throw new \DomainException(sprintf(
                'Stasiun untuk peran %s tidak dikenali di outlet %s. Stasiun yang ada: %s. Tambahkan namanya ke baris **Stasiun** di tabel pemetaan.',
                implode(' dan ', $unmatched), $outlet->code, $list,
            ));
        }

        return $stations;
    }

    /**
     * FR-015: kategori bernama sama di mode ini dipakai ulang apa adanya (tidak diubah); hanya yang belum ada
     * yang dibuat. Bila ada nama kembar, yang paling lama (id terkecil) yang dipakai.
     *
     * @param  array<string, Station>  $stations
     * @return array<string, Category> nama → kategori
     */
    private function ensureCategories(WjmCatalog $catalog, array $stations, WjmCatalogSeedReport $report): array
    {
        $existing = Category::query()->orderByDesc('id')->get()->keyBy('name');
        $categories = [];
        foreach ($catalog->categories() as $category) {
            if ($existing->has($category['name'])) {
                $categories[$category['name']] = $existing->get($category['name']);
                $report->existing('categories');

                continue;
            }
            $categories[$category['name']] = Category::create([
                'code' => $this->codes->freeCategoryCode($category['name']),
                'name' => $category['name'],
                'display_order' => $category['order'],
                'station_id' => $stations[$category['station']]->id,
                'is_active' => true,
            ]);
            $report->created('categories');
        }

        return $categories;
    }

    /**
     * FR-016 / research D7: grup yang sudah ada tidak pernah diubah. Grup bernama sama dengan opsi aktif yang
     * identik dipakai ulang (jalan ulang); bila opsinya berbeda (mis. grup contoh "Rasa" Cokelat/Keju di DEMO),
     * grup katalog dibuat dengan nama pembeda pertama yang bebas: "<nama> (WJM)", "<nama> (WJM 2)", ….
     *
     * @return array<string, array{group: ModifierGroup, new: bool}> nama grup katalog → grup di DB
     */
    private function ensureOptionGroups(WjmCatalog $catalog, WjmCatalogSeedReport $report): array
    {
        $groups = [];
        foreach ($catalog->optionGroups() as $order => $group) {
            $wanted = collect($group['options'])->sort()->values()->all();

            for ($n = 1; ; $n++) {
                $name = match ($n) {
                    1 => $group['name'],
                    2 => "{$group['name']} (WJM)",
                    default => "{$group['name']} (WJM ".($n - 1).')',
                };
                $sameName = ModifierGroup::query()->where('name', $name)
                    ->with(['options' => fn ($q) => $q->where('is_active', true)])->get();

                $identical = $sameName->first(fn (ModifierGroup $g) => $g->options->pluck('name')->sort()->values()->all() === $wanted);
                if ($identical !== null) {
                    $groups[$group['name']] = ['group' => $identical, 'new' => false];
                    $report->existing('option_groups');

                    break;
                }
                if ($sameName->isEmpty()) {
                    $groups[$group['name']] = ['group' => $this->createGroup($group, $name, $order), 'new' => true];
                    $report->created('option_groups');
                    if ($n > 1) {
                        $report->renamedGroups[] = "{$group['name']} → {$name}";
                    }

                    break;
                }
            }
        }

        return $groups;
    }

    /** @param  array<string, mixed>  $group */
    private function createGroup(array $group, string $name, int $order): ModifierGroup
    {
        return $this->groups->create([
            'name' => $name,
            'selection_type' => ModifierGroup::SINGLE,
            'is_required' => $group['required'],
            'display_order' => $order,
            'is_active' => true,
            'options' => array_map(
                fn (string $option, int $i) => ['name' => $option, 'price_delta' => 0, 'display_order' => $i, 'is_active' => true],
                $group['options'],
                array_keys($group['options']),
            ),
        ]);
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, Station>  $stations  peran (Bar/Dapur) → stasiun outlet tujuan
     * @return array{0: array<string, Product>, 1: list<int>} [nama → produk yang DIBUAT jalan ini, id semua item katalog]
     */
    private function createItems(WjmCatalog $catalog, Outlet $outlet, array $categories, array $stations, WjmCatalogSeedReport $report, bool $writePhotos): array
    {
        // FR-015/016 / research D7 (068) dan 069 research D7: item dianggap sudah ada bila OUTLET TUJUAN memiliki item
        // bernama sama di kategori bernama sama, termasuk yang sudah dihapus pemilik (tidak dihidupkan lagi). Item
        // outlet lain tidak pernah cocok: dua outlet boleh punya "Nasi Putih" sendiri-sendiri. Yang cocok dilewati utuh:
        // varian, foto, tautan grup, dan outlet penjualnya tidak disentuh.
        $existing = Product::withTrashed()->where('home_outlet_id', $outlet->id)->withCount('variants')
            ->with(['category' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('id')->get()->keyBy(fn (Product $p) => $p->name."\0".$p->category?->name);
        $roleByCategory = array_column($catalog->categories(), 'station', 'name');

        $created = [];
        $catalogProductIds = [];
        foreach ($catalog->items() as $item) {
            $match = $existing->get($item['name']."\0".$item['category']);
            if ($match !== null) {
                $report->existing('items');
                $report->existing('variants', (int) $match->variants_count);
                if ($match->image_path !== null) {
                    $report->existing('photos');
                }
                if ($match->trashed()) {
                    $report->deletedByOwner[] = $match->name;
                } else {
                    $catalogProductIds[] = $match->id;
                }

                continue;
            }

            $category = $categories[$item['category']];
            [$segment, $prefix] = $this->codes->firstFreeCodePrefix($outlet->code, $category->code, $item['name']);

            $imagePath = null;
            if ($item['photo'] !== null) {
                if ($writePhotos) {
                    $imagePath = $this->images->storeLocalFile($catalog->photoPath($item['photo']), 'products');
                    $this->storedPhotos[] = $imagePath;
                }
                $report->created('photos');
            }

            // 069 research D5: stasiun diset per item (peran kategori di katalog → stasiun outlet tujuan), karena
            // kategori boleh dipakai bersama dua outlet dan menunjuk stasiun outlet yang lebih dulu membuatnya;
            // KitchenService mencoba stasiun produk lebih dulu, baru stasiun kategori.
            $product = Product::create([
                'home_outlet_id' => $outlet->id,
                'category_id' => $category->id,
                'station_id' => $stations[$roleByCategory[$item['category']]]->id,
                'code_prefix' => $prefix,
                'product_segment' => $segment,
                'name' => $item['name'],
                'image_path' => $imagePath,
                'is_active' => true,
            ]);

            $initialStock = $catalog->initialStock();
            foreach ($item['variants'] as $variant) {
                // sell_price diisi saat dibuat seperti ProductController/impor master; hook ProductVariant::created
                // mencatat riwayat lewat PriceService. Tanpa initial_stock, menu warkop dibuat saat dipesan dan
                // tidak melacak stok (FR-013). Dengan initial_stock (2026-10-25, permintaan pengembang), stok
                // dilacak dan saldo awal masuk lewat StockService, satu-satunya penulis stok, di outlet tujuan.
                // 069: hook pembuatan varian membukukan saldo nol di outlet BAWAAN (tanpa pengguna = outlet aktif pertama
                // di mode itu). Untuk outlet kedua itu berarti baris stok milik outlet pertama, yang tampak di daftar
                // "stok menipis"-nya. Hook dilewati dan saldo nol dibuat di outlet tujuan, seperti pembuatan lewat layar.
                $newVariant = OutletStockSync::internally(fn () => $product->variants()->create([
                    'sku' => $this->codes->nextVariantSku($product),
                    'variant_name' => $variant['name'],
                    'sell_price' => $variant['price'],
                    'cost_price' => 0,
                    'track_stock' => $initialStock !== null,
                    'is_active' => true,
                ]));
                VariantOutletStock::query()->insertOrIgnore([
                    'variant_id' => $newVariant->id, 'outlet_id' => $outlet->id, 'current_stock' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($initialStock > 0) {
                    $this->stock->applyMovement($newVariant, outletId: $outlet->id, type: 'initial', qtyChange: $initialStock, reason: 'Stok awal katalog WJM');
                    $report->created('stock');
                }
                $report->created('variants');
            }

            // FR-004: hanya outlet tujuan yang menjual; outlet lain di mode yang sama tidak.
            $this->menu->setCarried($product, [$outlet->id]);
            $report->created('items');
            $created[$item['name']] = $product;
            $catalogProductIds[] = $product->id;
        }

        return [$created, $catalogProductIds];
    }

    /**
     * 068 FR-017 / 069 research D9: item di luar katalog yang dijual outlet tujuan ditarik dari menu outlet itu, TIDAK
     * dihapus, supaya POS hanya menampilkan katalog ini sementara riwayat order dan penjualan di outlet pemiliknya
     * tetap utuh. Dijalankan hanya bila katalog memintanya per mode (`withdraw_others`): katalog WJM 3 hanya di DEMO
     * (di LIVE item buatan pemilik tidak boleh hilang dari menu), katalog WJM 2 di keduanya. Yang ditulis hanya
     * penanda "dijual" untuk outlet tujuan, tidak pernah untuk outlet lain.
     *
     * BUG YANG DITEMUKAN & DIPERBAIKI (2026-10-25, halaman 2 katalog WJM 2): di LIVE, item yang dibuat pemilik
     * sendiri di outlet tujuan (home outlet = outlet tujuan) ikut ditarik, padahal alasan penarikan hanya item
     * MILIK outlet lain yang terbawa ke menu outlet ini. Pemilik sudah membuat empat item WJM 2 lewat layar
     * manajemen; jalan ulang katalog WJM 2 menyembunyikannya dari POS. Kini hanya item outlet lain yang ditarik
     * di LIVE. DEMO tetap menarik semuanya (item contoh lama milik outlet tujuan ikut disingkirkan).
     *
     * @param  list<int>  $catalogProductIds
     */
    private function withdrawOtherItems(Outlet $outlet, array $catalogProductIds, WjmCatalogSeedReport $report): void
    {
        foreach (Product::query()->whereNotIn('id', $catalogProductIds)->orderBy('id')->get() as $product) {
            if ($report->mode === 'live' && (int) $product->home_outlet_id === (int) $outlet->id) {
                continue;
            }
            if ($this->menu->isCarried($product->id, $outlet->id)) {
                $this->menu->setCarried($product, [], [$outlet->id]);
                $report->withdrawn[] = $product->name;
            }
        }
    }

    /**
     * Tautan hanya untuk grup yang BARU dibuat, ke item yang dibuat jalan ini: grup dan item yang sudah ada
     * tidak diubah (FR-016).
     *
     * @param  array<string, array{group: ModifierGroup, new: bool}>  $groups
     * @param  array<string, Product>  $created
     */
    private function attachNewGroups(WjmCatalog $catalog, array $groups, array $created): void
    {
        foreach ($catalog->optionGroups() as $group) {
            $target = $groups[$group['name']] ?? null;
            $productIds = array_values(array_map(
                fn (string $name) => $created[$name]->id,
                array_filter($group['attach_to'], fn (string $name) => isset($created[$name])),
            ));
            if ($target !== null && $target['new'] && $productIds !== []) {
                $this->groups->setAttachments($target['group'], $productIds, []);
            }
        }
    }

    /** FR-019 / Konstitusi IV: satu entri audit per mode, di dalam transaksi yang sama dengan pengisiannya. */
    private function logRun(WjmCatalog $catalog, Outlet $outlet, WjmCatalogSeedReport $report): void
    {
        $this->activityLogger->log(
            userId: null,
            action: 'catalog_seeded',
            entityType: 'Outlet',
            entityId: $outlet->id,
            description: "Katalog WJM (Odoo) diisi ke outlet {$outlet->code} lewat app:seed-wjm-catalog/WjmCatalogSeeder.",
            newValues: [
                'mode' => $report->mode,
                'counts' => $report->counts,
                'excluded' => count($catalog->excluded()),
                'withdrawn' => count($report->withdrawn),
                'catalog' => basename(dirname($catalog->path())),
                'catalog_exported_on' => $catalog->source()['exported_on'] ?? null,
                'approved_on' => $catalog->approval()['approved_on'] ?? null,
            ],
        );
    }

    /** Transaksi sudah di-rollBack; berkas foto tidak ikut transaksi, jadi dihapus di sini (D8). */
    private function discardRun(WjmCatalogSeedReport $report): void
    {
        foreach ($this->storedPhotos as $path) {
            $this->images->delete($path);
        }
        $this->storedPhotos = [];
        $report->resetRun();
    }
}
