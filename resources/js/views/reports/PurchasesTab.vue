<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import DataTable from '../../components/ui/DataTable.vue';
import KpiCard from '../../components/page/KpiCard.vue';
import StatusPill from '../../components/ui/StatusPill.vue';
import { sumRows } from './sumRows';

// 006-purchase-order-and-ops (US9) — laporan pembelian; sengaja tidak diskop event (bukan konsep per-event).
// Dua angka ringkasan (jumlah PO, total) — bukan empat — jadi KpiCard dalam grid dua kolom, bukan KpiStrip.
const props = defineProps({
  purchases: { type: Object, default: null },
  loading: { type: Boolean, default: false },
});
const { t } = useI18n();

const rows = computed(() => props.purchases?.rows ?? []);
const totals = computed(() => sumRows(rows.value, ['total_amount']));

const STATUS_TONE = { draft: 'neutral', ordered: 'info', received: 'accent', paid: 'success', cancelled: 'danger' };

const columns = computed(() => [
  { key: 'po_number', label: t('purchase_orders.col_number') },
  { key: 'vendor_name', label: t('purchase_orders.col_vendor') },
  { key: 'status', label: t('purchase_orders.col_status') },
  { key: 'created_at', label: t('reports.col_date') },
  { key: 'total_amount', label: t('purchase_orders.col_total') },
]);
</script>

<template>
  <div v-if="purchases" class="flex flex-col gap-4">
    <div class="mx-5 grid grid-cols-1 gap-px border-2 border-line bg-line sm:grid-cols-2">
      <KpiCard :label="t('reports.purchases_po_count')" :value="purchases.totals.po_count" tone="info" />
      <KpiCard :label="t('reports.purchases_total_amount')" :value="formatIDR(purchases.totals.total_amount)" tone="accent" />
    </div>
    <DataTable :columns="columns" :rows="rows" :loading="loading" :empty-message="t('reports.no_purchases')">
      <template #cell-status="{ row }"><StatusPill :variant="STATUS_TONE[row.status] ?? 'neutral'">{{ t(`purchase_orders.status_${row.status}`) }}</StatusPill></template>
      <template #cell-created_at="{ row }">{{ new Date(row.created_at).toLocaleDateString('id-ID') }}</template>
      <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
      <template #footer>
        <tr class="border-t-2 border-line-2 bg-surface-subtle font-bold">
          <td class="px-4 py-3 text-[13px]" colspan="4">{{ t('reports.grand_total') }}</td>
          <td class="px-4 py-3 text-[13px]">{{ formatIDR(totals.total_amount) }}</td>
        </tr>
      </template>
    </DataTable>
  </div>
</template>
