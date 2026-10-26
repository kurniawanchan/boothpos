<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDRExact } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import DataTable from '../ui/DataTable.vue';

// 076 — rincian transaksi beku. Hanya nomor transaksi (tidak ada data
// pelanggan). Pre-order punya tautan ke layar Pre-order lewat deep link
// `?preorder_id=` yang sudah ada; order POS ditampilkan sebagai teks.
defineProps({
  sources: { type: Array, required: true },
});

const { t } = useI18n();

const columns = computed(() => [
  { key: 'source_type', label: t('settlements.src_col_type') },
  { key: 'source_number', label: t('settlements.src_col_number') },
  { key: 'source_date', label: t('settlements.src_col_date') },
  { key: 'units', label: t('settlements.src_col_units') },
  { key: 'gross_amount', label: t('settlements.src_col_gross') },
  { key: 'amount', label: t('settlements.src_col_amount') },
]);
</script>

<template>
  <DataTable :columns="columns" :rows="sources" :empty-message="t('settlements.src_empty')" row-key="source_number">
    <template #cell-source_type="{ row }">{{ t(`settlements.src_type_${row.source_type}`) }}</template>
    <template #cell-source_number="{ row }">
      <RouterLink
        v-if="row.source_type === 'preorder'"
        :to="{ name: 'preorders', query: { preorder_id: row.source_id } }"
        class="font-mono text-[12.5px] font-bold text-brand-active hover:underline"
      >
        {{ row.source_number }}
      </RouterLink>
      <span v-else class="font-mono text-[12.5px]">{{ row.source_number }}</span>
    </template>
    <template #cell-source_date="{ row }">{{ formatDateTime(row.source_date) }}</template>
    <template #cell-gross_amount="{ row }"><span class="tabular-nums">{{ formatIDRExact(row.gross_amount) }}</span></template>
    <template #cell-amount="{ row }"><span class="font-semibold tabular-nums">{{ formatIDRExact(row.amount) }}</span></template>
  </DataTable>
</template>
