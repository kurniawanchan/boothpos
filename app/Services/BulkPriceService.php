<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\PriceChangeBatch;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\MenuSettings;
use App\Support\OutletContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 043-products-page-redesign — ubah harga massal: cakupan kategori → pratinjau → terapkan semua-atau-tidak-sama-
 * sekali (research.md D8). Server SELALU menghitung ulang dari masukan (pratinjau klien tidak pernah dipercaya);
 * setiap harga baru ditulis lewat PriceService (riwayat, mode rilis, persetujuan).
 *
 * Hitungan: persen → harga × (1 + nilai/100); nominal → harga + nilai (boleh negatif = turun); lalu pembulatan
 * "terdekat Rp 500" = round(x / 500) × 500 (setengah ke atas). Varian yang harganya tidak berubah dilewati.
 *
 * 045 Stage 3 — `price_scope`: `base` (bawaan, perilaku lama: harga dasar), `outlets` (+ `outlet_ids[]`) atau
 * `all_outlets` (semua outlet yang boleh dilihat pemanggil). Untuk cakupan outlet, harga LAMA adalah harga efektif
 * di outlet itu (harga khusus, jika ada, selain itu dasar) dan hasilnya ditulis sebagai harga khusus outlet lewat
 * PriceService::setOutletPrice. Item yang tidak dijual di sebuah outlet dilewati untuk outlet itu. Dengan rilis
 * menu aktif tiap harga outlet menjadi `menu_change` (dan persetujuan bila melewati ambang), seperti harga dasar.
 */
class BulkPriceService
{
    /** @var array<string, Collection<int, Outlet>> */
    private array $outletCache = [];

    public function __construct(private PriceService $prices) {}

    /**
     * @param  array{category_ids?: list<int>, type?: string, value?: mixed, rounding?: string|null, effective_from?: string|null}  $in
     * @return array{items: list<array<string,mixed>>, count: int, warnings: list<string>}
     *
     * @throws ValidationException
     */
    public function preview(array $in): array
    {
        $in = $this->normalise($in);
        $variants = $this->variantsFor($in['category_ids']);

        $items = [];
        $warnings = [];
        $errors = [];

        foreach ($variants as $v) {
            foreach ($this->targetsFor($v, $in) as $outlet) {
                $old = $outlet === null ? round((float) $v->sell_price, 2) : $this->prices->priceFor($v, $outlet->id);
                $new = $this->compute($old, $in['type'], $in['value'], $in['rounding']);
                $label = $v->product->name.' — '.$v->variant_name.($outlet ? ' @ '.$outlet->code : '');

                if ($new <= 0) {
                    $errors[] = __('products.bulk_price_not_positive', ['name' => $label]);

                    continue;
                }
                if ($new === $old) {
                    $warnings[] = __('products.bulk_price_unchanged', ['name' => $label]);

                    continue;
                }

                $items[] = [
                    'product_id' => $v->product_id, 'product' => $v->product->name, 'variant_id' => $v->id, 'variant' => $v->variant_name,
                    'outlet_id' => $outlet?->id, 'outlet_code' => $outlet?->code,
                    'old_price' => number_format($old, 2, '.', ''), 'new_price' => number_format($new, 2, '.', ''),
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['value' => $errors]);
        }

        return ['items' => $items, 'count' => count($items), 'warnings' => $warnings];
    }

    /**
     * @return array{batch_id: int, applied: int, pending: int, awaiting_approval: int}
     *
     * @throws ValidationException
     */
    public function apply(array $in, User $by): array
    {
        $in = $this->normalise($in);
        $preview = $this->preview($in);

        if ($preview['count'] === 0) {
            throw ValidationException::withMessages(['category_ids' => __('products.bulk_price_nothing_matches')]);
        }

        return DB::transaction(function () use ($in, $preview, $by) {
            $batch = PriceChangeBatch::create([
                'scope' => ['category_ids' => $in['category_ids'], 'price_scope' => $in['price_scope'], 'outlet_ids' => $in['outlet_ids']], 'type' => $in['type'], 'value' => $in['value'],
                'rounding' => $in['rounding'], 'effective_from' => $in['effective_from'], 'created_by' => $by->id,
            ]);

            $out = ['batch_id' => $batch->id, 'applied' => 0, 'pending' => 0, 'awaiting_approval' => 0];
            $variants = ProductVariant::query()->with('product')->lockForUpdate()->whereIn('id', array_column($preview['items'], 'variant_id'))->get()->keyBy('id');

            foreach ($preview['items'] as $item) {
                if ($item['outlet_id'] !== null) {
                    $r = $this->prices->setOutletPrice($variants[$item['variant_id']], (int) $item['outlet_id'], $item['new_price'], $by, 'bulk', null, ['batch_id' => $batch->id]);
                    if ($r['applied']) {
                        $out['applied']++;
                    } elseif ($r['requires_approval']) {
                        $out['awaiting_approval']++;
                    } elseif ($r['pending']) {
                        $out['pending']++;
                    }

                    continue;
                }

                $r = $this->prices->setBasePrice($variants[$item['variant_id']], $item['new_price'], $by, 'bulk', [
                    'effective_from' => $in['effective_from'], 'batch_id' => $batch->id,
                ]);

                if ($r['applied']) {
                    $out['applied']++;
                } elseif ($r['requires_approval']) {
                    $out['awaiting_approval']++;
                } elseif ($r['pending']) {
                    $out['pending']++;
                }
            }

            return $out;
        });
    }

    // --- internal -----------------------------------------------------------------------------------

    /** @return array{category_ids: list<int>, type: string, value: float, rounding: string, effective_from: string, price_scope: string, outlet_ids: list<int>} */
    private function normalise(array $in): array
    {
        $errors = [];

        $ids = array_values(array_unique(array_map('intval', (array) ($in['category_ids'] ?? []))));
        if ($ids === []) {
            $errors['category_ids'] = __('products.bulk_price_scope_required');
        }

        $type = $in['type'] ?? null;
        if (! in_array($type, ['percent', 'amount'], true)) {
            $errors['type'] = __('products.bulk_price_type_invalid');
        }

        $value = $in['value'] ?? null;
        if (! is_numeric($value) || (float) $value === 0.0) {
            $errors['value'] = __('products.bulk_price_value_required');
        }

        $rounding = $in['rounding'] ?? MenuSettings::roundingDefault();
        if (! in_array($rounding, ['none', '500'], true)) {
            $errors['rounding'] = __('products.bulk_price_rounding_invalid');
        }

        $effective = $in['effective_from'] ?? null;
        if (! is_string($effective) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective) || $effective < BusinessDay::today()) {
            $errors['effective_from'] = __('products.bulk_price_date_invalid');
        }

        $scope = $in['price_scope'] ?? 'base';
        $outletIds = [];
        if (! in_array($scope, ['base', 'outlets', 'all_outlets'], true)) {
            $errors['price_scope'] = __('products.bulk_price_scope_invalid');
        } elseif ($scope !== 'base') {
            $permitted = app(OutletContext::class)->permittedIds();
            $outletIds = $scope === 'all_outlets'
                ? array_values($permitted)
                : array_values(array_unique(array_map('intval', (array) ($in['outlet_ids'] ?? []))));
            if ($outletIds === []) {
                $errors['outlet_ids'] = __('products.bulk_price_outlets_required');
            } elseif (array_diff($outletIds, $permitted) !== []) {
                $errors['outlet_ids'] = __('outlets.forbidden');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['category_ids' => $ids, 'type' => $type, 'value' => (float) $value, 'rounding' => $rounding, 'effective_from' => $effective, 'price_scope' => $scope, 'outlet_ids' => $outletIds];
    }

    private function compute(float $old, string $type, float $value, string $rounding): float
    {
        $raw = $type === 'percent' ? $old * (1 + $value / 100) : $old + $value;

        return $rounding === '500' ? round($raw / 500) * 500.0 : round($raw, 2);
    }

    /**
     * Sasaran harga sebuah varian: `[null]` = harga dasar; selain itu outlet-outlet terpilih yang MENJUAL itemnya.
     *
     * @return list<Outlet|null>
     */
    private function targetsFor(ProductVariant $v, array $in): array
    {
        if ($in['price_scope'] === 'base') {
            return [null];
        }

        $menu = app(OutletMenuService::class);

        return $this->outlets($in['outlet_ids'])->filter(fn (Outlet $o) => $menu->isCarried((int) $v->product_id, $o->id))->values()->all();
    }

    /** @return Collection<int, Outlet> */
    private function outlets(array $ids): Collection
    {
        return $this->outletCache[implode(',', $ids)] ??= Outlet::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'code', 'name']);
    }

    /** Varian aktif milik item aktif (bukan diskontinu/draft) di kategori itu dan turunannya. */
    private function variantsFor(array $categoryIds): Collection
    {
        $all = $categoryIds;
        $frontier = $categoryIds;
        while ($frontier !== []) {
            $children = Category::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($i) => (int) $i)->diff($all)->values()->all();
            $all = array_merge($all, $children);
            $frontier = $children;
        }

        return ProductVariant::query()
            ->with('product')
            ->where('is_active', true)
            ->whereHas('product', fn ($p) => $p->whereIn('category_id', $all)->where('is_active', true)->whereNull('discontinued_at'))
            ->orderBy('product_id')->orderBy('id')
            ->get();
    }
}
