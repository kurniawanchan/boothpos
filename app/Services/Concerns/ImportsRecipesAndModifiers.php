<?php

namespace App\Services\Concerns;

use App\Exceptions\BomRuleException;
use App\Exceptions\InputRejectedException;
use App\Exceptions\MasterDataImportRowException;
use App\Models\Category;
use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MaterialStockService;
use App\Services\ModifierGroupService;
use App\Services\RecipeService;
use App\Support\BusinessDay;
use App\Support\MasterDataSheets;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 090 — sheet impor untuk stok bahan per outlet, resep, grup/pilihan modifier, bahan pilihan, dan tautan modifier ke
 * item. Dipisah dari `MasterDataImportService` (sudah >2500 baris) tetapi memakai pembantu pribadinya (`addError`,
 * `filled`, `stringValue`, `parseBool`, `parseInt`, `outletRefError`, `assertOutletPermitted`, `$counts`).
 *
 * Aturan tulis TIDAK diduplikasi: resep lewat `RecipeService`, grup/pilihan/bahan pilihan/tautan lewat
 * `ModifierGroupService`, stok bahan lewat `MaterialStockService` (satu-satunya penulis). Impor hanya menerjemahkan
 * baris Excel ke bentuk masukan service itu, jadi semua aturan bisnis (resep tanpa versi, batas jumlah pilihan,
 * pemotongan stok) tetap satu.
 *
 * Kunci rujukan: bahan = `code`, produk = `code_prefix` (`product_code`), grup = `name`, pilihan = `option_name` di
 * dalam grupnya, resep = (`product_code`, `recipe_name`). Sel kosong = "tidak diubah" untuk data yang sudah ada.
 */
trait ImportsRecipesAndModifiers
{
    /** @var array<string, bool> sheet kanonik => hadir di berkas ini (sheet hadir tanpa baris tetap berarti "kosongkan"). */
    private array $presentSheets = [];

    private function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    // =================================================================
    // MATERIAL_STOCK
    // =================================================================

    private function validateMaterialStock(array $rows, array $materialPlan, array $outletPlan): array
    {
        $sheet = MasterDataSheets::MATERIAL_STOCK;
        $plan = [];
        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $values = $entry['values'];
            $broken = false;

            $materialCode = $this->filled($values, 'material_code') ? strtoupper($this->stringValue($values, 'material_code')) : null;
            if ($materialCode === null) {
                $this->addError($sheet, $row, 'material_code', 'Kode bahan wajib diisi.');
                $broken = true;
            } elseif (! $this->materialKnown($materialCode, $materialPlan)) {
                $this->addError($sheet, $row, 'material_code', "Bahan dengan kode '{$materialCode}' tidak ditemukan.");
                $broken = true;
            }

            $outletCode = $this->filled($values, 'outlet_code') ? strtoupper($this->stringValue($values, 'outlet_code')) : null;
            $outletCode = $this->outletRefError($sheet, $row, $outletCode, $outletPlan);
            $broken = $broken || $outletCode === null;

            $target = null;
            if (! $this->filled($values, 'current_stock')) {
                $this->addError($sheet, $row, 'current_stock', 'Jumlah stok akhir (current_stock) wajib diisi; isi 0 untuk mengosongkan.');
                $broken = true;
            } elseif (! is_numeric($values['current_stock']) || (float) $values['current_stock'] < 0 || (float) $values['current_stock'] > 99999999.9999) {
                $this->addError($sheet, $row, 'current_stock', "Nilai '{$values['current_stock']}' harus angka polos 0 atau lebih (tanpa pemisah ribuan).");
                $broken = true;
            } else {
                $target = round((float) $values['current_stock'], 4);
            }

            if ($broken) {
                continue;
            }

            $key = $materialCode.'|'.$outletCode;
            if (isset($seen[$key])) {
                $this->addError($sheet, $row, 'material_code', "Pasangan bahan '{$materialCode}' + outlet '{$outletCode}' muncul dua kali di sheet ini (baris {$seen[$key]}).");

                continue;
            }
            $seen[$key] = $row;

            $existing = Material::where('code', $materialCode)->first();
            $outlet = Outlet::where('code', $outletCode)->first();
            $balance = $existing && $outlet
                ? MaterialOutletStock::query()->where('material_id', $existing->id)->where('outlet_id', $outlet->id)->value('current_stock')
                : null;
            $balance === null ? $created++ : $updated++;

            $plan[] = ['row' => $row, 'material_code' => $materialCode, 'outlet_code' => $outletCode, 'target' => $target, 'reason' => $this->stringValue($values, 'reason')];
        }

        $this->counts[$sheet] = ['rows' => count($rows), 'created' => $created, 'updated' => $updated, 'unchanged' => 0];

        return $plan;
    }

    private function applyMaterialStock(array $plan, User $user): void
    {
        foreach ($plan as $entry) {
            $material = Material::where('code', $entry['material_code'])->first();
            $outlet = Outlet::where('code', $entry['outlet_code'])->first();
            if ($material === null) {
                throw new MasterDataImportRowException(MasterDataSheets::MATERIAL_STOCK, $entry['row'], 'material_code', "Bahan '{$entry['material_code']}' tidak ditemukan.");
            }
            if ($outlet === null) {
                throw new MasterDataImportRowException(MasterDataSheets::MATERIAL_STOCK, $entry['row'], 'outlet_code', "Outlet '{$entry['outlet_code']}' tidak ditemukan.");
            }
            $this->assertOutletPermitted(MasterDataSheets::MATERIAL_STOCK, $entry['row'], $outlet);

            $balance = (float) MaterialOutletStock::query()->where('material_id', $material->id)->where('outlet_id', $outlet->id)->value('current_stock');
            $delta = round($entry['target'] - $balance, 4);
            if (abs($delta) < 0.00005) {
                continue; // movement nol bukan riwayat, hanya derau
            }

            app(MaterialStockService::class)->applyMovement(
                material: $material,
                outletId: (int) $outlet->id,
                type: 'adjustment',
                qtyChange: $delta,
                referenceType: 'MasterDataImport',
                userId: $user->id,
                reason: 'Impor massal master data (stok bahan)'.($entry['reason'] !== null ? ' — '.$entry['reason'] : ''),
            );
        }
    }

    // =================================================================
    // RECIPES
    // =================================================================

    private function validateRecipes(array $rows, array $productPlan, array $materialPlan): array
    {
        $sheet = MasterDataSheets::RECIPES;
        $groups = []; // "kodeProduk|namaResep" => data kepala + baris bahan
        $created = 0;
        $updated = 0;

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $values = $entry['values'];
            $broken = false;

            $name = $this->stringValue($values, 'recipe_name');
            if ($name === null) {
                $this->addError($sheet, $row, 'recipe_name', 'Nama resep wajib diisi.');
                $broken = true;
            } elseif (mb_strlen($name) > 100) {
                $this->addError($sheet, $row, 'recipe_name', 'Nama resep maksimal 100 karakter.');
                $broken = true;
            }

            $productCode = $this->filled($values, 'product_code') ? strtoupper($this->stringValue($values, 'product_code')) : null;
            if ($productCode === null) {
                $this->addError($sheet, $row, 'product_code', 'Kode produk (code_prefix) wajib diisi.');
                $broken = true;
            } elseif (! $this->productKnown($productCode, $productPlan)) {
                $this->addError($sheet, $row, 'product_code', "Produk dengan kode '{$productCode}' tidak ditemukan.");
                $broken = true;
            }

            $materialCode = $this->filled($values, 'material_code') ? strtoupper($this->stringValue($values, 'material_code')) : null;
            if ($materialCode === null) {
                $this->addError($sheet, $row, 'material_code', 'Kode bahan wajib diisi.');
                $broken = true;
            } elseif (! $this->materialKnown($materialCode, $materialPlan)) {
                $this->addError($sheet, $row, 'material_code', "Bahan dengan kode '{$materialCode}' tidak ditemukan.");
                $broken = true;
            }

            $quantity = null;
            if (! $this->filled($values, 'quantity') || ! is_numeric($values['quantity']) || (float) $values['quantity'] <= 0 || (float) $values['quantity'] > 99999999.9999) {
                $this->addError($sheet, $row, 'quantity', 'Jumlah bahan per porsi wajib angka polos lebih dari 0.');
                $broken = true;
            } else {
                $quantity = round((float) $values['quantity'], 4);
            }

            $shrink = 0.0;
            if ($this->filled($values, 'shrinkage_pct')) {
                if (! is_numeric($values['shrinkage_pct']) || (float) $values['shrinkage_pct'] < 0 || (float) $values['shrinkage_pct'] > 99.99) {
                    $this->addError($sheet, $row, 'shrinkage_pct', 'Susut (shrinkage_pct) harus angka 0 sampai 99.99.');
                    $broken = true;
                } else {
                    $shrink = round((float) $values['shrinkage_pct'], 2);
                }
            }

            $portions = null;
            if ($this->filled($values, 'portions')) {
                if (! is_numeric($values['portions']) || (float) $values['portions'] <= 0 || (float) $values['portions'] > 9999999.999) {
                    $this->addError($sheet, $row, 'portions', 'Portions harus angka polos lebih dari 0.');
                    $broken = true;
                } else {
                    $portions = round((float) $values['portions'], 3);
                }
            }

            $from = null;
            if ($this->filled($values, 'effective_from')) {
                $from = $this->parseDateCell($sheet, $row, 'effective_from', $values['effective_from']);
                $broken = $broken || $from === null;
            }

            $skus = null;
            if ($this->filled($values, 'variant_skus')) {
                $skus = array_values(array_unique(array_filter(array_map(
                    fn ($s) => strtoupper(trim($s)),
                    preg_split('/[,;\n]+/', (string) $values['variant_skus']) ?: []
                ))));
            }

            if ($broken) {
                continue;
            }

            $gk = $productCode.'|'.$this->key($name);
            if (! isset($groups[$gk])) {
                $groups[$gk] = [
                    'row' => $row, 'name' => $name, 'product_code' => $productCode, 'portions' => null, 'effective_from' => null,
                    'variant_skus' => null, 'note' => null, 'lines' => [], 'materials' => [],
                ];
            }
            $g = &$groups[$gk];

            // Kolom kepala (portions, effective_from, variant_skus, note) cukup di satu baris resep; baris lain boleh
            // mengulang nilai yang SAMA atau mengosongkannya, tetapi tidak boleh bertentangan.
            foreach ([
                'portions' => $portions, 'effective_from' => $from?->toDateString(),
                'variant_skus' => $skus === null ? null : implode(',', $skus),
                'note' => $this->stringValue($values, 'note'),
            ] as $field => $value) {
                if ($value === null) {
                    continue;
                }
                $stored = $g[$field] === null ? null : ($field === 'variant_skus' ? implode(',', $g[$field]) : $g[$field]);
                if ($stored === null) {
                    $g[$field] = $field === 'variant_skus' ? $skus : $value;
                } elseif ((string) $stored !== (string) $value) {
                    $this->addError($sheet, $row, $field, "Nilai '{$field}' bertentangan dengan baris {$g['row']} untuk resep yang sama.");
                }
            }

            if (isset($g['materials'][$materialCode])) {
                $this->addError($sheet, $row, 'material_code', "Bahan '{$materialCode}' muncul dua kali di resep '{$name}' (baris {$g['materials'][$materialCode]}).");
            } else {
                $g['materials'][$materialCode] = $row;
                $g['lines'][] = ['row' => $row, 'material_code' => $materialCode, 'quantity' => $quantity, 'shrinkage_pct' => $shrink];
            }
            unset($g);
        }

        $plan = [];
        foreach ($groups as $g) {
            if ($g['portions'] === null) {
                $this->addError($sheet, $g['row'], 'portions', "Portions wajib diisi pada baris pertama resep '{$g['name']}'.");

                continue;
            }
            $g['effective_from'] ??= BusinessDay::today();

            $product = Product::where('code_prefix', $g['product_code'])->first();
            $existing = $product
                ? Recipe::query()->where('product_id', $product->id)->whereNull('archived_at')->where('name', $g['name'])->first()
                : null;

            if ($existing === null && Carbon::parse($g['effective_from'])->lt(Carbon::parse(BusinessDay::today())->subDays(RecipeService::MAX_BACKDATE_DAYS))) {
                $this->addError($sheet, $g['row'], 'effective_from', 'Tanggal berlaku tidak boleh lebih dari '.RecipeService::MAX_BACKDATE_DAYS.' hari ke belakang.');

                continue;
            }

            if ($product !== null && ! $this->recipeVariantsOk($sheet, $g, $product)) {
                continue;
            }

            $existing === null ? $created++ : $updated++;
            $plan[] = $g + ['exists' => $existing !== null];
        }

        $this->counts[$sheet] = ['rows' => count($rows), 'created' => $created, 'updated' => $updated, 'unchanged' => 0];

        return $plan;
    }

    /** Varian yang disebut harus milik produk itu dan tidak boleh BOM-selesai (sumber harga modal lain). */
    private function recipeVariantsOk(string $sheet, array $g, Product $product): bool
    {
        $variants = $product->variants()->get();
        $chosen = $g['variant_skus'] === null ? $variants : $variants->filter(fn ($v) => in_array(strtoupper($v->sku), $g['variant_skus'], true));

        if ($g['variant_skus'] !== null) {
            $missing = array_diff($g['variant_skus'], $variants->pluck('sku')->map(fn ($s) => strtoupper($s))->all());
            if ($missing !== []) {
                $this->addError($sheet, $g['row'], 'variant_skus', 'SKU bukan varian produk '.$g['product_code'].': '.implode(', ', $missing).'.');

                return false;
            }
        }
        if ($chosen->isEmpty()) {
            $this->addError($sheet, $g['row'], 'variant_skus', "Produk {$g['product_code']} belum punya varian.");

            return false;
        }
        $conflict = $chosen->where('bom_complete', true);
        if ($conflict->isNotEmpty()) {
            $this->addError($sheet, $g['row'], 'variant_skus', 'BOM varian sudah ditandai selesai (harga modal mengikuti BOM): '.$conflict->pluck('sku')->implode(', ').'. Buka kembali BOM-nya dulu.');

            return false;
        }

        return true;
    }

    private function applyRecipes(array $plan, User $user): void
    {
        $service = app(RecipeService::class);

        foreach ($plan as $g) {
            $product = Product::where('code_prefix', $g['product_code'])->first();
            if ($product === null) {
                throw new MasterDataImportRowException(MasterDataSheets::RECIPES, $g['row'], 'product_code', "Produk '{$g['product_code']}' tidak ditemukan.");
            }

            $variants = $product->variants()->get();
            $chosen = $g['variant_skus'] === null ? $variants : $variants->filter(fn ($v) => in_array(strtoupper($v->sku), $g['variant_skus'], true));
            if ($chosen->isEmpty()) {
                throw new MasterDataImportRowException(MasterDataSheets::RECIPES, $g['row'], 'variant_skus', "Tidak ada varian produk {$g['product_code']} yang cocok.");
            }

            $lines = [];
            foreach ($g['lines'] as $l) {
                $material = Material::where('code', $l['material_code'])->first();
                if ($material === null) {
                    throw new MasterDataImportRowException(MasterDataSheets::RECIPES, $l['row'], 'material_code', "Bahan '{$l['material_code']}' tidak ditemukan.");
                }
                $lines[] = ['material_id' => $material->id, 'quantity' => $l['quantity'], 'shrinkage_pct' => $l['shrinkage_pct']];
            }

            $data = [
                'name' => $g['name'], 'yield' => $g['portions'], 'effective_from' => $g['effective_from'],
                'variant_ids' => $chosen->pluck('id')->all(), 'lines' => $lines, 'note' => $g['note'],
            ];

            try {
                $existing = Recipe::query()->where('product_id', $product->id)->whereNull('archived_at')->where('name', $g['name'])->first();
                if ($existing !== null) {
                    // Sheet ini hanya memuat resep DASAR berbahan baku. Yang diatur di layar Resep dipertahankan, kalau tidak
                    // impor ulang menghapusnya diam-diam: (1) tabel bahan bermodifier; (2) baris bahan berupa PRODUK (093),
                    // di blok dasar maupun blok bermodifier — lembar ini tidak punya kolom untuknya.
                    $existing->loadMissing('blocks.lines');
                    $productLines = fn ($block) => $block->lines->whereNotNull('variant_id')
                        ->map(fn ($l) => ['variant_id' => $l->variant_id, 'quantity' => $l->quantity, 'shrinkage_pct' => 0])->values()->all();
                    $baseProductLines = [];
                    $keep = [];
                    foreach ($existing->blocks as $b) {
                        if ($b->modifier_option_id === null) {
                            $baseProductLines[$b->variant_id] = $productLines($b);

                            continue;
                        }
                        $keep[] = [
                            'variant_id' => $b->variant_id, 'modifier_option_id' => $b->modifier_option_id,
                            'lines' => $b->lines->map(fn ($l) => $l->variant_id !== null
                                ? ['variant_id' => $l->variant_id, 'quantity' => $l->quantity, 'shrinkage_pct' => 0]
                                : ['material_id' => $l->material_id, 'quantity' => $l->quantity, 'shrinkage_pct' => $l->shrinkage_pct])->all(),
                        ];
                    }
                    if ($keep !== [] || array_filter($baseProductLines) !== []) {
                        $data = ['blocks' => array_merge(
                            array_map(fn ($id) => ['variant_id' => $id, 'modifier_option_id' => null, 'lines' => array_merge($lines, $baseProductLines[$id] ?? [])], $chosen->pluck('id')->all()),
                            $keep,
                        )] + $data;
                        unset($data['variant_ids'], $data['lines']);
                    }
                }
                $existing ? $service->update($existing, $data, $user) : $service->create($product, $data, $user);
            } catch (ValidationException $e) {
                throw new MasterDataImportRowException(MasterDataSheets::RECIPES, $g['row'], null, $this->firstMessage($e));
            } catch (BomRuleException $e) {
                throw new MasterDataImportRowException(MasterDataSheets::RECIPES, $g['row'], 'variant_skus', $e->getMessage());
            }
        }
    }

    // =================================================================
    // MODIFIER_GROUPS
    // =================================================================

    private function validateModifierGroups(array $rows): array
    {
        $sheet = MasterDataSheets::MODIFIER_GROUPS;
        $plan = [];
        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $values = $entry['values'];
            $broken = false;

            $name = $this->stringValue($values, 'name');
            if ($name === null || mb_strlen($name) > 100) {
                $this->addError($sheet, $row, 'name', $name === null ? 'Nama grup wajib diisi.' : 'Nama grup maksimal 100 karakter.');

                continue;
            }
            if (isset($seen[$this->key($name)])) {
                $this->addError($sheet, $row, 'name', "Grup '{$name}' muncul dua kali di sheet ini (baris {$seen[$this->key($name)]}).");

                continue;
            }
            $seen[$this->key($name)] = $row;

            $existing = ModifierGroup::where('name', $name)->first();
            $attrs = [];

            $type = $this->filled($values, 'selection_type') ? strtolower($this->stringValue($values, 'selection_type')) : null;
            if ($type !== null && ! in_array($type, ['single', 'multiple'], true)) {
                $this->addError($sheet, $row, 'selection_type', "Nilai '{$type}' tidak dikenali. Isi single atau multiple.");
                $broken = true;
            } elseif ($type !== null) {
                $attrs['selection_type'] = $type;
            } elseif ($existing === null) {
                $attrs['selection_type'] = 'single';
            }

            foreach (['is_required', 'portion_per_option', 'is_active'] as $col) {
                if ($this->filled($values, $col)) {
                    $b = $this->parseBool($sheet, $row, $col, $values[$col]);
                    $broken = $broken || $b === null;
                    if ($b !== null) {
                        $attrs[$col] = $b;
                    }
                } elseif ($existing === null) {
                    $attrs[$col] = $col === 'is_active';
                }
            }

            if ($this->filled($values, 'min_select')) {
                $v = $this->parseInt($sheet, $row, 'min_select', $values['min_select'], 0);
                if ($v === null || $v > 50) {
                    $v === null || $this->addError($sheet, $row, 'min_select', 'Maksimal 50.');
                    $broken = true;
                } else {
                    $attrs['min_select'] = $v;
                }
            }
            if ($this->filled($values, 'max_select')) {
                if (in_array(strtolower((string) $values['max_select']), ['unlimited', 'tanpa batas'], true)) {
                    $attrs['max_select'] = null;
                } else {
                    $v = $this->parseInt($sheet, $row, 'max_select', $values['max_select'], 1);
                    if ($v === null || $v > 50) {
                        $v === null || $this->addError($sheet, $row, 'max_select', 'Maksimal 50, atau tulis unlimited.');
                        $broken = true;
                    } else {
                        $attrs['max_select'] = $v;
                    }
                }
            } elseif ($existing === null && ($attrs['selection_type'] ?? 'single') === 'multiple') {
                $attrs['max_select'] = null; // grup jamak baru tanpa batas atas bila sel dikosongkan
            }

            if (($attrs['selection_type'] ?? $existing?->selection_type) === 'multiple') {
                $min = $attrs['min_select'] ?? ($existing?->min_select ?? 0);
                $max = array_key_exists('max_select', $attrs) ? $attrs['max_select'] : $existing?->max_select;
                if ($max !== null && $min > $max) {
                    $this->addError($sheet, $row, 'max_select', 'min_select tidak boleh lebih besar dari max_select.');
                    $broken = true;
                }
            }

            if ($this->filled($values, 'display_order')) {
                $v = $this->parseInt($sheet, $row, 'display_order', $values['display_order']);
                if ($v === null || $v < -32768 || $v > 32767) {
                    $v === null || $this->addError($sheet, $row, 'display_order', 'Urutan di luar rentang.');
                    $broken = true;
                } else {
                    $attrs['display_order'] = $v;
                }
            }

            if ($broken) {
                continue;
            }

            $existing === null ? $created++ : $updated++;
            $plan[] = ['row' => $row, 'name' => $name, 'attributes' => $attrs, 'exists' => $existing !== null];
        }

        $this->counts[$sheet] = ['rows' => count($rows), 'created' => $created, 'updated' => $updated, 'unchanged' => 0];

        return $plan;
    }

    // =================================================================
    // MODIFIER_OPTIONS
    // =================================================================

    /** @return array<string, list<array<string, mixed>>> kunci grup => pilihan berurutan sesuai baris */
    private function validateModifierOptions(array $rows, array $groupPlan): array
    {
        $sheet = MasterDataSheets::MODIFIER_OPTIONS;
        $byGroup = [];
        $seen = [];
        $created = 0;
        $updated = 0;
        $groupNames = array_map(fn ($g) => $this->key($g['name']), $groupPlan);

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $values = $entry['values'];
            $broken = false;

            $group = $this->stringValue($values, 'group_name');
            if ($group === null) {
                $this->addError($sheet, $row, 'group_name', 'Nama grup wajib diisi.');
                $broken = true;
            } elseif (! in_array($this->key($group), $groupNames, true) && ! ModifierGroup::where('name', $group)->exists()) {
                $this->addError($sheet, $row, 'group_name', "Grup '{$group}' tidak ditemukan. Tambahkan di sheet modifier_groups.");
                $broken = true;
            }

            $option = $this->stringValue($values, 'option_name');
            if ($option === null || mb_strlen($option) > 100) {
                $this->addError($sheet, $row, 'option_name', $option === null ? 'Nama pilihan wajib diisi.' : 'Nama pilihan maksimal 100 karakter.');
                $broken = true;
            }

            $attrs = [];
            if ($this->filled($values, 'price_delta')) {
                if (! is_numeric($values['price_delta']) || abs((float) $values['price_delta']) > 9999999.99) {
                    $this->addError($sheet, $row, 'price_delta', 'Selisih harga harus angka polos (boleh negatif), maksimal 9999999.99.');
                    $broken = true;
                } else {
                    $attrs['price_delta'] = round((float) $values['price_delta'], 2);
                }
            }
            if ($this->filled($values, 'min_qty')) {
                $v = $this->parseInt($sheet, $row, 'min_qty', $values['min_qty'], 1);
                if ($v === null || $v > 50) {
                    $v === null || $this->addError($sheet, $row, 'min_qty', 'Maksimal 50.');
                    $broken = true;
                } else {
                    $attrs['min_qty'] = $v;
                }
            }
            if ($this->filled($values, 'max_qty')) {
                if (in_array(strtolower((string) $values['max_qty']), ['unlimited', 'tanpa batas'], true)) {
                    $attrs['max_qty'] = null;
                } else {
                    $v = $this->parseInt($sheet, $row, 'max_qty', $values['max_qty'], 1);
                    if ($v === null || $v > 50) {
                        $v === null || $this->addError($sheet, $row, 'max_qty', 'Maksimal 50, atau tulis unlimited.');
                        $broken = true;
                    } else {
                        $attrs['max_qty'] = $v;
                    }
                }
            }
            if (isset($attrs['min_qty'], $attrs['max_qty']) && $attrs['max_qty'] !== null && $attrs['min_qty'] > $attrs['max_qty']) {
                $this->addError($sheet, $row, 'max_qty', 'min_qty tidak boleh lebih besar dari max_qty.');
                $broken = true;
            }
            if ($this->filled($values, 'display_order')) {
                $v = $this->parseInt($sheet, $row, 'display_order', $values['display_order']);
                if ($v === null || $v < -32768 || $v > 32767) {
                    $v === null || $this->addError($sheet, $row, 'display_order', 'Urutan di luar rentang.');
                    $broken = true;
                } else {
                    $attrs['display_order'] = $v;
                }
            }
            if ($this->filled($values, 'is_active')) {
                $b = $this->parseBool($sheet, $row, 'is_active', $values['is_active']);
                $broken = $broken || $b === null;
                if ($b !== null) {
                    $attrs['is_active'] = $b;
                }
            }

            if ($broken) {
                continue;
            }

            $sk = $this->key($group).'|'.$this->key($option);
            if (isset($seen[$sk])) {
                $this->addError($sheet, $row, 'option_name', "Pilihan '{$option}' muncul dua kali di grup '{$group}' (baris {$seen[$sk]}).");

                continue;
            }
            $seen[$sk] = $row;

            $exists = ModifierGroup::where('name', $group)->first()?->options()->where('name', $option)->exists() ?? false;
            $exists ? $updated++ : $created++;

            $byGroup[$this->key($group)][] = ['row' => $row, 'group' => $group, 'name' => $option, 'attributes' => $attrs];
        }

        $this->counts[$sheet] = ['rows' => count($rows), 'created' => $created, 'updated' => $updated, 'unchanged' => 0];

        return $byGroup;
    }

    // =================================================================
    // MODIFIER_OPTION_MATERIALS
    // =================================================================

    /** @return array<string, array<string, list<array<string, mixed>>>> kunci grup => kunci pilihan => bahan */
    private function validateModifierOptionMaterials(array $rows, array $groupPlan, array $optionsByGroup, array $materialPlan): array
    {
        $sheet = MasterDataSheets::MODIFIER_OPTION_MATERIALS;
        $out = [];
        $seen = [];
        $created = 0;
        $groupNames = array_map(fn ($g) => $this->key($g['name']), $groupPlan);

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $values = $entry['values'];
            $broken = false;

            $group = $this->stringValue($values, 'group_name');
            $option = $this->stringValue($values, 'option_name');
            if ($group === null) {
                $this->addError($sheet, $row, 'group_name', 'Nama grup wajib diisi.');
                $broken = true;
            }
            if ($option === null) {
                $this->addError($sheet, $row, 'option_name', 'Nama pilihan wajib diisi.');
                $broken = true;
            }
            if (! $broken) {
                $gk = $this->key($group);
                $inFile = in_array($gk, $groupNames, true)
                    && collect($optionsByGroup[$gk] ?? [])->contains(fn ($o) => $this->key($o['name']) === $this->key($option));
                $inDb = ModifierGroup::where('name', $group)->first()?->options()->where('name', $option)->exists() ?? false;
                if (! $inFile && ! $inDb) {
                    $this->addError($sheet, $row, 'option_name', "Pilihan '{$option}' di grup '{$group}' tidak ditemukan. Tambahkan di sheet modifier_options.");
                    $broken = true;
                }
            }

            $materialCode = $this->filled($values, 'material_code') ? strtoupper($this->stringValue($values, 'material_code')) : null;
            if ($materialCode === null) {
                $this->addError($sheet, $row, 'material_code', 'Kode bahan wajib diisi.');
                $broken = true;
            } elseif (! $this->materialKnown($materialCode, $materialPlan)) {
                $this->addError($sheet, $row, 'material_code', "Bahan dengan kode '{$materialCode}' tidak ditemukan.");
                $broken = true;
            }

            $quantity = null;
            if (! $this->filled($values, 'quantity') || ! is_numeric($values['quantity']) || (float) $values['quantity'] <= 0 || (float) $values['quantity'] > 99999999.9999) {
                $this->addError($sheet, $row, 'quantity', 'Jumlah bahan per porsi wajib angka polos lebih dari 0.');
                $broken = true;
            } else {
                $quantity = round((float) $values['quantity'], 4);
            }

            if ($broken) {
                continue;
            }

            // Satu bahan boleh dipakai di banyak pilihan; hanya DI DALAM satu pilihan tidak boleh kembar.
            $sk = $this->key($group).'|'.$this->key($option).'|'.$materialCode;
            if (isset($seen[$sk])) {
                $this->addError($sheet, $row, 'material_code', "Bahan '{$materialCode}' muncul dua kali di pilihan '{$option}' (baris {$seen[$sk]}).");

                continue;
            }
            $seen[$sk] = $row;
            $created++;

            $out[$this->key($group)][$this->key($option)][] = ['row' => $row, 'material_code' => $materialCode, 'quantity' => $quantity];
        }

        foreach ($out as $g => $byOption) {
            foreach ($byOption as $o => $list) {
                if (count($list) > 20) {
                    $this->addError($sheet, $list[0]['row'], 'material_code', 'Satu pilihan maksimal 20 bahan.');
                }
            }
        }

        $this->counts[$sheet] = ['rows' => count($rows), 'created' => $created, 'updated' => 0, 'unchanged' => 0];

        return $out;
    }

    // =================================================================
    // MODIFIER_LINKS
    // =================================================================

    private function validateModifierLinks(array $rows, array $groupPlan, array $productPlan, array $categoryPlan): array
    {
        $sheet = MasterDataSheets::MODIFIER_LINKS;
        $plan = [];
        $seen = [];
        $created = 0;
        $groupNames = array_map(fn ($g) => $this->key($g['name']), $groupPlan);
        $categoryCodes = array_map('strtoupper', array_column($categoryPlan, 'code'));

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $values = $entry['values'];
            $broken = false;

            $group = $this->stringValue($values, 'group_name');
            if ($group === null) {
                $this->addError($sheet, $row, 'group_name', 'Nama grup wajib diisi.');
                $broken = true;
            } elseif (! in_array($this->key($group), $groupNames, true) && ! ModifierGroup::where('name', $group)->exists()) {
                $this->addError($sheet, $row, 'group_name', "Grup '{$group}' tidak ditemukan.");
                $broken = true;
            }

            $productCode = $this->filled($values, 'product_code') ? strtoupper($this->stringValue($values, 'product_code')) : null;
            $categoryCode = $this->filled($values, 'category_code') ? strtoupper($this->stringValue($values, 'category_code')) : null;
            if (($productCode === null) === ($categoryCode === null)) {
                $this->addError($sheet, $row, 'product_code', 'Isi TEPAT SATU dari product_code atau category_code.');
                $broken = true;
            } elseif ($productCode !== null && ! $this->productKnown($productCode, $productPlan)) {
                $this->addError($sheet, $row, 'product_code', "Produk dengan kode '{$productCode}' tidak ditemukan.");
                $broken = true;
            } elseif ($categoryCode !== null && ! in_array($categoryCode, $categoryCodes, true) && ! Category::where('code', $categoryCode)->exists()) {
                $this->addError($sheet, $row, 'category_code', "Kategori dengan kode '{$categoryCode}' tidak ditemukan.");
                $broken = true;
            }

            $linked = true;
            if ($this->filled($values, 'is_linked')) {
                $b = $this->parseBool($sheet, $row, 'is_linked', $values['is_linked']);
                $broken = $broken || $b === null;
                $linked = $b ?? true;
            }

            if ($broken) {
                continue;
            }

            $sk = $this->key($group).'|'.($productCode ?? 'C:'.$categoryCode);
            if (isset($seen[$sk])) {
                $this->addError($sheet, $row, 'group_name', "Tautan yang sama muncul dua kali di sheet ini (baris {$seen[$sk]}).");

                continue;
            }
            $seen[$sk] = $row;
            $created++;

            $plan[] = ['row' => $row, 'group' => $group, 'product_code' => $productCode, 'category_code' => $categoryCode, 'linked' => $linked];
        }

        $this->counts[$sheet] = ['rows' => count($rows), 'created' => $created, 'updated' => 0, 'unchanged' => 0];

        return $plan;
    }

    // =================================================================
    // PENERAPAN MODIFIER (grup → pilihan → bahan → tautan)
    // =================================================================

    /**
     * @param  array<string, list<array>>  $optionsByGroup
     * @param  array<string, array<string, list<array>>>  $materialsByGroup
     */
    private function applyModifiers(array $groupPlan, array $optionsByGroup, array $materialsByGroup, array $linkPlan, User $user): void
    {
        $service = app(ModifierGroupService::class);
        $materialsSheetPresent = $this->presentSheets[MasterDataSheets::MODIFIER_OPTION_MATERIALS] ?? false;

        // Grup yang disentuh: yang tertulis di sheet grup + yang hanya dirujuk sheet pilihan/bahan.
        $groups = [];
        foreach ($groupPlan as $g) {
            $groups[$this->key($g['name'])] = ['name' => $g['name'], 'row' => $g['row'], 'attributes' => $g['attributes']];
        }
        foreach ($optionsByGroup as $gk => $options) {
            $groups[$gk] ??= ['name' => $options[0]['group'], 'row' => $options[0]['row'], 'attributes' => []];
        }
        foreach ($materialsByGroup as $gk => $byOption) {
            $first = reset($byOption)[0];
            $groups[$gk] ??= ['name' => ModifierGroup::query()->get()->first(fn ($m) => $this->key($m->name) === $gk)?->name ?? $gk, 'row' => $first['row'], 'attributes' => []];
        }

        foreach ($groups as $gk => $g) {
            $existing = ModifierGroup::where('name', $g['name'])->first();
            $existingOptions = $existing ? $existing->options()->get() : collect();
            $listed = $optionsByGroup[$gk] ?? [];
            $matRows = $materialsByGroup[$gk] ?? [];

            $buildMaterials = function (string $optionKey) use ($matRows): array {
                return array_map(function ($m) {
                    $material = Material::where('code', $m['material_code'])->first();
                    if ($material === null) {
                        throw new MasterDataImportRowException(MasterDataSheets::MODIFIER_OPTION_MATERIALS, $m['row'], 'material_code', "Bahan '{$m['material_code']}' tidak ditemukan.");
                    }

                    return ['material_id' => $material->id, 'quantity' => $m['quantity']];
                }, $matRows[$optionKey] ?? []);
            };

            $options = [];
            $handled = [];
            foreach ($listed as $i => $o) {
                $ok = $this->key($o['name']);
                $handled[$ok] = true;
                $current = $existingOptions->first(fn ($x) => $this->key($x->name) === $ok);
                $row = ['name' => $o['name']] + $o['attributes'];
                $row['price_delta'] ??= $current ? (float) $current->price_delta : 0;
                $row['display_order'] ??= $current ? $current->display_order : $i;
                $row['is_active'] ??= $current ? $current->is_active : true;
                if ($current) {
                    $row['id'] = $current->id;
                }
                if ($materialsSheetPresent || isset($matRows[$ok])) {
                    $row['materials'] = $buildMaterials($ok);
                }
                $options[] = $row;
            }

            // Pilihan yang hanya disebut sheet bahan, atau (bila sheet pilihan tidak menyebut grup ini) seluruh pilihan
            // lama: dikirim apa adanya supaya penggantian penuh ala service tidak menonaktifkannya.
            $touchOthers = $listed !== [] ? array_keys($matRows) : $existingOptions->map(fn ($x) => $this->key($x->name))->all();
            foreach ($existingOptions as $x) {
                $ok = $this->key($x->name);
                if (isset($handled[$ok]) || (! in_array($ok, $touchOthers, true) && $listed !== [])) {
                    continue;
                }
                $row = ['id' => $x->id, 'name' => $x->name, 'price_delta' => (float) $x->price_delta, 'display_order' => $x->display_order, 'is_active' => $x->is_active];
                if (isset($matRows[$ok])) {
                    $row['materials'] = $buildMaterials($ok);
                }
                $options[] = $row;
            }

            foreach (array_keys($matRows) as $ok) {
                if (! isset($handled[$ok]) && ! $existingOptions->contains(fn ($x) => $this->key($x->name) === $ok)) {
                    throw new MasterDataImportRowException(MasterDataSheets::MODIFIER_OPTION_MATERIALS, $matRows[$ok][0]['row'], 'option_name', "Pilihan tidak ditemukan di grup '{$g['name']}'.");
                }
            }

            $data = ['name' => $g['name']] + $g['attributes'];
            if ($options !== [] || $listed !== []) {
                $data['options'] = $options;
            }

            try {
                $existing ? $service->update($existing, $data, $user->id) : $service->create($data, $user->id);
            } catch (ValidationException|InputRejectedException $e) {
                throw new MasterDataImportRowException(MasterDataSheets::MODIFIER_GROUPS, $g['row'], null, $this->firstMessage($e));
            }
        }

        $byGroup = [];
        foreach ($linkPlan as $l) {
            $byGroup[$this->key($l['group'])][] = $l;
        }
        foreach ($byGroup as $links) {
            $group = ModifierGroup::where('name', $links[0]['group'])->first();
            if ($group === null) {
                throw new MasterDataImportRowException(MasterDataSheets::MODIFIER_LINKS, $links[0]['row'], 'group_name', "Grup '{$links[0]['group']}' tidak ditemukan.");
            }

            // Penambahan/pelepasan saja: tautan lain yang sudah ada di grup ini TIDAK disentuh.
            $products = $group->products()->pluck('products.id')->all();
            $categories = $group->categories()->pluck('categories.id')->all();
            foreach ($links as $l) {
                if ($l['product_code'] !== null) {
                    $id = Product::where('code_prefix', $l['product_code'])->value('id');
                    if ($id === null) {
                        throw new MasterDataImportRowException(MasterDataSheets::MODIFIER_LINKS, $l['row'], 'product_code', "Produk '{$l['product_code']}' tidak ditemukan.");
                    }
                    $products = $l['linked'] ? array_merge($products, [$id]) : array_diff($products, [$id]);
                } else {
                    $id = Category::where('code', $l['category_code'])->value('id');
                    if ($id === null) {
                        throw new MasterDataImportRowException(MasterDataSheets::MODIFIER_LINKS, $l['row'], 'category_code', "Kategori '{$l['category_code']}' tidak ditemukan.");
                    }
                    $categories = $l['linked'] ? array_merge($categories, [$id]) : array_diff($categories, [$id]);
                }
            }

            try {
                $service->setAttachments($group, array_values($products), array_values($categories));
            } catch (ValidationException|InputRejectedException $e) {
                throw new MasterDataImportRowException(MasterDataSheets::MODIFIER_LINKS, $links[0]['row'], 'group_name', $this->firstMessage($e));
            }
        }
    }

    // =================================================================
    // PEMBANTU
    // =================================================================

    private function materialKnown(string $code, array $materialPlan): bool
    {
        return in_array($code, array_column($materialPlan, 'code'), true) || Material::where('code', $code)->exists();
    }

    private function productKnown(string $code, array $productPlan): bool
    {
        return in_array($code, array_map('strtoupper', array_filter(array_column($productPlan, 'code_prefix'))), true)
            || Product::where('code_prefix', $code)->exists();
    }

    private function firstMessage(\Throwable $e): string
    {
        $errors = method_exists($e, 'errors') ? $e->errors() : [];
        $first = reset($errors);

        return is_array($first) ? (string) reset($first) : $e->getMessage();
    }

    /** Sel tanggal: teks Y-m-d, objek tanggal, atau nomor seri Excel. */
    private function parseDateCell(string $sheet, int $row, string $column, mixed $value): ?Carbon
    {
        try {
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value)->startOfDay();
            }
            if (is_numeric($value)) {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value))->startOfDay();
            }

            return Carbon::createFromFormat('Y-m-d', trim((string) $value))->startOfDay();
        } catch (\Throwable) {
            $this->addError($sheet, $row, $column, "Tanggal '{$value}' tidak dikenali. Tulis dengan format YYYY-MM-DD (contoh: 2026-11-05).");

            return null;
        }
    }
}
