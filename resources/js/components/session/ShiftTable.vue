<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/formatters';
import { formatDateTime } from '../../utils/date';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';

/**
 * 065 / US1 — daftar shift untuk owner/admin: satu baris per shift, dari aplikasi (nama terminal) atau web.
 * Murni tampilan: data, paginasi, dan keadaan memuat/galat dipegang pemanggil (AllShiftsSection).
 *
 * @prop {Array} rows           baris dari GET /sessions
 * @prop {'open'|'closed'} mode kolom yang tampil: terbuka = penjualan sejauh ini; tertutup = hitungan dan selisih
 */
const props = defineProps({
  rows: { type: Array, required: true },
  mode: { type: String, default: 'open' },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  selectedId: { type: Number, default: null },
});
const emit = defineEmits(['select', 'retry']);
const { t } = useI18n();

const columns = computed(() => [
  { key: 'cashier', label: t('shifts.col_cashier') },
  { key: 'source', label: t('shifts.col_source') },
  { key: 'outlet', label: t('shifts.col_outlet') },
  { key: props.mode === 'closed' ? 'closed_at' : 'opened_at', label: props.mode === 'closed' ? t('shifts.col_closed') : t('shifts.col_opened') },
  { key: 'opening_cash', label: t('shifts.col_opening') },
  { key: 'sales_total', label: t('shifts.col_sales') },
  ...(props.mode === 'closed'
    ? [
      { key: 'expected_cash', label: t('shifts.col_expected') },
      { key: 'counted_total', label: t('shifts.col_counted') },
      { key: 'difference', label: t('shifts.col_difference') },
    ]
    : [{ key: 'expected_cash', label: t('shifts.col_expected') }]),
  { key: 'actions', label: '' },
]);

const diffClass = (row) => (Number(row.difference) === 0 ? 'text-muted-4' : 'text-danger-text font-bold');
const sign = (v) => (Number(v) > 0 ? '+' : Number(v) < 0 ? '−' : '');
const rowClass = (row) => (row.id === props.selectedId ? 'bg-mint-50' : '');
</script>

<template>
  <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error" :row-class="rowClass" :empty-message="t(mode === 'closed' ? 'shifts.empty_closed' : 'shifts.empty_open')" @retry="emit('retry')">
    <template #cell-cashier="{ row }">{{ row.cashier?.name ?? '—' }}</template>
    <template #cell-source="{ row }">
      <StatusPill :variant="row.source === 'app' ? 'info' : 'neutral'">{{ row.source === 'app' ? (row.terminal_name || t('shifts.source_app')) : t('shifts.source_web') }}</StatusPill>
    </template>
    <template #cell-outlet="{ row }">{{ row.outlet?.name ?? '—' }}</template>
    <template #cell-opened_at="{ row }">{{ formatDateTime(row.opened_at) }}</template>
    <template #cell-closed_at="{ row }">{{ formatDateTime(row.closed_at) }}</template>
    <template #cell-opening_cash="{ row }">{{ formatIDR(row.opening_cash) }}</template>
    <template #cell-sales_total="{ row }">
      {{ formatIDR(row.sales_total) }}<span class="block text-[11.5px] text-muted-3">{{ t('shifts.receipts', { count: row.order_count }) }}</span>
    </template>
    <template #cell-expected_cash="{ row }">{{ formatIDR(row.expected_cash) }}</template>
    <template #cell-counted_total="{ row }">{{ row.counted_total === null ? '—' : formatIDR(row.counted_total) }}</template>
    <template #cell-difference="{ row }">
      <span v-if="row.difference === null">—</span>
      <span v-else :class="diffClass(row)">{{ sign(row.difference) }}{{ formatIDR(Math.abs(Number(row.difference))) }}</span>
    </template>
    <template #cell-actions="{ row }">
      <button type="button" class="text-[12.5px] font-bold text-brand-active underline decoration-dotted hover:text-brand" :data-testid="`shift-detail-${row.id}`" @click="emit('select', row.id)">{{ t('shifts.detail') }}</button>
    </template>
  </DataTable>
</template>
