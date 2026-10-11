<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ItemAvailabilityMark;
use App\Models\MenuChange;
use App\Models\MenuRelease;
use App\Models\Material;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use App\Support\MenuSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 043-products-page-redesign — daftar katalog (grid/tabel), jumlah tab, dan angka header dalam JUMLAH QUERY
 * TETAP (relasi dimuat per halaman, bukan per kartu; research.md D14, FR-023).
 *
 * Definisi (semua dari data yang sama, jadi angka header = baris di bawahnya, SC-001):
 *  - tab `menu` = produk bukan-ritel, `retail` = produk ritel; bahan menurut `kind`; modifier = grup modifier;
 *  - status turunan: draft (belum terbit), active, discontinued (lihat Product::status);
 *  - kualitas data (hanya item menu AKTIF): `no_station` (stasiun produk DAN kategorinya kosong), `no_price`
 *    (tak ada varian ber-harga > 0), `no_cost` (tak ada varian ber-biaya > 0);
 *  - biaya (food cost %) hanya untuk pemanggil yang boleh melihat biaya (menu products/reports).
 */
class ProductCatalogQuery
{
    public const QUALITY_KINDS = ['no_station', 'no_price', 'no_cost'];

    private const SORTS = ['name', '-name', 'sku', '-sku', 'price', '-price', 'status', '-status'];

    /** @param array<string,mixed> $f filter tervalidasi: tab, q, category_id[], status[], quality, sort, page, per_page */
    public function page(array $f, User $user): LengthAwarePaginator
    {
        $query = $this->base($f);
        $this->applySort($query, (string) ($f['sort'] ?? 'name'));

        $paginator = $query->paginate((int) ($f['per_page'] ?? 15), ['products.*'], 'page', (int) ($f['page'] ?? 1));
        $paginator->setCollection($this->present($paginator->getCollection(), $user));

        return $paginator;
    }

    /** Jumlah per tab, tidak bergantung pada filter. @return array<string,int> */
    public function counts(): array
    {
        $products = Product::query()->selectRaw('is_retail, COUNT(*) as n')->groupBy('is_retail')->pluck('n', 'is_retail');
        $materials = Material::query()->selectRaw('kind, COUNT(*) as n')->groupBy('kind')->pluck('n', 'kind');

        return [
            'menu' => (int) ($products[0] ?? 0),
            'retail' => (int) ($products[1] ?? 0),
            'material' => (int) ($materials['ingredient'] ?? 0),
            'semi_finished' => (int) ($materials['semi_finished'] ?? 0),
            'packaging' => (int) ($materials['packaging'] ?? 0),
            'modifier' => ModifierGroup::query()->count(),
        ];
    }

    /** Angka header (GET /products/summary). */
    public function summary(): array
    {
        $byStatus = Product::query()->where('is_retail', false)
            ->selectRaw("SUM(discontinued_at IS NULL AND published_at IS NOT NULL) as active, SUM(published_at IS NULL AND discontinued_at IS NULL) as draft, SUM(discontinued_at IS NOT NULL) as discontinued, COUNT(*) as total")
            ->first();

        $marks = ItemAvailabilityMark::query()->active()->with('product:id,name')->orderBy('marked_at')->get();

        $quality = null;
        foreach (self::QUALITY_KINDS as $kind) {
            $q = $this->qualityQuery($kind);
            $count = (clone $q)->count();
            if ($count > 0) {
                $quality = ['count' => $count, 'examples' => $q->orderBy('name')->limit(2)->pluck('name')->all(), 'kind' => $kind];
                break;
            }
        }

        $releaseOn = MenuSettings::releaseEnabled();
        $unreleased = null;
        if ($releaseOn) {
            $changes = MenuChange::query()->unreleased()->orderBy('id')->get(['id', 'kind', 'payload']);
            $last = MenuRelease::query()->where('status', MenuRelease::PUBLISHED)->orderByDesc('version')->first();
            $unreleased = [
                'count' => $changes->count(),
                'summary' => $changes->take(3)->map(fn (MenuChange $c) => $this->describeChange($c))->values()->all(),
                'active_version' => $last?->version,
                'active_since' => $last?->published_at?->toDateString(),
            ];
        }

        return [
            'computed_at' => now()->toIso8601String(),
            'release_enabled' => $releaseOn,
            'active' => [
                'count' => (int) ($byStatus->active ?? 0), 'total' => (int) ($byStatus->total ?? 0),
                'draft' => (int) ($byStatus->draft ?? 0), 'discontinued' => (int) ($byStatus->discontinued ?? 0),
            ],
            'sold_out_today' => [
                'count' => $marks->pluck('product_id')->unique()->count(),
                'examples' => $marks->take(2)->map(fn ($m) => ['product' => $m->product?->name, 'outlet' => null])->values()->all(),
            ],
            'unreleased' => $unreleased,
            'quality' => $quality ?? ['count' => 0, 'examples' => [], 'kind' => null],
        ];
    }

    // --- dasar query -----------------------------------------------------------------------------

    private function base(array $f): Builder
    {
        $q = Product::query()
            ->with([
                'category:id,name,station_id', 'category.station:id,name', 'station:id,name',
                'variants:id,product_id,sku,variant_name,sell_price,cost_price,is_active',
                'modifierGroups:id,name', 'category.modifierGroups:id,name',
                'availabilityMarks' => fn ($m) => $m->active()->with('reason:id,code,label_id,label_en'),
            ]);

        $tab = $f['tab'] ?? 'menu';
        $q->where('products.is_retail', $tab === 'retail');

        if (($term = trim((string) ($f['q'] ?? $f['search'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w
                ->where('products.name', 'like', $like)
                ->orWhere('products.short_name', 'like', $like)
                ->orWhere('products.code_prefix', 'like', $like)
                ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like)));
        }

        if (! empty($f['category_id'])) {
            $q->whereIn('products.category_id', $this->categoryIdsWithChildren((array) $f['category_id']));
        }

        if (! empty($f['status'])) {
            $statuses = (array) $f['status'];
            $q->where(function ($w) use ($statuses) {
                foreach ($statuses as $s) {
                    $w->orWhere(fn ($x) => match ($s) {
                        'draft' => $x->whereNull('products.published_at')->whereNull('products.discontinued_at'),
                        'discontinued' => $x->whereNotNull('products.discontinued_at'),
                        default => $x->whereNotNull('products.published_at')->whereNull('products.discontinued_at'),
                    });
                }
            });
        }

        // "86 hari ini" (angka header) menyaring daftar ke item yang tanda 86-nya berlaku.
        if (($f['availability'] ?? null) === 'sold_out') {
            $q->whereHas('availabilityMarks', fn ($m) => $m->active());
        }

        if (! empty($f['quality']) && in_array($f['quality'], self::QUALITY_KINDS, true)) {
            $this->constrainQuality($q, $f['quality']);
        }

        return $q;
    }

    private function applySort(Builder $q, string $sort): void
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'name';
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';

        match (ltrim($sort, '-')) {
            'sku' => $q->orderBy(DB::raw('(SELECT MIN(v.sku) FROM product_variants v WHERE v.product_id = products.id AND v.deleted_at IS NULL)'), $dir),
            'price' => $q->orderBy(DB::raw('(SELECT MIN(v.sell_price) FROM product_variants v WHERE v.product_id = products.id AND v.deleted_at IS NULL AND v.is_active = 1)'), $dir),
            'status' => $q->orderByRaw('CASE WHEN products.discontinued_at IS NOT NULL THEN 2 WHEN products.published_at IS NULL THEN 0 ELSE 1 END '.$dir),
            default => $q->orderBy('products.name', $dir),
        };
        $q->orderBy('products.id');
    }

    /** @param list<int|string> $ids @return list<int> */
    private function categoryIdsWithChildren(array $ids): array
    {
        $all = array_map('intval', $ids);
        $frontier = $all;
        while ($frontier !== []) {
            $children = Category::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($i) => (int) $i)->diff($all)->values()->all();
            $all = array_merge($all, $children);
            $frontier = $children;
        }

        return $all;
    }

    // --- kualitas data -----------------------------------------------------------------------------

    private function qualityQuery(string $kind): Builder
    {
        $q = Product::query()->where('products.is_retail', false)->whereNotNull('products.published_at')->whereNull('products.discontinued_at');
        $this->constrainQuality($q, $kind);

        return $q;
    }

    private function constrainQuality(Builder $q, string $kind): void
    {
        match ($kind) {
            'no_station' => $q->whereNull('products.station_id')->whereHas('category', fn ($c) => $c->whereNull('station_id')),
            'no_price' => $q->whereDoesntHave('variants', fn ($v) => $v->where('sell_price', '>', 0)),
            default => $q->whereDoesntHave('variants', fn ($v) => $v->where('cost_price', '>', 0)),
        };
    }

    // --- bentuk baris -----------------------------------------------------------------------------

    /** @param Collection<int,Product> $products */
    private function present(Collection $products, User $user): Collection
    {
        $mayCost = $user->canAccessMenu('products') || $user->canAccessMenu('reports');

        return $products->map(function (Product $p) use ($mayCost) {
            $variants = $p->variants;
            $active = $variants->where('is_active', true);
            $priced = $active->isNotEmpty() ? $active : $variants;
            $first = $variants->sortBy('id')->first();
            $station = $p->station ?? $p->category?->station;
            $mark = $p->availabilityMarks->first();

            $names = $active->pluck('variant_name')->reject(fn ($n) => $n === null || strcasecmp((string) $n, 'Standard') === 0 || strcasecmp((string) $n, 'Reguler') === 0)->values();
            $groups = $p->modifierGroups->merge($p->category?->modifierGroups ?? collect())->unique('id')->pluck('name')->values();

            $flags = [];
            if ($p->station_id === null && $p->category?->station_id === null) {
                $flags[] = 'no_station';
            }
            if (! $variants->contains(fn ($v) => (float) $v->sell_price > 0)) {
                $flags[] = 'no_price';
            }
            if (! $variants->contains(fn ($v) => (float) $v->cost_price > 0)) {
                $flags[] = 'no_cost';
            }

            $head = $priced->sortBy('id')->first();
            $foodCost = null;
            if ($mayCost && $head && (float) $head->cost_price > 0 && (float) $head->sell_price > 0) {
                $foodCost = round((float) $head->cost_price / (float) $head->sell_price * 100, 1);
            }

            return [
                'id' => $p->id,
                'code' => $first?->sku ?? $p->code_prefix,
                'name' => $p->name,
                'short_name' => $p->short_name,
                'image_url' => $p->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($p->image_path) : null,
                'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name] : null,
                'station' => $station ? ['id' => $station->id, 'name' => $station->name, 'source' => $p->station ? 'product' : 'category'] : null,
                'status' => $p->status,
                'is_retail' => (bool) $p->is_retail,
                'base_price' => $this->money($priced->min('sell_price')),
                'price_range' => ['min' => $this->money($priced->min('sell_price')), 'max' => $this->money($priced->max('sell_price'))],
                'variants_summary' => $names->isEmpty() ? __('products.no_variants') : $names->implode(' / '),
                'variant_count' => $variants->count(),
                'modifier_summary' => $groups->take(3)->all(),
                'availability' => [[
                    'outlet_id' => null, 'outlet_code' => null,
                    'state' => $mark ? 'sold_out' : 'available',
                    'reason' => $mark?->reason?->label(),
                ]],
                'food_cost_pct' => $foodCost,
                'quality' => $flags,
            ];
        })->values();
    }

    private function money(mixed $v): ?string
    {
        return $v === null ? null : number_format((float) $v, 2, '.', '');
    }

    private function describeChange(MenuChange $c): string
    {
        return match ($c->kind) {
            MenuChange::KIND_PRICE => __('products.change_price', ['name' => $c->payload['product_name'] ?? $c->payload['variant_name'] ?? '']),
            MenuChange::KIND_ITEM_PUBLISH => __('products.change_publish', ['name' => $c->payload['product_name'] ?? '']),
            MenuChange::KIND_ITEM_DISCONTINUE => __('products.change_discontinue', ['name' => $c->payload['product_name'] ?? '']),
            MenuChange::KIND_ITEM_REACTIVATE => __('products.change_reactivate', ['name' => $c->payload['product_name'] ?? '']),
            default => __('products.change_modifier', ['name' => $c->payload['product_name'] ?? '']),
        };
    }
}
