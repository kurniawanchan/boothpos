<script setup>
import { ref, watch, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import TablePagination from '../ui/TablePagination.vue';
import TransactionItemsModal from '../sales/TransactionItemsModal.vue';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';

/**
 * 054 (US10/US11) — daftar transaksi di balik satu baris laporan (satu produk, atau satu metode
 * pembayaran). Dipakai kedua tab baru; pemanggil memberi `loader(page)` yang mengembalikan
 * `{ data, meta }`, dan kolom jumlahnya diberi label oleh `amountLabel` (untuk produk: nilai baris
 * produk itu; untuk metode: porsi metode itu). Nomor transaksi membuka detail penjualan tanpa
 * meninggalkan laporan.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  loader: { type: Function, required: true },
  amountLabel: { type: String, default: '' },
  showQty: { type: Boolean, default: false },
  showVerification: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const rows = ref([]);
const meta = ref({ current_page: 1, per_page: 25, total: 0, last_page: 1 });
const loading = ref(false);
const failed = ref(false);
const orderId = ref(null);
const router = useRouter();

// Baris bisa berupa penjualan POS ATAU pre-order (laporan produk menggabungkan keduanya): id dari dua tabel
// bisa sama, jadi kunci barisnya memuat jenisnya; pre-order membuka layar Pre-order lewat tautan dalam yang ada.
const keyed = computed(() => rows.value.map((r) => ({ ...r, _key: `${r.kind ?? 'sale'}:${r.order_id}` })));
function open_(row) {
  if (row.kind === 'preorder') {
    emit('close');
    router.push({ name: 'preorders', query: { preorder_id: row.order_id } });
  } else {
    orderId.value = row.order_id;
  }
}

async function load(page = 1) {
  loading.value = true;
  failed.value = false;
  try {
    const res = await props.loader(page);
    rows.value = res.data;
    meta.value = res.meta;
  } catch {
    failed.value = true;
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

watch(() => props.open, (isOpen) => { if (isOpen) load(1); }, { immediate: true });

const columns = () => [
  { key: 'order_number', label: t('sales_reports.col_transaction') },
  { key: 'created_at', label: t('sales_reports.col_time') },
  { key: 'cashier_name', label: t('sales_reports.col_cashier') },
  ...(props.showQty ? [{ key: 'qty', label: t('sales_reports.col_qty') }] : []),
  { key: 'amount', label: props.amountLabel },
  ...(props.showVerification ? [{ key: 'verification', label: t('sales_reports.col_verification') }] : []),
];
</script>

<template>
  <BaseModal :open="open" :title="title" max-width-class="max-w-[760px]" @close="emit('close')">
    <div class="flex flex-col gap-3 px-6 py-5">
      <div v-if="failed" class="flex flex-col items-start gap-2 text-[13px]">
        <span class="text-danger-text">{{ t('sales_reports.load_failed') }}</span>
        <BaseButton variant="secondary" size="sm" @click="load(meta.current_page)">{{ t('common.retry') }}</BaseButton>
      </div>
      <div v-else class="overflow-hidden rounded-card border border-line-2">
        <DataTable :columns="columns()" :rows="keyed" row-key="_key" :loading="loading" :empty-message="t('sales_reports.no_transactions')">
          <template #cell-order_number="{ row }">
            <button type="button" class="whitespace-nowrap font-mono text-[12.5px] font-bold text-brand-active underline decoration-dotted" @click="open_(row)">{{ row.order_number }}</button>
            <StatusPill v-if="row.kind === 'preorder'" variant="warn" class="ml-1.5" data-testid="preorder-badge">{{ t('sales_reports.kind_preorder') }}</StatusPill>
          </template>
          <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
          <template #cell-amount="{ row }"><span class="whitespace-nowrap">{{ formatIDR(row.amount) }}</span></template>
          <template #cell-verification="{ row }">
            <StatusPill :variant="row.verification === 'pending' ? 'warn' : 'mint'">
              {{ row.verification === 'pending' ? t('payment_ledger.verification_pending') : t('payment_ledger.verification_verified') }}
            </StatusPill>
          </template>
        </DataTable>
        <TablePagination v-if="meta.total > 0" :meta="meta" @change="load" />
      </div>
    </div>
  </BaseModal>

  <TransactionItemsModal :open="orderId !== null" :order-id="orderId" @close="orderId = null" />
</template>
