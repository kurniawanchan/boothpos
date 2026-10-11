<script setup>
import { useI18n } from 'vue-i18n';
import { formatIDR, parseMoney } from '../../utils/money';
import { imageFileError } from './imageGuard';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import BaseSelect from '../../components/ui/BaseSelect.vue';
import BaseTooltip from '../../components/ui/BaseTooltip.vue';

/**
 * Satu kartu varian di drawer produk (dipecah dari ProductsView, perilaku identik). `row` adalah objek
 * reaktif milik drawer (`variantRows`) dan sengaja DIMUTASI langsung oleh kartu ini lewat v-model — drawer
 * membaca baris yang sama saat menyimpan; membungkusnya dengan emit per-field hanya menambah kode tanpa
 * mengubah apa pun. Hal yang butuh dunia luar (hapus, duplikat, buka BOM, perbesar gambar) dipancarkan.
 */
const props = defineProps({
  row: { type: Object, required: true },
  markupPercent: { type: Number, required: true },
  canCopyBomOnCreate: { type: Boolean, default: false },
  copySourceOptions: { type: Array, default: () => [] },
});
defineEmits(['remove', 'duplicate', 'open-bom', 'enlarge-image']);
const { t } = useI18n();

function applyMarkup() {
  const cost = parseMoney(props.row.cost_price);
  props.row.sell_price = Math.round(cost * (1 + props.markupPercent / 100)).toString();
}

function marginFor(row) {
  const cost = parseMoney(row.cost_price);
  const sell = parseMoney(row.sell_price);
  if (sell <= 0) return null;
  return Math.round(((sell - cost) / sell) * 100);
}

// Markup (profit ÷ modal) dan margin (profit ÷ harga jual) adalah metrik berbeda menurut definisi — markup
// 50% selalu persis margin 33% berapa pun modalnya. Keduanya ditampilkan berdampingan supaya hubungan itu
// tidak disangka bug.
function markupFor(row) {
  const cost = parseMoney(row.cost_price);
  const sell = parseMoney(row.sell_price);
  if (cost <= 0) return null;
  return Math.round(((sell - cost) / cost) * 100);
}

// Gambar per-varian (permintaan eksplisit pemilik produk: varian dengan motif berbeda boleh punya gambar
// sendiri, terpisah dari gambar produk).
function onImageChange(e) {
  const file = e.target.files?.[0] ?? null;
  props.row.image_error = '';
  props.row.image_file = null;
  if (!file) return;
  const errKey = imageFileError(file);
  if (errKey) {
    props.row.image_error = t(errKey);
    e.target.value = '';
    return;
  }
  props.row.image_file = file;
}
</script>

<template>
  <!-- 037: kartu putih bergaris tipis dan bayangan lembut di atas baki abu-abu muda. -->
  <div
    data-testid="variant-card"
    class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5 shadow-sm"
    :class="{ 'opacity-50': row.id && !row.is_active }"
  >
    <div data-testid="variant-header" class="flex flex-wrap items-center gap-2.5">
      <span v-if="row.sku" class="rounded-md bg-sky-bg px-2.5 py-1 font-mono text-[12px] font-semibold text-sky-text">{{ row.sku }}</span>
      <span v-if="row.bom_complete" class="rounded-md bg-mint-100 px-2 py-1 text-[11px] font-bold text-brand-active">{{ t('master_data.bom_from_bom') }}</span>
      <span v-if="markupFor(row) !== null" class="rounded-md px-2 py-1 text-[11px] font-bold" :class="markupFor(row) >= 0 ? 'bg-mint-100 text-brand-active' : 'bg-danger-bg text-danger-text'">
        {{ t('master_data.markup_value', { value: markupFor(row) }) }}
      </span>
      <span v-if="marginFor(row) !== null" class="rounded-md px-2 py-1 text-[11px] font-bold" :class="marginFor(row) >= 0 ? 'bg-violet-bg text-violet-text' : 'bg-danger-bg text-danger-text'">
        {{ t('master_data.margin', { value: marginFor(row) }) }}
      </span>
      <span v-if="row.id && row.has_bom" class="rounded-md bg-warn-bg px-2 py-1 text-[11px] font-bold text-warn-text">{{ t('master_data.bom_cost_label', { cost: formatIDR(row.bom_cost) }) }}</span>
      <span class="flex-1"></span>
      <!-- Urutan aksi sesuai permintaan: Buka BOM · Terapkan markup · hapus. -->
      <BaseTooltip v-if="row.id" :text="t('master_data.bom_open_tip')" align="right">
        <BaseButton variant="secondary" size="sm" @click="$emit('open-bom', row)">
          <i class="ph-duotone ph-stack text-[14px]" aria-hidden="true"></i>
          {{ t('master_data.bom_open') }}
        </BaseButton>
      </BaseTooltip>
      <BaseButton variant="secondary" size="sm" @click="applyMarkup">{{ t('master_data.apply_markup') }}</BaseButton>
      <button type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('master_data.delete_variant', { name: row.variant_name })" @click="$emit('remove')">
        <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
      </button>
    </div>
    <div class="grid grid-cols-2 items-end gap-3 lg:grid-cols-[1.6fr_0.8fr_1fr_1fr]">
      <BaseInput v-model="row.variant_name" class="col-span-2 lg:col-span-1" :label="t('master_data.variant_name')" />
      <BaseInput v-model="row.current_stock" type="number" min="0" :label="t('master_data.col_stock')" />
      <BaseInput
        v-model="row.cost_price"
        type="number"
        min="0"
        :label="t('master_data.cost_price')"
        :disabled="!!row.bom_complete"
        :hint="row.bom_complete ? t('master_data.bom_cost_price_locked_hint') : ''"
      />
      <BaseInput v-model="row.sell_price" type="number" min="0" :label="t('master_data.sell_price')" />
    </div>
    <BaseSelect
      v-if="!row.id && canCopyBomOnCreate"
      v-model="row.copy_bom_from"
      :label="t('master_data.bom_new_variant_label')"
      :options="copySourceOptions"
      :placeholder="t('master_data.bom_start_empty')"
    />
    <!-- 037: catatan pada kartu salinan — apa yang akan terjadi saat disimpan. -->
    <p v-if="row.is_duplicate && row.copy_bom_from && row.duplicated_from_sku" class="rounded-md bg-mint-50 px-3 py-2 text-[12px] font-semibold text-brand-active">
      {{ t('master_data.duplicate_bom_note', { sku: row.duplicated_from_sku }) }}
    </p>
    <p v-if="row.is_duplicate && Number(row.current_stock) > 0" class="rounded-md bg-warn-bg px-3 py-2 text-[12px] font-semibold text-warn-text">
      {{ t('master_data.duplicate_stock_note', { qty: row.current_stock }) }}
    </p>
    <!-- 028 — hidangan made-to-order tidak punya stok fisik: matikan pelacakan supaya tidak ada stok palsu
         dan penjualan tidak pernah terblokir "stok habis". -->
    <label class="flex items-start gap-2.5 text-[12.5px] font-semibold text-muted-4">
      <input v-model="row.track_stock" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-line accent-brand" />
      <span>
        {{ t('master_data.track_stock') }}
        <span class="block text-[11.5px] font-normal text-muted-3">{{ t('master_data.track_stock_hint') }}</span>
      </span>
    </label>
    <!-- Gambar per-varian. 037: 44px -> 66px, placeholder satu ukuran supaya kartu tetap sejajar. -->
    <div class="flex items-center gap-3">
      <button
        v-if="row.image_url && !row.image_file"
        type="button"
        class="h-[66px] w-[66px] flex-none cursor-zoom-in"
        :aria-label="t('master_data.enlarge_variant_image', { name: row.variant_name })"
        @click="$emit('enlarge-image', row)"
      >
        <img :src="row.image_url" :alt="t('master_data.current_variant_image')" class="h-[66px] w-[66px] rounded-md border border-line-2 object-cover" />
      </button>
      <div v-else class="flex h-[66px] w-[66px] flex-none items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3">
        <i class="ph-duotone ph-image text-[24px]" aria-hidden="true"></i>
      </div>
      <input
        type="file"
        accept="image/*"
        class="flex-1 rounded-lg border border-line bg-white px-3 py-2 text-[12px] file:mr-2.5 file:rounded-md file:border-0 file:bg-mint-100 file:px-2.5 file:py-1 file:text-[11.5px] file:font-bold file:text-brand-active"
        @change="onImageChange"
      />
    </div>
    <p v-if="row.image_error" class="text-[12px] font-semibold text-danger-text">{{ row.image_error }}</p>
    <div class="flex justify-end border-t border-line-3 pt-3">
      <BaseButton variant="secondary" size="sm" @click="$emit('duplicate')">
        <i class="ph-duotone ph-copy text-[14px]" aria-hidden="true"></i>
        {{ t('master_data.duplicate_variant') }}
      </BaseButton>
    </div>
  </div>
</template>
