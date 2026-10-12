<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import DataTable from '../../components/ui/DataTable.vue';
import { sumRows } from './sumRows';

// F9.5 — laporan modal & laba kotor PER OUTLET (owner/admin saja). Baris sudah difilter outlet oleh induk.
const props = defineProps({
  rows: { type: Array, required: true },
  loading: { type: Boolean, default: false },
});
const { t } = useI18n();

const totals = computed(() => sumRows(props.rows, ['total_sales', 'modal', 'gross_profit']));

const columns = computed(() => [
  { key: 'outlet_name', label: t('reports.col_artist') },
  { key: 'total_sales', label: t('reports.col_sales') },
  { key: 'modal', label: t('reports.col_modal') },
  { key: 'gross_profit', label: t('reports.col_gross_profit') },
]);
</script>

<template>
  <DataTable :columns="columns" :rows="rows" :loading="loading" row-key="outlet_id" :empty-message="t('reports.no_artist_sales')">
    <template #cell-outlet_name="{ row }">
      {{ row.outlet_name }}
      <span v-if="row.historical" class="ml-1.5 border border-line px-1.5 py-0.5 text-[11px] font-semibold text-muted-4" :title="t('reports.historical_hint')" data-testid="historical-tag">{{ t('reports.historical') }}</span>
    </template>
    <template #cell-total_sales="{ row }">{{ formatIDR(row.total_sales) }}</template>
    <template #cell-modal="{ row }">{{ formatIDR(row.modal) }}</template>
    <template #cell-gross_profit="{ row }">
      <span class="font-semibold text-brand-active">{{ formatIDR(row.gross_profit) }}</span>
    </template>
    <template #footer>
      <tr class="border-t-2 border-line-2 bg-surface-subtle font-bold">
        <td class="px-4 py-3 text-[13px]">{{ t('reports.grand_total') }}</td>
        <td class="px-4 py-3 text-[13px]">{{ formatIDR(totals.total_sales) }}</td>
        <td class="px-4 py-3 text-[13px]">{{ formatIDR(totals.modal) }}</td>
        <td class="px-4 py-3 text-[13px] text-brand-active">{{ formatIDR(totals.gross_profit) }}</td>
      </tr>
    </template>
  </DataTable>
</template>
