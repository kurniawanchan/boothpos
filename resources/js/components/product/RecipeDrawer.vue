<script setup>
import { computed, reactive, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { createRecipe, getRecipe, listRecipeModifierOptions, updateRecipe, listRecipeIngredients } from '../../api/recipes';
import { ingredientKey, isWholePerPortion, lineFromServer, linePayload, parseIngredientKey, productOptionLabel } from '../../utils/recipeIngredients';
import { getMaterial, listMaterials } from '../../api/materials';
import { useToastStore } from '../../stores/toast';
import { formatIDR } from '../../utils/formatters';
import { variantName, variantPrice } from '../../utils/itemDetail';
import { computeRecipeCost, referencePrice } from '../../utils/recipeCost';
import FormDrawer from '../page/FormDrawer.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import ErrorState from '../ui/ErrorState.vue';
import LoadingState from '../ui/LoadingState.vue';
import { CELL_ERROR, CELL_HEAD, ROW_REMOVE, TABLE_LINK, cellInputClass } from './cellStyles';

/**
 * 043 (US8), diubah 089 — laci "Resep": membuat satu resep (`POST /products/{id}/recipes`) atau mengubahnya di tempat
 * (`PUT /recipes/{id}`); resep tidak lagi berversi. Satu varian boleh ada di banyak resep (semuanya dijumlahkan saat
 * penjualan), jadi varian yang sudah tertaut ke resep lain tetap boleh dicentang. Dibuka dari bagian Resep hanya untuk
 * pemegang izin `menu.recipe.edit`; server tetap menolak yang lain.
 *
 * - Stok bahan dipotong saat PESANAN SELESAI (bukan saat resep disimpan); catatan di atas formulir menjelaskannya.
 * - Satuan baris = satuan bahan (read-only): tidak ada konversi satuan.
 * - Bahan = SATU tabel "Bahan per porsi" (rancangan Back Office). Pengelompokan per jenis (bahan baku / setengah jadi /
 *   kemasan) dipertahankan DI DALAM pemilih (BaseSelect, ber-kotak-cari) lewat judul kelompok, bukan lagi tiga kotak terpisah. Urutan kirim = urutan baris
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
 * - Mengubah resep memuat isinya dari `GET /recipes/{id}`; resep baru mulai kosong.
 *
 * 2026-11-02 (permintaan pemilik) — bahan kini berupa BEBERAPA tabel ("blok"), satu per (varian, pilihan modifier). Pengguna memilih
 *   varian, lalu pilihan modifier (atau "tanpa modifier" = resep dasar varian); tabel bahan aktif setelah varian dipilih. Pasangan
 *   (varian, pilihan) yang sudah dipakai blok lain tidak bisa dipilih lagi. Server memotong blok bermodifier hanya bila
 *   pilihannya dipesan. Resep baru dimulai dengan satu blok dasar per varian aktif (bukan BOM-selesai).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  product: { type: Object, default: null }, // { id, name, variants }
  recipe: { type: Object, default: null }, // baris daftar yang diubah; null = resep baru
});
const emit = defineEmits(['close', 'saved', 'remove']);
const { t } = useI18n();
const toast = useToastStore();

const KINDS = ['ingredient', 'semi_finished', 'packaging'];
const MAX_BACKDATE_DAYS = 30;

const tableLabelId = `rc-table-${useId()}`;
const loadState = ref('loading'); // loading | ready | error
const materials = ref([]);
const ingredients = ref([]); // 093: varian produk bertanda bahan (GET /recipe-ingredients)
const unitCosts = reactive({}); // kunci bahan (`m12` bahan baku / `v34` produk) → number | null (null = diketahui TIDAK punya harga)
const recipeName = ref('');
const yieldQty = ref('1');
const effectiveFrom = ref('');
const blocks = ref([]); // { key, variant_id, modifier_option_id (null = tanpa modifier), lines: [] }
const modifierGroups = ref([]); // [{ id, name, options: [{ id, name }] }]
const note = ref('');
const submitting = ref(false);
const alertMessage = ref('');
const fieldErrors = reactive({});
const lineErrors = reactive({}); // { [line.key]: { material, quantity, shrinkage } }
const blockErrors = reactive({}); // { [block.key]: { variant, modifier, lines } }
let keySeq = 0;
let loadSeq = 0;

const isEdit = computed(() => !!props.recipe);
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

/** 093: semua halaman produk bahan (sama dengan pola bahan baku di atas). Gagal → kosong, resep bahan baku tetap bisa dibuat. */
async function loadAllIngredients() {
  if (!props.product?.id) return [];
  const all = [];
  let page = 1;
  let last = 1;
  do {
    const res = await listRecipeIngredients({ exclude_product_id: props.product.id, per_page: 100, page });
    all.push(...(res?.data ?? []));
    last = res?.meta?.last_page ?? 1;
    page += 1;
  } while (page <= last && page <= 20);
  return all;
}

const lineFromRecipe = (l) => lineFromServer(l, () => `l${++keySeq}`);

async function load() {
  const mine = ++loadSeq;
  loadState.value = 'loading';
  alertMessage.value = '';
  Object.keys(fieldErrors).forEach((k) => delete fieldErrors[k]);
  Object.keys(lineErrors).forEach((k) => delete lineErrors[k]);
  Object.keys(blockErrors).forEach((k) => delete blockErrors[k]);
  try {
    const [mats, recipe, groups, products] = await Promise.all([
      loadAllMaterials(),
      props.recipe ? getRecipe(props.recipe.id) : Promise.resolve(null),
      props.product?.id ? listRecipeModifierOptions(props.product.id).catch(() => []) : Promise.resolve([]),
      loadAllIngredients().catch(() => []),
    ]);
    if (mine !== loadSeq) return;
    materials.value = mats;
    ingredients.value = products ?? [];
    modifierGroups.value = groups ?? [];
    Object.keys(unitCosts).forEach((k) => delete unitCosts[k]);
    effectiveFrom.value = recipe?.effective_from ?? today();
    note.value = recipe?.note ?? '';

    if (recipe) {
      recipeName.value = recipe.name ?? '';
      yieldQty.value = String(Number(recipe.yield));
      const existing = new Set(variants.value.map((v) => v.id));
      // Server lama/ringkas tanpa `blocks`: satu blok dasar per varian dengan bahan yang sama.
      const source = recipe.blocks ?? (recipe.variants ?? []).map((v) => ({ variant_id: v.id, modifier_option_id: null, lines: recipe.lines ?? [] }));
      blocks.value = source.filter((b) => existing.has(b.variant_id)).map((b) => newBlock(b.variant_id, b.modifier_option_id ?? null, (b.lines ?? []).map(lineFromRecipe)));
      source.forEach((b) => (b.lines ?? []).forEach((l) => {
        if (l.unit_cost !== undefined && l.unit_cost !== null) unitCosts[l.variant_id ? `v${l.variant_id}` : `m${l.material_id}`] = Number(l.unit_cost);
      }));
    } else {
      // Resep baru: nama bawaan = nama produk (boleh diubah; server tidak mewajibkan nama unik per produk, dan maks 100 karakter).
      recipeName.value = (props.product?.name ?? '').slice(0, 100);
      yieldQty.value = '1';
      // "Berdasarkan jumlah varian": satu tabel dasar per varian aktif yang bukan BOM-selesai.
      blocks.value = variants.value.filter((v) => v.is_active !== false && !v.bom_complete).map((v) => newBlock(v.id, null, []));
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

/** Kelompok pilihan bahan per jenis; jenis tak dikenal jatuh ke bahan baku (sama seperti server). */
const materialGroups = computed(() =>
  KINDS.map((kind) => ({ kind, items: materials.value.filter((m) => (KINDS.includes(m.kind) ? m.kind : 'ingredient') === kind) })).filter((g) => g.items.length),
);
// Bahan baris yang sudah tak aktif (tak ada di daftar) tetap harus tampil sebagai pilihan terpilih.
const orphanOption = (line) => (line.material_id && !materials.value.some((m) => m.id === line.material_id) ? { id: line.material_id, name: line.name || String(line.material_id) } : null);

// Opsi untuk BaseSelect (pemilih bawaan aplikasi, dengan kotak cari). `disabled` = sudah dipakai tabel lain; `group` = judul kelompok.
const variantOptions = computed(() => variants.value.map((v) => ({
  value: v.id,
  label: `${variantName(v)} · ${v.sku}${v.is_active === false ? ` (${t('products.rc_variant_inactive')})` : ''}`,
})));
const variantChoices = (block) => variantOptions.value.map((o) => ({ ...o, disabled: variantTaken(block, o.value) }));
const modifierChoices = (block) => [
  { value: '', label: t('products.rc_block_no_modifier'), disabled: optionTaken(block, null) },
  ...modifierGroups.value.flatMap((g) => g.options.map((o) => ({ value: o.id, label: o.name, group: g.name, disabled: optionTaken(block, o.id) }))),
];
// 093: nilai opsi = kunci bahan (`m12` / `v34`). Baris produk lama yang penandanya sudah mati (atau tak ada di daftar)
// tetap tampil sebagai pilihan terpilih, diberi catatan, supaya resep lama bisa dibuka dan disimpan ulang.
const productOrphan = (line) => (line.variant_id && !ingredients.value.some((p) => p.variant_id === line.variant_id)
  ? { value: `v${line.variant_id}`, label: `${line.name || line.sku || line.variant_id} (${t('products.rc_product_unflagged')})`, group: t('products.rc_kind_product') }
  : null);
const materialChoices = (line) => [
  ...(orphanOption(line) ? [{ value: `m${orphanOption(line).id}`, label: orphanOption(line).name }] : []),
  ...(productOrphan(line) ? [productOrphan(line)] : []),
  ...materialGroups.value.flatMap((g) => g.items.map((m) => ({ value: `m${m.id}`, label: m.name, group: kindLabel(g.kind) }))),
  ...ingredients.value.map((p) => ({ value: `v${p.variant_id}`, label: productOptionLabel(p, t), group: t('products.rc_kind_product') })),
];

function newBlock(variantId, optionId, blockLines) {
  return { key: `b${++keySeq}`, variant_id: variantId, modifier_option_id: optionId, lines: blockLines };
}

function addLine(block) {
  block.lines.push({ key: `l${++keySeq}`, material_id: null, variant_id: null, name: '', unit: '', sku: '', flagged: true, quantity: '', shrinkage: '0' });
}

function removeLine(block, line) {
  block.lines = block.lines.filter((l) => l !== line);
  delete lineErrors[line.key];
}

// --- blok (varian + modifier) ---------------------------------------------------------------------------------------
/** Kunci pasangan; `base` = tanpa modifier. */
const pairKey = (variantId, optionId) => `${variantId}:${optionId ?? 'base'}`;
const usedByOthers = (block) => new Set(blocks.value.filter((b) => b !== block && b.variant_id).map((b) => pairKey(b.variant_id, b.modifier_option_id)));
const modifierOptionCount = computed(() => modifierGroups.value.reduce((n, g) => n + g.options.length, 0));
/** Varian tak bisa dipilih lagi bila semua pasangannya (dasar + tiap pilihan) sudah dipakai blok lain. */
function variantTaken(block, variantId) {
  const used = usedByOthers(block);
  const optionIds = modifierGroups.value.flatMap((g) => g.options.map((o) => o.id));
  return used.has(pairKey(variantId, null)) && optionIds.every((id) => used.has(pairKey(variantId, id)));
}
const optionTaken = (block, optionId) => block.variant_id !== null && usedByOthers(block).has(pairKey(block.variant_id, optionId));

/** Pilih varian: modifier jatuh ke pasangan pertama yang masih bebas (dasar dulu). */
function pickVariant(block, raw) {
  const id = raw === '' ? null : Number(raw);
  block.variant_id = id;
  delete blockErrors[block.key]?.variant;
  if (id === null) return;
  const optionIds = modifierGroups.value.flatMap((g) => g.options.map((o) => o.id));
  block.modifier_option_id = [null, ...optionIds].find((o) => !optionTaken(block, o)) ?? null;
}

function pickModifier(block, raw) {
  block.modifier_option_id = raw === '' ? null : Number(raw);
  delete blockErrors[block.key]?.modifier;
}

function addBlock() {
  blocks.value.push(newBlock(null, null, []));
}

function removeBlock(block) {
  blocks.value = blocks.value.filter((b) => b !== block);
  block.lines.forEach((l) => delete lineErrors[l.key]);
  delete blockErrors[block.key];
}

const variantOf = (block) => variants.value.find((v) => v.id === block.variant_id) ?? null;

/** Biaya satuan sebuah bahan: harga vendor acuan, selain itu `standard_cost` bahan setengah jadi (cermin RecipeService::unitCost). */
async function resolveUnitCost(material) {
  if (Object.prototype.hasOwnProperty.call(unitCosts, `m${material.id}`)) return;
  let cost = null;
  try {
    const full = await getMaterial(material.id);
    cost = referencePrice(full?.vendor_prices);
    if (cost === null && (full?.kind ?? material.kind) === 'semi_finished' && full?.standard_cost !== null && full?.standard_cost !== undefined) cost = Number(full.standard_cost);
  } catch {
    cost = null; // harga tak bisa dipastikan → baris ditandai belum lengkap, bukan nol
  }
  unitCosts[`m${material.id}`] = cost;
}

function pickMaterial(line, rawValue) {
  const parsed = parseIngredientKey(rawValue);
  // Placeholder "Pilih bahan" (nilai kosong) tidak mengosongkan pilihan lama: baris tanpa bahan ditolak validate().
  if (!parsed) return;
  if (parsed.type === 'product') {
    const p = ingredients.value.find((x) => x.variant_id === parsed.id);
    if (!p) return; // baris produk lama yang sudah tak bertanda: pilihan terpilihnya sendiri, tak ada yang diubah
    line.variant_id = p.variant_id;
    line.material_id = null;
    line.name = p.product_name;
    line.unit = p.unit ?? '';
    line.sku = p.sku;
    line.flagged = true;
    line.shrinkage = '0'; // produk bahan tidak memakai susut
    delete lineErrors[line.key]?.material;
    return;
  }
  const m = materials.value.find((x) => x.id === parsed.id);
  if (!m) return;
  line.material_id = m.id;
  line.variant_id = null;
  line.name = m.name;
  line.unit = m.unit ?? '';
  line.sku = '';
  delete lineErrors[line.key]?.material;
  resolveUnitCost(m);
}

// Biaya baris produk baru belum diketahui di pratinjau (daftar pilihan tidak membawa harga pokok); baris yang sudah
// tersimpan membawa `unit_cost` dari server, dan server selalu menghitung biaya yang sebenarnya.
const unitCostOf = (line) => {
  const k = ingredientKey(line);

  return k !== '' && Object.prototype.hasOwnProperty.call(unitCosts, k) ? unitCosts[k] : null;
};

// --- pratinjau biaya -------------------------------------------------------------------------------------------
/** Biaya per BLOK (tiap tabel punya total sendiri); harga jual acuan = varian blok itu. */
function previewOf(block) {
  const v = variantOf(block);
  const price = v && variantPrice(v) !== null ? Number(variantPrice(v)) : null;

  return computeRecipeCost(
    block.lines.map((l) => ({ quantity: l.quantity, shrinkage_pct: l.variant_id ? 0 : l.shrinkage, unit_cost: ingredientKey(l) === '' ? null : unitCostOf(l) })),
    Number(yieldQty.value),
    price,
  );
}
const lineCostText = (block, line) => {
  const c = previewOf(block).lineCosts[block.lines.indexOf(line)];
  return c === null || c === undefined ? '—' : formatIDR(c);
};
const incompleteCount = computed(() => blocks.value.reduce((n, b) => n + previewOf(b).unknownCount, 0));

// --- validasi & simpan ---------------------------------------------------------------------------------------------
function validate() {
  Object.keys(fieldErrors).forEach((k) => delete fieldErrors[k]);
  Object.keys(lineErrors).forEach((k) => delete lineErrors[k]);
  Object.keys(blockErrors).forEach((k) => delete blockErrors[k]);
  let ok = true;
  if (!recipeName.value.trim()) {
    fieldErrors.name = t('products.rc_err_name');
    ok = false;
  }
  const y = Number(yieldQty.value);
  if (yieldQty.value === '' || !Number.isFinite(y) || y <= 0) {
    fieldErrors.yield = t('products.rc_err_yield');
    ok = false;
  }
  // Resep yang sudah ada boleh disimpan dengan tanggal berlakunya yang lama; hanya tanggal yang DIUBAH dibatasi 30 hari.
  const dateUnchanged = isEdit.value && effectiveFrom.value === props.recipe?.effective_from;
  if (!effectiveFrom.value || (!dateUnchanged && effectiveFrom.value < minDate())) {
    fieldErrors.effective_from = t('products.rc_err_date', { days: MAX_BACKDATE_DAYS });
    ok = false;
  }
  if (!blocks.value.length) {
    fieldErrors.blocks = t('products.rc_variants_required');
    ok = false;
  }
  const seen = new Set();
  blocks.value.forEach((b) => {
    const be = {};
    if (b.variant_id === null) be.variant = t('products.rc_variants_required');
    else {
      const k = pairKey(b.variant_id, b.modifier_option_id);
      if (seen.has(k)) be.modifier = t('products.rc_block_duplicate');
      seen.add(k);
    }
    if (!b.lines.length) be.lines = t('products.rc_err_lines');
    if (Object.keys(be).length) {
      blockErrors[b.key] = be;
      ok = false;
    }
    b.lines.forEach((l) => {
      const e = {};
      if (ingredientKey(l) === '') e.material = t('products.rc_err_material');
      const q = l.quantity === '' ? NaN : Number(l.quantity);
      if (!Number.isFinite(q) || q <= 0) e.quantity = t('products.rc_err_qty');
      else if (l.variant_id && !isWholePerPortion(q, y)) e.quantity = t('products.rc_err_whole');
      const sh = l.shrinkage === '' ? 0 : Number(l.shrinkage);
      if (!l.variant_id && (!Number.isFinite(sh) || sh < 0 || sh > 99.99)) e.shrinkage = t('products.rc_err_shrink');
      if (Object.keys(e).length) {
        lineErrors[l.key] = e;
        ok = false;
      }
    });
  });
  return ok;
}

function applyServerErrors(errors, order) {
  Object.entries(errors ?? {}).forEach(([field, messages]) => {
    const message = Array.isArray(messages) ? messages[0] : messages;
    const line = /^blocks\.(\d+)\.lines\.(\d+)\.(material_id|variant_id|quantity|shrinkage_pct)$/.exec(field);
    const blk = /^blocks\.(\d+)\.(variant_id|modifier_option_id|lines)$/.exec(field);
    if (line) {
      const target = order[Number(line[1])]?.lines[Number(line[2])];
      if (!target) return;
      const column = line[3] === 'material_id' || line[3] === 'variant_id' ? 'material' : line[3] === 'quantity' ? 'quantity' : 'shrinkage';
      lineErrors[target.key] = { ...(lineErrors[target.key] ?? {}), [column]: message };
    } else if (blk) {
      const target = order[Number(blk[1])];
      if (!target) return;
      const column = blk[2] === 'variant_id' ? 'variant' : blk[2] === 'modifier_option_id' ? 'modifier' : 'lines';
      blockErrors[target.key] = { ...(blockErrors[target.key] ?? {}), [column]: message };
    } else if (['name', 'yield', 'effective_from', 'blocks'].includes(field)) {
      fieldErrors[field] = message;
    } else {
      alertMessage.value = message;
    }
  });
}

async function submit() {
  alertMessage.value = '';
  if (!validate()) return;
  const order = [...blocks.value];
  const payload = {
    name: recipeName.value.trim(),
    yield: Number(yieldQty.value),
    effective_from: effectiveFrom.value,
    blocks: order.map((b) => ({
      variant_id: b.variant_id,
      modifier_option_id: b.modifier_option_id,
      lines: b.lines.map(linePayload),
    })),
    note: note.value.trim() || null,
  };

  submitting.value = true;
  try {
    const recipe = isEdit.value ? await updateRecipe(props.recipe.id, payload) : await createRecipe(props.product.id, payload);
    toast.success(t('products.rc_saved'));
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
    :title="isEdit ? t('products.rc_title_edit') : t('products.rc_title')"
    :subtitle="product?.name ?? ''"
    :submit-label="t('products.rc_submit')"
    :submitting="submitting"
    max-width-class="max-w-[960px]"
    @close="emit('close')"
    @submit="submit"
  >
    <template v-if="isEdit" #footer-start>
      <BaseButton variant="danger" data-testid="rc-remove" @click="emit('remove')">{{ t('products.rc_remove') }}</BaseButton>
    </template>
    <LoadingState v-if="loadState === 'loading'" :rows="6" />
    <ErrorState v-else-if="loadState === 'error'" :message="t('products.rc_load_failed')" @retry="load" />

    <div v-else class="flex flex-col gap-5">
      <p class="border-2 border-line bg-surface-subtle px-3.5 py-2.5 text-[12.5px] leading-relaxed text-muted-5" data-testid="rc-stock-note">{{ t('products.rc_stock_note') }}</p>
      <p v-if="alertMessage" role="alert" class="border-2 border-danger-border bg-danger-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-danger-text" data-testid="rc-alert">{{ alertMessage }}</p>

      <BaseInput v-model="recipeName" type="text" maxlength="100" :label="t('products.rc_name')" :hint="t('products.rc_name_hint')" :error="fieldErrors.name" data-testid="rc-name" />

      <div class="grid grid-cols-2 gap-3.5">
        <!-- 089: label "Portions"; tanpa satuan di samping isian (angkanya sudah jelas dari labelnya). -->
        <BaseInput v-model="yieldQty" type="number" step="any" :label="t('products.rc_yield')" :error="fieldErrors.yield" />
        <BaseInput
          v-model="effectiveFrom"
          type="date"
          :label="t('products.rc_effective')"
          :hint="t('products.rc_effective_hint', { days: MAX_BACKDATE_DAYS })"
          :error="fieldErrors.effective_from"
        />
      </div>

      <div class="flex flex-col gap-4" data-testid="rc-blocks">
        <p class="text-[11.5px] font-bold uppercase tracking-wide text-muted">{{ t('products.rc_section_label') }}</p>
        <p v-if="fieldErrors.blocks" class="text-[12px] font-medium text-danger-text" role="alert">{{ fieldErrors.blocks }}</p>

        <!-- Satu kartu = satu tabel bahan untuk SATU (varian, pilihan modifier). Tabel baru aktif setelah varian dipilih. -->
        <section v-for="(b, bi) in blocks" :key="b.key" class="flex flex-col gap-3 border-2 border-line bg-white p-3.5" data-testid="rc-block">
          <div class="flex flex-wrap items-start gap-3">
            <div class="flex min-w-[200px] flex-1 flex-col gap-1" data-testid="rc-block-variant">
              <span class="text-[12.5px] font-semibold text-muted-4">{{ t('products.rc_block_variant') }}</span>
              <BaseSelect
                :model-value="b.variant_id ?? ''"
                :options="variantChoices(b)"
                :placeholder="t('products.rc_block_variant_placeholder')"
                :aria-label="t('products.rc_block_variant_aria', { n: bi + 1 })"
                :error="blockErrors[b.key]?.variant ?? ''"
                searchable
                @update:model-value="pickVariant(b, $event)"
              />
            </div>
            <div class="flex min-w-[200px] flex-1 flex-col gap-1" data-testid="rc-block-modifier">
              <span class="text-[12.5px] font-semibold text-muted-4">{{ t('products.rc_block_modifier') }}</span>
              <BaseSelect
                :model-value="b.modifier_option_id ?? ''"
                :options="modifierChoices(b)"
                :disabled="b.variant_id === null"
                :aria-label="t('products.rc_block_modifier_aria', { n: bi + 1 })"
                :error="blockErrors[b.key]?.modifier ?? ''"
                searchable
                @update:model-value="pickModifier(b, $event)"
              />
            </div>
            <button type="button" class="mt-6 flex h-8 w-8 items-center justify-center rounded-md border border-line text-muted-4 hover:bg-surface-subtle" data-testid="rc-block-remove" :aria-label="t('products.rc_block_remove_aria', { n: bi + 1 })" @click="removeBlock(b)">
              <i class="ph-duotone ph-trash text-[15px]" aria-hidden="true"></i>
            </button>
          </div>
          <p v-if="variantOf(b)?.bom_complete" class="text-[11.5px] font-semibold text-warn-text" data-testid="rc-bom-note">{{ t('products.rc_variant_bom_note') }}</p>
          <p v-if="b.variant_id !== null && b.modifier_option_id !== null && !modifierOptionCount" class="text-[11.5px] text-muted-4">{{ t('products.rc_block_no_options') }}</p>

          <p v-if="b.variant_id === null" class="border border-dashed border-line px-3 py-3 text-[12.5px] text-muted-4" data-testid="rc-block-inactive">{{ t('products.rc_block_pick_variant') }}</p>

          <div v-else class="flex flex-col gap-1.5" data-testid="rc-materials">
            <p :id="`${tableLabelId}-${b.key}`" class="text-[13px] font-semibold text-ink">{{ t('products.rc_materials_label') }}<span class="text-danger-text" aria-hidden="true"> *</span></p>
            <!-- Satu tabel (rancangan Back Office): jenis bahan tetap terkelompok, tetapi di dalam pemilih lewat judul kelompok.
                 Biaya baris yang belum diketahui tampil "—", tidak pernah nol diam-diam. -->
            <div class="overflow-x-auto border-2 border-line bg-white">
              <table class="w-full min-w-[620px] border-collapse text-left text-[13px]" :aria-labelledby="`${tableLabelId}-${b.key}`">
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
                  <tr v-for="(l, i) in b.lines" :key="l.key" class="border-b border-line-2 align-top" data-testid="rc-line">
                    <td class="p-0">
                      <BaseSelect
                        cell
                        :model-value="ingredientKey(l)"
                        :options="materialChoices(l)"
                        :placeholder="t('products.rc_material_placeholder')"
                        :aria-label="t('products.rc_row_material_aria', { n: i + 1 })"
                        :error="lineErrors[l.key]?.material ?? ''"
                        searchable
                        @update:model-value="pickMaterial(l, $event)"
                      />
                    </td>
                    <td class="border-l border-line-3 p-0">
                      <input v-model="l.quantity" type="number" step="any" :class="cellInputClass({ number: true, error: !!lineErrors[l.key]?.quantity })" :aria-label="t('products.rc_row_qty_aria', { n: i + 1 })" :aria-invalid="lineErrors[l.key]?.quantity ? 'true' : undefined" />
                      <p v-if="lineErrors[l.key]?.quantity" role="alert" :class="[CELL_ERROR, 'text-right']">{{ lineErrors[l.key].quantity }}</p>
                    </td>
                    <!-- Satuan = satuan bahan terpilih, teks biasa (tidak ada konversi satuan). -->
                    <td class="border-l border-line-3 px-3 py-3 text-right font-semibold text-muted-4" data-testid="rc-unit">{{ l.unit || '—' }}</td>
                    <!-- 093: bahan berupa produk tidak memakai susut (stok varian bilangan bulat). -->
                    <td v-if="l.variant_id" class="border-l border-line-3 px-3 py-3 text-right text-muted-4" data-testid="rc-shrink-na">—</td>
                    <td v-else class="border-l border-line-3 p-0">
                      <input v-model="l.shrinkage" type="number" step="any" :class="cellInputClass({ number: true, error: !!lineErrors[l.key]?.shrinkage })" :aria-label="t('products.rc_row_shrink_aria', { n: i + 1 })" :aria-invalid="lineErrors[l.key]?.shrinkage ? 'true' : undefined" />
                      <p v-if="lineErrors[l.key]?.shrinkage" role="alert" :class="[CELL_ERROR, 'text-right']">{{ lineErrors[l.key].shrinkage }}</p>
                    </td>
                    <td class="border-l border-line-3 px-3 py-3 text-right font-bold tabular-nums" data-testid="rc-line-cost" :title="lineCostText(b, l) === '—' ? t('products.rc_cost_unknown') : undefined">{{ lineCostText(b, l) }}</td>
                    <td class="border-l border-line-3 px-1 py-1.5 text-center">
                      <button type="button" :class="ROW_REMOVE" :aria-label="t('products.rc_row_remove_aria', { n: i + 1 })" @click="removeLine(b, l)">
                        <i class="ph-duotone ph-x text-[14px]" aria-hidden="true"></i>
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
              <div class="flex items-center justify-between gap-3 px-3 py-2.5" data-testid="rc-footer">
                <button type="button" :class="TABLE_LINK" data-testid="rc-add" @click="addLine(b)">{{ t('products.rc_add_line') }}</button>
                <p class="flex gap-1.5 text-[13px] font-bold tabular-nums" data-testid="rc-totals">
                  <span>{{ previewOf(b).incomplete ? t('products.rc_portion_cost_partial') : t('products.rc_portion_cost') }}</span>
                  <span data-testid="rc-portion-cost">{{ formatIDR(previewOf(b).portionCost) }}</span>
                </p>
              </div>
            </div>
            <p v-if="blockErrors[b.key]?.lines" class="text-[12px] font-medium text-danger-text" role="alert">{{ blockErrors[b.key].lines }}</p>
            <!-- Food cost dan margin: tenang di bawah tabel; "—" selama ada bahan tanpa harga (tidak dihitung dari data parsial). -->
            <p class="text-[12.5px] text-muted-4">
              {{ t('products.rc_food_cost') }} <span class="font-semibold tabular-nums text-ink" data-testid="rc-food-cost">{{ previewOf(b).foodCostPct === null ? '—' : `${previewOf(b).foodCostPct.toFixed(1)}%` }}</span>
              · {{ t('products.rc_margin') }} <span class="font-semibold tabular-nums text-ink" data-testid="rc-margin">{{ previewOf(b).grossMargin === null ? '—' : formatIDR(previewOf(b).grossMargin) }}</span>
            </p>
          </div>
        </section>

        <div>
          <button type="button" :class="TABLE_LINK" data-testid="rc-add-block" @click="addBlock">{{ t('products.rc_add_block') }}</button>
        </div>
        <p v-if="incompleteCount" role="status" class="text-[12.5px] font-semibold text-warn-text" data-testid="rc-incomplete">
          {{ t('products.rc_incomplete_notice', { count: incompleteCount }) }}
        </p>
      </div>

      <BaseTextarea v-model="note" :label="t('products.rc_note')" :rows="2" />
    </div>
  </FormDrawer>
</template>
