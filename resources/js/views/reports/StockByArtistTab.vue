<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTable from '../../components/ui/DataTable.vue';
import StockByArtistDetailModal from '../../components/report/StockByArtistDetailModal.vue';
import { sumRows } from './sumRows';

// 006-purchase-order-and-ops (US10) — stok per penjual; sengaja tidak diskop event. Baris sudah difilter
// penjual oleh induk. Detail per-varian diambil HANYA saat baris diklik (StockByArtistDetailModal memanggil
// endpoint-nya sendiri on-demand, per research.md R9) — tidak pernah eager untuk semua penjual.
const props = defineProps({
  rows: { type: Array, required: true },
  loading: { type: Boolean, default: false },
});
const { t } = useI18n();

const totals = computed(() => sumRows(props.rows, [], ['variant_count', 'total_stock']));
const columns = computed(() => [
  { key: 'artist_name', label: t('reports.col_artist') },
  { key: 'variant_count', label: t('reports.stock_col_variant_count') },
  { key: 'total_stock', label: t('reports.stock_col_total_stock') },
  { key: 'actions', label: '' },
]);

// US7 (009-ui-ux-refinements) — drill-down varian per penjual.
const showDetail = ref(false);
const target = ref(null);
function openDetail(row) {
  target.value = row;
  showDetail.value = true;
}
</script>

<template>
  <DataTable :columns="columns" :rows="rows" :loading="loading" row-key="artist_id" :empty-message="t('reports.no_stock_data')">
    <template #cell-actions="{ row }">
      <div class="flex justify-end">
        <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openDetail(row)">{{ t('reports.stock_detail_action') }}</button>
      </div>
    </template>
    <template #footer>
      <tr class="border-t-2 border-line-2 bg-surface-subtle font-bold">
        <td class="px-4 py-3 text-[13px]">{{ t('reports.grand_total') }}</td>
        <td class="px-4 py-3 text-[13px]">{{ totals.variant_count }}</td>
        <td class="px-4 py-3 text-[13px]">{{ totals.total_stock }}</td>
        <td class="px-4 py-3"></td>
      </tr>
    </template>
  </DataTable>

  <StockByArtistDetailModal
    :open="showDetail"
    :artist-id="target?.artist_id"
    :artist-name="target?.artist_name"
    @close="showDetail = false"
  />
</template>
