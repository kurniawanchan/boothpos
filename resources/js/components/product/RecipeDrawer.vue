<script setup>
import { computed, reactive, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { createRecipe, getRecipe } from '../../api/recipes';
import { getMaterial, listMaterials } from '../../api/materials';
import { useToastStore } from '../../stores/toast';
import { formatIDR } from '../../utils/formatters';
import { variantName, variantPrice } from '../../utils/itemDetail';
import { computeRecipeCost, referencePrice } from '../../utils/recipeCost';
import FormDrawer from '../page/FormDrawer.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import ErrorState from '../ui/ErrorState.vue';
import LoadingState from '../ui/LoadingState.vue';
import { CELL_ERROR, CELL_HEAD, ROW_REMOVE, TABLE_LINK, cellInputClass } from './cellStyles';

/**
 * 043 (US8) — laci "Resep": menyimpan resep sebagai VERSI BARU (`POST /products/{id}/recipes`); versi lama tidak
 * pernah diubah. Dibuka dari bagian Resep hanya untuk pemegang izin `menu.recipe.edit`; server tetap menolak yang lain.
 *
 * - Kalimat versi memang TIDAK menjanjikan pemotongan stok bahan (belum dibangun, research D12 / O6).
 * - Satuan baris = satuan bahan (read-only): tidak ada konversi satuan.
 * - Bahan = SATU tabel "Bahan per porsi" (rancangan Back Office). Pengelompokan per jenis (bahan baku / setengah jadi /
 *   kemasan) dipertahankan DI DALAM <select> lewat <optgroup>, bukan lagi tiga kotak terpisah. Urutan kirim = urutan baris
 *   (urutan baris tidak berpengaruh pada biaya).
 * - Pratinjau biaya memakai rumus yang SAMA dengan server (utils/recipeCost.js ↔ App\Support\RecipeCost). Biaya satuan
 *   berasal dari: biaya versi sebelumnya (GET /recipes/{id}), harga vendor acuan bahan (GET /materials/{id}:
 *   preferensi lalu termurah) atau `standard_cost` bahan setengah jadi — urutan yang sama dengan server. Baris yang
 *   biayanya tidak diketahui TIDAK dihitung nol: biaya porsi ditandai "(sebagian)" dan food cost/margin ditahan,
 *   dengan catatan "biaya belum lengkap". Angka resmi tetap dari server setelah simpan.
 * - Varian yang BOM-nya selesai tetap bisa dicentang tetapi diberi catatan; server menjawab 409
 *   `cost_source_conflict` dan pesannya (beserta SKU) ditampilkan di dalam laci. Pilihan awal tanpa versi
 *   sebelumnya hanya varian yang tidak BOM-selesai, supaya simpan pertama tidak otomatis ditolak.
 * - Kolom angka/tanggal SENGAJA tanpa atribut `min`/`max`: atribut itu memicu validasi bawaan browser yang menahan
 *   submit dengan balon berbahasa browser dan tanpa pesan kita; batasnya (yield > 0, jumlah > 0, susut 0–99,99,
 *   tanggal ≥ hari ini − 30) dicek `validate()` dengan pesan per kolom, dan server tetap menolak yang lolos.
 * - Prefill dari versi TERBARU (bukan hanya yang aktif) supaya "ubah sedikit lalu simpan" bekerja dari titik terakhir.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  product: { type: Object, default: null }, // { id, name, variants }
  versions: { type: Array, default: () => [] }, // GET /products/{id}/recipes (terbaru dulu)
});
const emit = defineEmits(['close', 'saved']);
const { t } = useI18n();
const toast = useToastStore();

const KINDS = ['ingredient', 'semi_finished', 'packaging'];
const MAX_BACKDATE_DAYS = 30;

const tableLabelId = `rc-table-${useId()}`;
const loadState = ref('loading'); // loading | ready | error
const materials = ref([]);
const unitCosts = reactive({}); // material_id → number | null (null = diketahui TIDAK punya harga)
const yieldQty = ref('1');
const effectiveFrom = ref('');
const variantIds = ref([]);
const note = ref('');
const lines = ref([]);
const submitting = ref(false);
const alertMessage = ref('');
const fieldErrors = reactive({});
const lineErrors = reactive({}); // { [line.key]: { material, quantity, shrinkage } }
let keySeq = 0;
let loadSeq = 0;

const latestVersion = computed(() => props.versions.reduce((m, v) => Math.max(m, Number(v.version) || 0), 0));
const nextVersion = computed(() => latestVersion.value + 1);
const variants = computed(() => props.product?.variants ?? []);

const pad = (n) => String(n).padStart(2, '0');
const dateKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const today = () => dateKey(new Date());
const minDate = () => {
  const d = new Date();
  d.setDate(d.getDate() - MAX_BACKDATE_DAYS);
  return dateKey(d);
};

async function loadAllMaterials() {
  const all = [];
  let page = 1;
  let last = 1;
  do {
    const res = await listMaterials({ per_page: 100, is_active: 1, page });
    all.push(...(res?.data ?? []));
    last = res?.meta?.last_page ?? 1;
    page += 1;
  } while (page <= last && page <= 20);
  return all;
}

function lineFromRecipe(l) {
  return {
    key: `l${++keySeq}`,
    material_id: l.material_id,
    name: l.name ?? '',
    unit: l.unit ?? '',
    quantity: String(Number(l.quantity)),
    shrinkage: String(Number(l.shrinkage_pct ?? 0)),
  };
}

async function load() {
  const mine = ++loadSeq;
  loadState.value = 'loading';
  alertMessage.value = '';
  Object.keys(fieldErrors).forEach((k) => delete fieldErrors[k]);
  Object.keys(lineErrors).forEach((k) => delete lineErrors[k]);
  try {
    const latest = [...props.versions].sort((a, b) => b.version - a.version)[0] ?? null;
    const [mats, recipe] = await Promise.all([loadAllMaterials(), latest ? getRecipe(latest.id) : Promise.resolve(null)]);
    if (mine !== loadSeq) return;
    materials.value = mats;
    Object.keys(unitCosts).forEach((k) => delete unitCosts[k]);
    effectiveFrom.value = today();
    note.value = '';

    if (recipe) {
      yieldQty.value = String(Number(recipe.yield));
      lines.value = (recipe.lines ?? []).map(lineFromRecipe);
      (recipe.lines ?? []).forEach((l) => {
        if (l.unit_cost !== undefined && l.unit_cost !== null) unitCosts[l.material_id] = Number(l.unit_cost);
      });
      const existing = new Set(variants.value.map((v) => v.id));
      variantIds.value = (recipe.variants ?? []).map((v) => v.id).filter((id) => existing.has(id));
    } else {
      yieldQty.value = '1';
      lines.value = [];
      variantIds.value = variants.value.filter((v) => v.is_active !== false && !v.bom_complete).map((v) => v.id);
    }
    loadState.value = 'ready';
  } catch {
    if (mine !== loadSeq) return;
    loadState.value = 'error';
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) load();
  },
  { immediate: true },
);

// --- bahan -------------------------------------------------------------------------------------------------------
const kindLabel = (kind) => t(`products.rc_kind_${kind}`);

/** Pilihan <select> per jenis; jenis tak dikenal jatuh ke bahan baku (sama seperti server). */
const materialGroups = computed(() =>
  KINDS.map((kind) => ({ kind, items: materials.value.filter((m) => (KINDS.includes(m.kind) ? m.kind : 'ingredient') === kind) })).filter((g) => g.items.length),
);
// Bahan baris yang sudah tak aktif (tak ada di daftar) tetap harus tampil sebagai pilihan terpilih.
const orphanOption = (line) => (line.material_id && !materials.value.some((m) => m.id === line.material_id) ? { id: line.material_id, name: line.name || String(line.material_id) } : null);

function addLine() {
  lines.value.push({ key: `l${++keySeq}`, material_id: null, name: '', unit: '', quantity: '', shrinkage: '0' });
}

function removeLine(line) {
  lines.value = lines.value.filter((l) => l !== line);
  delete lineErrors[line.key];
}

/** Biaya satuan sebuah bahan: harga vendor acuan, selain itu `standard_cost` bahan setengah jadi (cermin RecipeService::unitCost). */
async function resolveUnitCost(material) {
  if (Object.prototype.hasOwnProperty.call(unitCosts, material.id)) return;
  let cost = null;
  try {
    const full = await getMaterial(material.id);
    cost = referencePrice(full?.vendor_prices);
    if (cost === null && (full?.kind ?? material.kind) === 'semi_finished' && full?.standard_cost !== null && full?.standard_cost !== undefined) cost = Number(full.standard_cost);
  } catch {
    cost = null; // harga tak bisa dipastikan → baris ditandai belum lengkap, bukan nol
  }
  unitCosts[material.id] = cost;
}

function pickMaterial(line, rawValue) {
  const id = Number(rawValue);
  const m = materials.value.find((x) => x.id === id);
  // Placeholder "Pilih bahan" (nilai kosong) tidak mengosongkan pilihan lama: baris tanpa bahan ditolak validate().
  if (!m) return;
  line.material_id = m.id;
  line.name = m.name;
  line.unit = m.unit ?? '';
  delete lineErrors[line.key]?.material;
  resolveUnitCost(m);
}

const unitCostOf = (line) => (line.material_id !== null && Object.prototype.hasOwnProperty.call(unitCosts, line.material_id) ? unitCosts[line.material_id] : null);

// --- pratinjau biaya -------------------------------------------------------------------------------------------
const headVariant = computed(() => variants.value.filter((v) => variantIds.value.includes(v.id)).sort((a, b) => a.id - b.id)[0] ?? null);
const basePrice = computed(() => (headVariant.value && variantPrice(headVariant.value) !== null ? Number(variantPrice(headVariant.value)) : null));
const orderedLines = computed(() => lines.value);
const preview = computed(() =>
  computeRecipeCost(
    orderedLines.value.map((l) => ({ quantity: l.quantity, shrinkage_pct: l.shrinkage, unit_cost: l.material_id === null ? null : unitCostOf(l) })),
    Number(yieldQty.value),
    basePrice.value,
  ),
);
const lineCostText = (line) => {
  const c = preview.value.lineCosts[orderedLines.value.indexOf(line)];
  return c === null || c === undefined ? '—' : formatIDR(c);
};
const incompleteCount = computed(() => preview.value.unknownCount);

// --- validasi & simpan ---------------------------------------------------------------------------------------------
function validate() {
  Object.keys(fieldErrors).forEach((k) => delete fieldErrors[k]);
  Object.keys(lineErrors).forEach((k) => delete lineErrors[k]);
  let ok = true;
  const y = Number(yieldQty.value);
  if (yieldQty.value === '' || !Number.isFinite(y) || y <= 0) {
    fieldErrors.yield = t('products.rc_err_yield');
    ok = false;
  }
  if (!effectiveFrom.value || effectiveFrom.value < minDate()) {
    fieldErrors.effective_from = t('products.rc_err_date', { days: MAX_BACKDATE_DAYS });
    ok = false;
  }
  if (!variantIds.value.length) {
    fieldErrors.variant_ids = t('products.rc_variants_required');
    ok = false;
  }
  if (!lines.value.length) {
    fieldErrors.lines = t('products.rc_err_lines');
    ok = false;
  }
  lines.value.forEach((l) => {
    const e = {};
    if (l.material_id === null) e.material = t('products.rc_err_material');
    const q = l.quantity === '' ? NaN : Number(l.quantity);
    if (!Number.isFinite(q) || q <= 0) e.quantity = t('products.rc_err_qty');
    const s = l.shrinkage === '' ? 0 : Number(l.shrinkage);
    if (!Number.isFinite(s) || s < 0 || s > 99.99) e.shrinkage = t('products.rc_err_shrink');
    if (Object.keys(e).length) {
      lineErrors[l.key] = e;
      ok = false;
    }
  });
  return ok;
}

function applyServerErrors(errors, order) {
  Object.entries(errors ?? {}).forEach(([field, messages]) => {
    const message = Array.isArray(messages) ? messages[0] : messages;
    const m = /^lines\.(\d+)\.(material_id|quantity|shrinkage_pct)$/.exec(field);
    if (m) {
      const line = order[Number(m[1])];
      if (!line) return;
      const column = m[2] === 'material_id' ? 'material' : m[2] === 'quantity' ? 'quantity' : 'shrinkage';
      lineErrors[line.key] = { ...(lineErrors[line.key] ?? {}), [column]: message };
    } else if (['yield', 'effective_from', 'variant_ids', 'lines'].includes(field)) {
      fieldErrors[field] = message;
    } else {
      alertMessage.value = message;
    }
  });
}

async function submit() {
  alertMessage.value = '';
  if (!validate()) return;
  const order = [...orderedLines.value];
  const payload = {
    yield: Number(yieldQty.value),
    effective_from: effectiveFrom.value,
    variant_ids: [...variantIds.value],
    lines: order.map((l) => ({ material_id: l.material_id, quantity: Number(l.quantity), shrinkage_pct: l.shrinkage === '' ? 0 : Number(l.shrinkage) })),
    note: note.value.trim() || null,
  };

  submitting.value = true;
  try {
    const recipe = await createRecipe(props.product.id, payload);
    toast.success(t('products.rc_saved', { n: recipe?.version ?? nextVersion.value }));
    emit('saved', recipe);
  } catch (err) {
    if (err?.isValidation) applyServerErrors(err.errors, order);
    else if (err?.isConflict) {
      const skus = (err.data?.variants ?? []).map((v) => v.sku).filter(Boolean).join(', ');
      alertMessage.value = skus ? `${err.message} ${t('products.rc_conflict_skus', { skus })}` : err.message;
    } else if (err?.status !== 403) alertMessage.value = err?.message || t('products.rc_failed');
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <FormDrawer
    :open="open"
    :title="t('products.rc_title')"
    :subtitle="product?.name ?? ''"
    :submit-label="t('products.rc_submit', { n: nextVersion })"
    :submitting="submitting"
    max-width-class="max-w-[960px]"
    @close="emit('close')"
    @submit="submit"
  >
    <LoadingState v-if="loadState === 'loading'" :rows="6" />
    <ErrorState v-else-if="loadState === 'error'" :message="t('products.rc_load_failed')" @retry="load" />

    <div v-else class="flex flex-col gap-5">
      <p class="border-2 border-line bg-surface-subtle px-3.5 py-2.5 text-[12.5px] leading-relaxed text-muted-5" data-testid="rc-versioning">{{ t('products.rc_versioning') }}</p>
      <p v-if="alertMessage" role="alert" class="border-2 border-danger-border bg-danger-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-danger-text" data-testid="rc-alert">{{ alertMessage }}</p>

      <div class="grid grid-cols-2 gap-3.5">
        <div class="flex items-start gap-2">
          <div class="flex-1">
            <BaseInput v-model="yieldQty" type="number" step="any" :label="t('products.rc_yield')" :error="fieldErrors.yield" />
          </div>
          <span class="pt-[34px] text-[13px] text-muted-4">{{ t('products.rc_yield_unit') }}</span>
        </div>
        <BaseInput
          v-model="effectiveFrom"
          type="date"
          :label="t('products.rc_effective')"
          :hint="t('products.rc_effective_hint', { days: MAX_BACKDATE_DAYS })"
          :error="fieldErrors.effective_from"
        />
      </div>

      <fieldset class="flex flex-col gap-2" data-testid="rc-variants">
        <legend class="mb-1 text-[12.5px] font-semibold text-muted-4">{{ t('products.rc_variants') }}</legend>
        <label v-for="v in variants" :key="v.id" class="flex flex-wrap items-center gap-2 text-[13px]">
          <input v-model="variantIds" type="checkbox" :value="v.id" class="h-4 w-4 accent-brand" />
          <span class="font-semibold">{{ variantName(v) }}</span>
          <span class="font-mono text-[11.5px] text-muted-4">{{ v.sku }}</span>
          <span v-if="v.is_active === false" class="text-[11.5px] text-muted-4">({{ t('products.rc_variant_inactive') }})</span>
          <span v-if="v.bom_complete" class="text-[11.5px] font-semibold text-warn-text" data-testid="rc-bom-note">{{ t('products.rc_variant_bom_note') }}</span>
        </label>
        <p v-if="fieldErrors.variant_ids" class="text-[12px] font-medium text-danger-text" role="alert">{{ fieldErrors.variant_ids }}</p>
      </fieldset>

      <div class="flex flex-col gap-1.5" data-testid="rc-materials">
        <p class="text-[11.5px] font-bold uppercase tracking-wide text-muted">{{ t('products.rc_section_label') }}</p>
        <p :id="tableLabelId" class="text-[13px] font-semibold text-ink">{{ t('products.rc_materials_label') }}<span class="text-danger-text" aria-hidden="true"> *</span></p>
        <!-- Satu tabel (rancangan Back Office): jenis bahan tetap terkelompok, tetapi di dalam <select> lewat <optgroup>.
             Biaya baris yang belum diketahui tampil "—", tidak pernah nol diam-diam. -->
        <div class="overflow-x-auto border-2 border-line bg-white">
          <table class="w-full min-w-[620px] border-collapse text-left text-[13px]" :aria-labelledby="tableLabelId">
            <thead>
              <tr class="border-b border-line-2 bg-surface-subtle">
                <th scope="col" :class="CELL_HEAD">{{ t('products.rc_col_material_full') }}</th>
                <th scope="col" :class="[CELL_HEAD, 'w-24 border-l border-line-3 text-right']">{{ t('products.rc_col_qty_short') }}</th>
                <th scope="col" :class="[CELL_HEAD, 'w-20 border-l border-line-3 text-right']">{{ t('products.rc_col_uom') }}</th>
                <th scope="col" :class="[CELL_HEAD, 'w-24 border-l border-line-3 text-right']">{{ t('products.rc_col_shrink') }}</th>
                <th scope="col" :class="[CELL_HEAD, 'w-32 border-l border-line-3 text-right']">{{ t('products.rc_col_cost') }}</th>
                <th scope="col" aria-hidden="true" class="w-10 border-l border-line-3"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(l, i) in lines" :key="l.key" class="border-b border-line-2 align-top" data-testid="rc-line">
                <td class="p-0">
                  <select
                    :value="l.material_id ?? ''"
                    :class="cellInputClass({ error: !!lineErrors[l.key]?.material })"
                    :aria-label="t('products.rc_row_material_aria', { n: i + 1 })"
                    :aria-invalid="lineErrors[l.key]?.material ? 'true' : undefined"
                    @change="pickMaterial(l, $event.target.value)"
                  >
                    <option value="" disabled>{{ t('products.rc_material_placeholder') }}</option>
                    <option v-if="orphanOption(l)" :value="orphanOption(l).id">{{ orphanOption(l).name }}</option>
                    <optgroup v-for="g in materialGroups" :key="g.kind" :label="kindLabel(g.kind)">
                      <option v-for="m in g.items" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </optgroup>
                  </select>
                  <p v-if="lineErrors[l.key]?.material" role="alert" :class="CELL_ERROR">{{ lineErrors[l.key].material }}</p>
                </td>
                <td class="border-l border-line-3 p-0">
                  <input v-model="l.quantity" type="number" step="any" :class="cellInputClass({ number: true, error: !!lineErrors[l.key]?.quantity })" :aria-label="t('products.rc_row_qty_aria', { n: i + 1 })" :aria-invalid="lineErrors[l.key]?.quantity ? 'true' : undefined" />
                  <p v-if="lineErrors[l.key]?.quantity" role="alert" :class="[CELL_ERROR, 'text-right']">{{ lineErrors[l.key].quantity }}</p>
                </td>
                <!-- Satuan = satuan bahan terpilih, teks biasa (tidak ada konversi satuan). -->
                <td class="border-l border-line-3 px-3 py-3 text-right font-semibold text-muted-4" data-testid="rc-unit">{{ l.unit || '—' }}</td>
                <td class="border-l border-line-3 p-0">
                  <input v-model="l.shrinkage" type="number" step="any" :class="cellInputClass({ number: true, error: !!lineErrors[l.key]?.shrinkage })" :aria-label="t('products.rc_row_shrink_aria', { n: i + 1 })" :aria-invalid="lineErrors[l.key]?.shrinkage ? 'true' : undefined" />
                  <p v-if="lineErrors[l.key]?.shrinkage" role="alert" :class="[CELL_ERROR, 'text-right']">{{ lineErrors[l.key].shrinkage }}</p>
                </td>
                <td class="border-l border-line-3 px-3 py-3 text-right font-bold tabular-nums" data-testid="rc-line-cost" :title="lineCostText(l) === '—' ? t('products.rc_cost_unknown') : undefined">{{ lineCostText(l) }}</td>
                <td class="border-l border-line-3 px-1 py-1.5 text-center">
                  <button type="button" :class="ROW_REMOVE" :aria-label="t('products.rc_row_remove_aria', { n: i + 1 })" @click="removeLine(l)">
                    <i class="ph-duotone ph-x text-[14px]" aria-hidden="true"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <div class="flex items-center justify-between gap-3 px-3 py-2.5" data-testid="rc-footer">
            <button type="button" :class="TABLE_LINK" data-testid="rc-add" @click="addLine">{{ t('products.rc_add_line') }}</button>
            <p class="flex gap-1.5 text-[13px] font-bold tabular-nums" data-testid="rc-totals">
              <span>{{ preview.incomplete ? t('products.rc_portion_cost_partial') : t('products.rc_portion_cost') }}</span>
              <span data-testid="rc-portion-cost">{{ formatIDR(preview.portionCost) }}</span>
            </p>
          </div>
        </div>
        <p v-if="fieldErrors.lines" class="text-[12px] font-medium text-danger-text" role="alert">{{ fieldErrors.lines }}</p>
        <!-- Food cost dan margin: tenang di bawah tabel; "—" selama ada bahan tanpa harga (tidak dihitung dari data parsial). -->
        <p class="text-[12.5px] text-muted-4">
          {{ t('products.rc_food_cost') }} <span class="font-semibold tabular-nums text-ink" data-testid="rc-food-cost">{{ preview.foodCostPct === null ? '—' : `${preview.foodCostPct.toFixed(1)}%` }}</span>
          · {{ t('products.rc_margin') }} <span class="font-semibold tabular-nums text-ink" data-testid="rc-margin">{{ preview.grossMargin === null ? '—' : formatIDR(preview.grossMargin) }}</span>
        </p>
        <p v-if="incompleteCount" role="status" class="text-[12.5px] font-semibold text-warn-text" data-testid="rc-incomplete">
          {{ t('products.rc_incomplete_notice', { count: incompleteCount }) }}
        </p>
      </div>

      <BaseTextarea v-model="note" :label="t('products.rc_note')" :rows="2" />
    </div>
  </FormDrawer>
</template>
