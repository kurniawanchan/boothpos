<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/formatters';
import { formatDateTime } from '../../utils/date';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';

/**
 * 065 / US2 — kas masuk/keluar: dipakai daftar lintas shift (tab "Kas masuk/keluar") DAN rincian satu shift.
 * `showShift` menampilkan kolom sumber/terminal (daftar lintas shift); di rincian satu shift kolom itu tak perlu.
 */
const props = defineProps({
  rows: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  showShift: { type: Boolean, default: true },
});
const emit = defineEmits(['retry']);
const { t } = useI18n();

const columns = computed(() => [
  { key: 'created_at', label: t('shifts.col_time') },
  { key: 'direction', label: t('shifts.col_type') },
  { key: 'reason', label: t('shifts.col_reason') },
  { key: 'amount', label: t('shifts.col_amount') },
  { key: 'created_by_name', label: t('shifts.col_user') },
  ...(props.showShift ? [{ key: 'source', label: t('shifts.col_source') }] : []),
]);
</script>

<template>
  <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error" :empty-message="t('shifts.empty_cash')" @retry="emit('retry')">
    <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
    <template #cell-direction="{ row }">
      <StatusPill :variant="row.direction === 'in' ? 'success' : 'warning'">{{ row.direction === 'in' ? t('shifts.cash_in') : t('shifts.cash_out') }}</StatusPill>
    </template>
    <template #cell-reason="{ row }">
      {{ row.reason_label || row.reason_code }}<span v-if="row.reason_note" class="block text-[11.5px] text-muted-3">{{ row.reason_note }}</span>
    </template>
    <template #cell-amount="{ row }">{{ formatIDR(row.amount) }}</template>
    <template #cell-source="{ row }">{{ row.source === 'app' ? (row.terminal_name || t('shifts.source_app')) : t('shifts.source_web') }}</template>
  </DataTable>
</template>
