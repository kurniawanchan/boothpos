<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import TransactionItemsModal from '../sales/TransactionItemsModal.vue';
import { customerTransactions } from '../../api/customers';
import { formatIDR } from '../../utils/money';
import { formatDate } from '../../utils/date';
import { useToastStore } from '../../stores/toast';

/**
 * 009-ui-ux-refinements US5 (T043) — riwayat transaksi (penjualan) per pelanggan, dibuka dari CustomersView.vue.
 * 046: pre-order sudah dihapus dari produk, jadi daftar ini hanya memuat penjualan.
 *
 * Sumber data GET /customers/{id}/transactions (T042). Detail memakai TransactionItemsModal yang sudah dipakai
 * SalesView.vue (GET /orders/{order}, tidak diubah).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  customerId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const toast = useToastStore();

const rows = ref([]);
const loading = ref(false);

// Hanya penjualan yang dibatalkan yang diberi penanda status; yang selesai tampil "—" seperti sebelumnya.

async function load() {
  if (!props.open || !props.customerId) {
    rows.value = [];
    return;
  }
  loading.value = true;
  try {
    const result = await customerTransactions(props.customerId);
    rows.value = result.data ?? [];
  } catch (err) {
    toast.error(err.message || t('events_sessions.no_transactions'));
  } finally {
    loading.value = false;
  }
}

watch(() => [props.open, props.customerId], load, { immediate: true });

const columns = [
  { key: 'number', label: t('events_sessions.col_transaction_number') },
  { key: 'status', label: t('events_sessions.col_transaction_status') },
  { key: 'total_amount', label: t('events_sessions.col_transaction_total') },
  { key: 'date', label: t('events_sessions.col_transaction_date') },
];

const showOrderDetail = ref(false);
const detailOrderId = ref(null);

function openRow(row) {
  detailOrderId.value = row.id;
  showOrderDetail.value = true;
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
          <template #cell-status="{ row }">
            <StatusPill v-if="row.status === 'voided'" variant="danger">{{ t('events_sessions.status_cancelled') }}</StatusPill>
            <span v-else>—</span>
          </template>
          <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
          <template #cell-date="{ row }">{{ formatDate(row.date) }}</template>
        </DataTable>
      </div>
    </div>
  </BaseModal>

  <!-- @changed: detail transaksi bisa membatalkan transaksi; daftar ini harus ikut segar. -->
  <TransactionItemsModal :open="showOrderDetail" :order-id="detailOrderId" @close="showOrderDetail = false" @changed="load" />
</template>
