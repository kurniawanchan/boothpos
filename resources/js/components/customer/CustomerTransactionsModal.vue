<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import BaseModal from '../ui/BaseModal.vue';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import TransactionItemsModal from '../sales/TransactionItemsModal.vue';
import { customerTransactions } from '../../api/customers';
import { formatIDR } from '../../utils/money';
import { formatDate } from '../../utils/date';
import { useToastStore } from '../../stores/toast';

/**
 * 009-ui-ux-refinements US5 (T043) — riwayat transaksi (penjualan +
 * pre-order tergabung) per pelanggan, dibuka dari CustomersView.vue.
 *
 * Sumber data GET /customers/{id}/transactions (T042). Drill-in ke detail
 * TIDAK membangun UI baru:
 * - type 'order' -> TransactionItemsModal yang sudah dipakai SalesView.vue
 *   (GET /orders/{order}, tidak diubah).
 * - type 'preorder' -> navigasi ke PreordersView via query
 *   ?preorder_id=<id>, yang membuka detail drawer pre-order yang sudah ada
 *   di sana (lihat watcher openDetailById di PreordersView.vue).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  customerId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const toast = useToastStore();
const router = useRouter();

const rows = ref([]);
const loading = ref(false);

// Reuse existing preorder status labels/variants (PreordersView.vue) so
// this list matches the badge styling used everywhere else pre-order
// status is shown.
const STATUS_LABEL = {
  ordered: 'preorders.step_ordered',
  dp_paid: 'preorders.step_dp_paid',
  arrived: 'preorders.step_arrived',
  settled: 'preorders.step_settled',
  handed_over: 'preorders.step_handed_over',
  cancelled: 'events_sessions.status_cancelled',
};
const STATUS_VARIANT = { ordered: 'neutral', dp_paid: 'warn', arrived: 'mint', settled: 'mint', handed_over: 'dark', cancelled: 'danger' };

watch(
  () => [props.open, props.customerId],
  async ([open, customerId]) => {
    if (!open || !customerId) {
      rows.value = [];
      return;
    }
    loading.value = true;
    try {
      const result = await customerTransactions(customerId);
      rows.value = result.data ?? [];
    } catch (err) {
      toast.error(err.message || t('events_sessions.no_transactions'));
    } finally {
      loading.value = false;
    }
  },
  { immediate: true }
);

const columns = [
  { key: 'number', label: t('events_sessions.col_transaction_number') },
  { key: 'type', label: t('events_sessions.col_transaction_type') },
  { key: 'status', label: t('events_sessions.col_transaction_status') },
  { key: 'total_amount', label: t('events_sessions.col_transaction_total') },
  { key: 'date', label: t('events_sessions.col_transaction_date') },
];

const showOrderDetail = ref(false);
const detailOrderId = ref(null);

function openRow(row) {
  if (row.type === 'order') {
    detailOrderId.value = row.id;
    showOrderDetail.value = true;
    return;
  }
  // Pre-order detail already lives in PreordersView.vue — navigate there
  // instead of duplicating the drawer/stepper/payment UI here.
  emit('close');
  router.push({ name: 'preorders', query: { preorder_id: row.id } });
}
</script>

<template>
  <BaseModal :open="open" :title="t('events_sessions.transaction_history_title')" max-width-class="max-w-[680px]" @close="emit('close')">
    <div class="px-6 py-5">
      <div class="overflow-hidden rounded-lg border border-line-2">
        <DataTable :columns="columns" :rows="rows" :loading="loading" :empty-message="t('events_sessions.no_transactions')">
          <template #cell-number="{ row }">
            <button type="button" class="text-left font-semibold text-brand-active underline decoration-dotted hover:text-brand" @click="openRow(row)">
              {{ row.number }}
            </button>
          </template>
          <template #cell-type="{ row }">
            {{ row.type === 'order' ? t('events_sessions.transaction_type_order') : t('events_sessions.transaction_type_preorder') }}
          </template>
          <template #cell-status="{ row }">
            <StatusPill v-if="row.type === 'preorder'" :variant="STATUS_VARIANT[row.status] ?? 'neutral'">{{ t(STATUS_LABEL[row.status] ?? row.status) }}</StatusPill>
            <span v-else>—</span>
          </template>
          <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
          <template #cell-date="{ row }">{{ formatDate(row.date) }}</template>
        </DataTable>
      </div>
    </div>
  </BaseModal>

  <TransactionItemsModal :open="showOrderDetail" :order-id="detailOrderId" @close="showOrderDetail = false" />
</template>
