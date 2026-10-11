<?php

namespace App\Services;

use App\Models\Category;
use App\Models\PriceChangeBatch;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\MenuSettings;
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
 */
class BulkPriceService
{
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
            $old = round((float) $v->sell_price, 2);
            $new = $this->compute($old, $in['type'], $in['value'], $in['rounding']);

            if ($new <= 0) {
                $errors[] = __('products.bulk_price_not_positive', ['name' => $v->product->name.' — '.$v->variant_name]);

                continue;
            }
            if ($new === $old) {
                $warnings[] = __('products.bulk_price_unchanged', ['name' => $v->product->name.' — '.$v->variant_name]);

                continue;
            }

            $items[] = [
                'product_id' => $v->product_id, 'product' => $v->product->name, 'variant_id' => $v->id, 'variant' => $v->variant_name,
                'old_price' => number_format($old, 2, '.', ''), 'new_price' => number_format($new, 2, '.', ''),
            ];
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
                'scope' => ['category_ids' => $in['category_ids']], 'type' => $in['type'], 'value' => $in['value'],
                'rounding' => $in['rounding'], 'effective_from' => $in['effective_from'], 'created_by' => $by->id,
            ]);

            $out = ['batch_id' => $batch->id, 'applied' => 0, 'pending' => 0, 'awaiting_approval' => 0];
            $variants = ProductVariant::query()->with('product')->lockForUpdate()->whereIn('id', array_column($preview['items'], 'variant_id'))->get()->keyBy('id');

            foreach ($preview['items'] as $item) {
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

    /** @return array{category_ids: list<int>, type: string, value: float, rounding: string, effective_from: string} */
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

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['category_ids' => $ids, 'type' => $type, 'value' => (float) $value, 'rounding' => $rounding, 'effective_from' => $effective];
    }

    private function compute(float $old, string $type, float $value, string $rounding): float
    {
        $raw = $type === 'percent' ? $old * (1 + $value / 100) : $old + $value;

        return $rounding === '500' ? round($raw / 500) * 500.0 : round($raw, 2);
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
