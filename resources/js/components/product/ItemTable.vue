<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/formatters';
import { categoryStationText, variantModifierText } from '../../utils/itemSummary';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import AvailabilityChips from './AvailabilityChips.vue';

/**
 * 043 (T033) — tampilan Tabel di atas DataTable: kotak pilih, Item (gambar mini + nama + "Kategori · Station"), SKU,
 * Varian · modifier, Harga dasar (rata kanan), Ketersediaan, Status. Kepala yang bisa diurutkan (item, SKU, harga,
 * status) hanya MEMINTA perubahan urutan (`sort`) — keadaan urutan dimiliki halaman (alamat URL), sama seperti
 * DataTable. Pilihan baris dipegang pemanggil lewat v-model:selected (hanya halaman yang tampil).
 * Aksi baris di kolom terakhir (Edit) hanya dirender bila `canEdit` (disembunyikan, bukan dinonaktifkan). Edit adalah
 * TAUTAN ke halaman item (rute product-detail), sama seperti mengklik namanya: mengubah item dilakukan dari halaman
 * itu ("Edit item"), jadi daftar tidak lagi membuka laci ubah sendiri dan tidak ada emit `edit`.
 * 043 (US4, keputusan pemilik 2026-10-11): aksi lama "Nonaktifkan" dihapus; mematikan item kini HANYA lewat
 * "Discontinue" di halaman item (alasan wajib, mengikuti rilis menu).
 *
 * Kolom SKU menampilkan kode item (`code`, mis. KOP-001): daftar tidak membawa SKU per varian (menghindari satu
 * permintaan per baris); SKU tiap varian ada di halaman item.
 */
const props = defineProps({
  items: { type: Array, required: true },
  sortKey: { type: String, default: null },
  sortDir: { type: String, default: 'asc' },
  selected: { type: Array, default: () => [] },
  canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['sort', 'update:selected']);
const { t } = useI18n();

const columns = computed(() => [
  { key: 'name', label: t('products.col_item'), sortable: true },
  { key: 'sku', label: t('products.col_sku'), sortable: true },
  { key: 'variants', label: t('products.col_variants') },
  { key: 'price', label: t('products.col_price'), sortable: true },
  { key: 'availability', label: t('products.col_availability') },
  { key: 'status', label: t('products.col_status'), sortable: true },
  { key: 'actions', label: '' },
]);

const STATUS = {
  active: { tone: 'success', key: 'products.status_active' },
  draft: { tone: 'warning', key: 'products.status_draft' },
  discontinued: { tone: 'neutral', key: 'products.status_discontinued' },
};
const statusOf = (row) => STATUS[row.status] ?? STATUS.active;
const priceOf = (row) => (row.base_price === null || row.base_price === undefined ? '—' : formatIDR(row.base_price));
</script>

<template>
  <DataTable
    :columns="columns"
    :rows="items"
    selectable
    :selected="selected"
    :row-label="(row) => t('products.select_item', { name: row.name })"
    :sort-key="sortKey"
    :sort-dir="sortDir"
    @update:selected="emit('update:selected', $event)"
    @sort="emit('sort', $event)"
  >
    <template #cell-name="{ row }">
      <div class="flex items-center gap-3">
        <img v-if="row.image_url" :src="row.image_url" :alt="row.name" class="h-12 w-12 flex-none border border-line-2 object-cover" loading="lazy" />
        <span v-else class="flex h-12 w-12 flex-none items-center justify-center border border-line-2 bg-surface-subtle text-muted-3" aria-hidden="true">
          <i class="ph-duotone ph-image text-[20px]"></i>
        </span>
        <div class="flex min-w-0 flex-col">
          <RouterLink :to="{ name: 'product-detail', params: { id: row.id } }" class="truncate font-bold hover:text-brand-active focus-visible:text-brand-active focus-visible:outline-none">{{ row.name }}</RouterLink>
          <span class="truncate text-[12px] text-muted">{{ categoryStationText(row) || t('products.no_category') }}</span>
        </div>
      </div>
    </template>
    <template #cell-sku="{ row }">
      <span class="inline-block max-w-[190px] truncate whitespace-nowrap font-mono text-[12px] font-bold text-brand-active" :title="row.code">{{ row.code }}</span>
    </template>
    <template #cell-variants="{ row }">
      <span class="text-[12.5px] text-muted-4">{{ variantModifierText(row, t) }}</span>
    </template>
    <template #cell-price="{ row }">
      <div class="text-right font-semibold tabular-nums">{{ priceOf(row) }}</div>
    </template>
    <template #cell-availability="{ row }"><AvailabilityChips :availability="row.availability" /></template>
    <template #cell-status="{ row }">
      <StatusPill :variant="statusOf(row).tone">{{ t(statusOf(row).key) }}</StatusPill>
    </template>
    <template #cell-actions="{ row }">
      <div v-if="canEdit" class="flex justify-end gap-3">
        <RouterLink :to="{ name: 'product-detail', params: { id: row.id } }" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active focus-visible:text-brand-active focus-visible:outline-none" :aria-label="t('products.edit_item', { name: row.name })">{{ t('common.edit') }}</RouterLink>
      </div>
    </template>
  </DataTable>
</template>
