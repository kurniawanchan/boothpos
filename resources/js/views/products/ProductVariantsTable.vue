<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR, parseMoney } from '../../utils/money';
import { cellInputClass } from './formStyle';
import BaseSelect from '../../components/ui/BaseSelect.vue';
import BaseTooltip from '../../components/ui/BaseTooltip.vue';
import ProductVariantImageCell from './ProductVariantImageCell.vue';

/**
 * 043 — SATU tabel "Varian & harga" untuk mode BUAT dan mode UBAH (formulir Menu item): VARIAN · SKU · GAMBAR ·
 * [HARGA MODAL] · HARGA DASAR · STOK · AKTIF · aksi, sel rata bergaris, tautan "+ Tambah varian" di dalam bingkai.
 * Menggantikan tabel khusus-buat dan kartu varian lama supaya kedua mode tidak punya dua tata letak yang menyimpang.
 *
 * `rows` adalah array reaktif milik drawer dan baris-barisnya sengaja DIMUTASI langsung lewat v-model (konvensi
 * lama kartu varian): drawer membaca baris yang sama saat menyimpan. Yang butuh dunia luar dipancarkan:
 * tambah/hapus/duplikat/buka-BOM/perbesar-gambar.
 *
 * - mode `create`: SKU hanya keterangan "Otomatis" (server yang membangkitkannya); kolom STOK = stok awal; tanpa
 *   kolom modal (harga modal item baru 0, diisi lewat BOM/PO). Aksi baris hanya × untuk baris yang boleh dibuang.
 * - mode `edit`: SKU asli, STOK = stok saat ini, kolom HARGA MODAL hanya bila `canSeeCost` (server juga membuang
 *   cost_price dari payload untuk pengguna tanpa izin biaya; hidden-not-disabled), dan satu baris kedua redup per
 *   varian untuk hal yang jarang disentuh: batas stok rendah, lacak stok, markup/margin, biaya BOM, penjelasan kunci
 *   harga modal, pilihan salin-BOM untuk varian baru, dan catatan duplikat.
 * - Varian tersimpan tidak pernah dihapus (tidak ada DELETE /variants/{id}) — hanya dinonaktifkan lewat kotak AKTIF;
 *   × hanya pada baris belum tersimpan.
 * - Harga modal varian ber-BOM SELESAI dikunci (server menolak edit manual dengan 409); selnya dinonaktifkan dan
 *   alasannya dijelaskan di baris kedua.
 */
const props = defineProps({
  rows: { type: Array, required: true },
  errors: { type: Object, default: () => ({}) },
  mode: { type: String, default: 'create', validator: (v) => ['create', 'edit'].includes(v) },
  canSeeCost: { type: Boolean, default: false },
  markupPercent: { type: Number, default: 150 },
  canCopyBomOnCreate: { type: Boolean, default: false },
  copySourceOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(['add', 'remove', 'duplicate', 'open-bom', 'enlarge-image']);
const { t } = useI18n();

const isEdit = computed(() => props.mode === 'edit');
const showCost = computed(() => isEdit.value && props.canSeeCost);
// Jumlah kolom (untuk baris kedua yang membentang): varian, SKU, gambar, [modal], harga, stok, aktif, aksi.
const colCount = computed(() => 7 + (showCost.value ? 1 : 0));

const errorOf = (i, field) => props.errors[`variants.${i}.${field}`] || '';
// Harga baris pertama = "Harga dasar" item, yang sudah menampilkan galatnya sendiri di bagian Harga & pajak —
// di sini tidak diulang (selnya tetap merah).
const lineError = (i) => errorOf(i, 'variant_name') || (i > 0 ? errorOf(i, 'sell_price') : '');

function applyMarkup(row) {
  const cost = parseMoney(row.cost_price);
  row.sell_price = Math.round(cost * (1 + props.markupPercent / 100)).toString();
}

// Markup (profit ÷ modal) dan margin (profit ÷ harga jual) adalah metrik berbeda menurut definisi — markup
// 50% selalu persis margin 33% berapa pun modalnya. Keduanya ditampilkan berdampingan supaya hubungan itu
// tidak disangka bug.
function marginFor(row) {
  const cost = parseMoney(row.cost_price);
  const sell = parseMoney(row.sell_price);
  if (sell <= 0) return null;
  return Math.round(((sell - cost) / sell) * 100);
}
function markupFor(row) {
  const cost = parseMoney(row.cost_price);
  const sell = parseMoney(row.sell_price);
  if (cost <= 0) return null;
  return Math.round(((sell - cost) / cost) * 100);
}

// Mode ubah: baris utama tanpa garis bawah (garisnya milik baris kedua); mode buat: tiap sel bergaris bawah.
const bb = computed(() => (isEdit.value ? '' : 'border-b'));
const iconBtn = 'flex h-7 w-7 items-center justify-center border border-line-2 bg-white text-muted-5 hover:bg-surface-subtle hover:text-brand-active';
const canRemove = (row) => !row.id && props.rows.length > 1;
</script>

<template>
  <div class="flex flex-col gap-1.5" data-testid="variants-table">
    <div class="border border-line">
      <table class="w-full table-fixed border-collapse text-left">
        <thead>
          <tr class="bg-surface-subtle text-[11px] font-semibold uppercase tracking-[0.05em] text-muted">
            <th scope="col" class="border-b border-r border-line-2 px-3 py-2" :class="isEdit ? 'w-[22%]' : 'w-[30%]'">{{ t('products.f_col_variant') }}</th>
            <th scope="col" class="border-b border-r border-line-2 px-3 py-2" :class="isEdit ? 'w-[18%]' : 'w-[18%]'">{{ t('products.f_col_sku') }}</th>
            <th scope="col" class="w-[92px] border-b border-r border-line-2 px-3 py-2">{{ t('products.f_col_image') }}</th>
            <th v-if="showCost" scope="col" class="w-[112px] border-b border-r border-line-2 px-3 py-2">{{ t('products.f_col_cost') }}</th>
            <th scope="col" class="w-[112px] border-b border-r border-line-2 px-3 py-2">{{ t('products.f_col_price') }}</th>
            <th scope="col" class="w-[88px] border-b border-r border-line-2 px-3 py-2">{{ isEdit ? t('products.f_col_stock_now') : t('products.f_col_stock') }}</th>
            <th scope="col" class="w-[56px] border-b border-line-2 px-2 py-2 text-center">{{ t('products.f_col_active') }}</th>
            <th scope="col" class="border-b border-l border-line-2" :class="isEdit ? 'w-[116px]' : 'w-[36px]'" aria-hidden="true"></th>
          </tr>
        </thead>
        <!-- Satu <tbody> per varian: baris kedua (mode ubah) tetap serumpun dengan barisnya dan tidak ikut tertukar. -->
        <tbody v-for="(row, i) in rows" :key="i" data-testid="variant-row" :class="{ 'opacity-50': isEdit && row.id && !row.is_active }">
          <tr>
            <td class="border-r border-line-2 p-0" :class="bb">
              <input
                v-model="row.variant_name"
                type="text"
                maxlength="100"
                :class="[cellInputClass, errorOf(i, 'variant_name') ? 'bg-danger-bg' : '']"
                :placeholder="t('products.f_variant_ph')"
                :aria-label="t('products.f_variant_name_aria', { n: i + 1 })"
                :aria-invalid="errorOf(i, 'variant_name') ? 'true' : undefined"
              />
            </td>
            <td class="border-r border-line-2 px-3 text-[12px]" :class="[bb, isEdit && row.sku ? 'font-mono text-muted-5' : 'text-muted-3']">
              <span v-if="isEdit && row.sku" class="block truncate" :title="row.sku">{{ row.sku }}</span>
              <span v-else>{{ t('products.f_sku_auto') }}</span>
            </td>
            <td class="border-r border-line-2" :class="bb">
              <ProductVariantImageCell :row="row" :index="i" @enlarge="(r) => emit('enlarge-image', r)" />
            </td>
            <td v-if="showCost" class="border-r border-line-2 p-0" :class="bb">
              <input
                v-model="row.cost_price"
                type="number"
                min="0"
                step="any"
                :class="cellInputClass"
                :disabled="!!row.bom_complete"
                :aria-label="t('products.f_variant_cost_aria', { n: i + 1 })"
              />
            </td>
            <td class="border-r border-line-2 p-0" :class="bb">
              <input
                v-model="row.sell_price"
                type="number"
                min="0"
                step="any"
                :class="[cellInputClass, errorOf(i, 'sell_price') ? 'bg-danger-bg' : '']"
                :aria-label="t('products.f_variant_price_aria', { n: i + 1 })"
                :aria-invalid="errorOf(i, 'sell_price') ? 'true' : undefined"
              />
            </td>
            <td class="border-r border-line-2 p-0" :class="bb">
              <input
                v-model="row.current_stock"
                type="number"
                min="0"
                step="1"
                :class="cellInputClass"
                :aria-label="isEdit ? t('products.f_variant_stock_now_aria', { n: i + 1 }) : t('products.f_variant_stock_aria', { n: i + 1 })"
              />
            </td>
            <td class="text-center" :class="[bb, 'border-line-2']">
              <input v-model="row.is_active" type="checkbox" class="h-4 w-4 accent-brand" :aria-label="t('products.f_variant_active_aria', { n: i + 1 })" />
            </td>
            <td class="border-l border-line-2 text-center" :class="bb">
              <div v-if="isEdit" class="flex items-center justify-center gap-1 px-1">
                <!-- Urutan aksi: Buka BOM · Terapkan markup · Duplikat · (hapus bila belum tersimpan). -->
                <BaseTooltip v-if="row.id" :text="t('master_data.bom_open_tip')" align="right">
                  <button type="button" :class="iconBtn" :aria-label="t('master_data.bom_open')" @click="emit('open-bom', row)">
                    <i class="ph-duotone ph-stack text-[14px]" aria-hidden="true"></i>
                  </button>
                </BaseTooltip>
                <BaseTooltip v-if="canSeeCost" :text="t('master_data.apply_markup')" align="right">
                  <button type="button" :class="iconBtn" :aria-label="t('master_data.apply_markup')" @click="applyMarkup(row)">
                    <i class="ph-duotone ph-percent text-[14px]" aria-hidden="true"></i>
                  </button>
                </BaseTooltip>
                <BaseTooltip :text="t('master_data.duplicate_variant')" align="right">
                  <button type="button" :class="iconBtn" :aria-label="t('master_data.duplicate_variant')" @click="emit('duplicate', i)">
                    <i class="ph-duotone ph-copy text-[14px]" aria-hidden="true"></i>
                  </button>
                </BaseTooltip>
                <button v-if="canRemove(row)" type="button" class="text-muted-3 hover:text-danger-text" :aria-label="t('products.f_variant_remove_aria', { n: i + 1 })" @click="emit('remove', i)">
                  <i class="ph-duotone ph-x text-[12px]" aria-hidden="true"></i>
                </button>
              </div>
              <!-- Item wajib punya ≥ 1 varian: × hanya pada baris belum tersimpan yang masih boleh dibuang. -->
              <button v-else-if="canRemove(row)" type="button" class="text-muted-3 hover:text-danger-text" :aria-label="t('products.f_variant_remove_aria', { n: i + 1 })" @click="emit('remove', i)">
                <i class="ph-duotone ph-x text-[12px]" aria-hidden="true"></i>
              </button>
            </td>
          </tr>
          <!-- Baris kedua redup (mode ubah): pengaturan yang jarang disentuh, satu baris per varian. -->
          <tr v-if="isEdit" data-testid="variant-extra">
            <td :colspan="colCount" class="border-b border-line-2 bg-surface-subtle px-3 py-1.5 text-[11.5px] text-muted-3">
              <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5">
                <label class="flex items-center gap-2">
                  {{ t('products.f_low_stock_alert') }}
                  <input
                    v-model="row.low_stock_alert"
                    type="number"
                    min="0"
                    step="1"
                    class="h-7 w-16 border border-line bg-white px-2 text-[12px] text-ink outline-none focus:border-brand"
                    :aria-label="t('products.f_variant_low_aria', { n: i + 1 })"
                  />
                </label>
                <!-- 028 — hidangan made-to-order tidak punya stok fisik: matikan pelacakan supaya tidak ada stok palsu
                     dan penjualan tidak pernah terblokir "stok habis". -->
                <label class="flex items-center gap-2">
                  <input v-model="row.track_stock" type="checkbox" class="h-4 w-4 accent-brand" :aria-label="t('products.f_variant_track_aria', { n: i + 1 })" />
                  {{ t('master_data.track_stock') }}
                </label>
                <template v-if="canSeeCost">
                  <span v-if="markupFor(row) !== null" :class="markupFor(row) < 0 ? 'font-semibold text-danger-text' : ''">{{ t('master_data.markup_value', { value: markupFor(row) }) }}</span>
                  <span v-if="marginFor(row) !== null" :class="marginFor(row) < 0 ? 'font-semibold text-danger-text' : ''">{{ t('master_data.margin', { value: marginFor(row) }) }}</span>
                  <span v-if="row.id && row.has_bom">{{ t('master_data.bom_cost_label', { cost: formatIDR(row.bom_cost) }) }}</span>
                </template>
                <span v-if="row.bom_complete" class="font-semibold text-brand-active">{{ t('master_data.bom_from_bom') }}</span>
              </div>
              <p v-if="showCost && row.bom_complete" class="mt-1">{{ t('master_data.bom_cost_price_locked_hint') }}</p>
              <div v-if="!row.id && canCopyBomOnCreate" class="mt-1.5 max-w-[320px]">
                <BaseSelect
                  v-model="row.copy_bom_from"
                  :label="t('master_data.bom_new_variant_label')"
                  :options="copySourceOptions"
                  :placeholder="t('master_data.bom_start_empty')"
                />
              </div>
              <!-- 037: catatan pada salinan — apa yang akan terjadi saat disimpan. -->
              <p v-if="row.is_duplicate && row.copy_bom_from && row.duplicated_from_sku" class="mt-1 font-semibold text-brand-active">
                {{ t('master_data.duplicate_bom_note', { sku: row.duplicated_from_sku }) }}
              </p>
              <p v-if="row.is_duplicate && Number(row.current_stock) > 0" class="mt-1 font-semibold text-warn-text">
                {{ t('master_data.duplicate_stock_note', { qty: row.current_stock }) }}
              </p>
            </td>
          </tr>
        </tbody>
      </table>
      <button type="button" class="block w-full px-3 py-2.5 text-left text-[12.5px] font-semibold text-brand hover:text-brand-active" @click="emit('add')">
        {{ t('products.f_add_variant') }}
      </button>
    </div>
    <p v-for="(row, i) in rows" :key="`e${i}`" v-show="lineError(i)" class="text-[12px] font-medium text-danger-text">
      {{ lineError(i) }}
    </p>
    <p class="text-[11.5px] text-muted-3">{{ isEdit ? t('products.f_variants_hint_edit') : t('products.f_variants_hint') }}</p>
  </div>
</template>
