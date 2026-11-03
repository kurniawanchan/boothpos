<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { salesByPaymentMethodReport, salesByPaymentMethodTransactions } from '../../api/reports';
import { formatIDR } from '../../utils/money';
import { paymentMethodLabel } from '../../utils/paymentMethods';
import DataTable from '../ui/DataTable.vue';
import BaseButton from '../ui/BaseButton.vue';
import ReportTransactionsModal from './ReportTransactionsModal.vue';

/**
 * 054 (US11) — tab "Penjualan per metode pembayaran": jumlah transaksi dan uang per metode/kanal, uang yang
 * sudah vs belum diverifikasi, dan daftar transaksi per metode. Semua angka dari SERVER dengan definisi yang
 * sama dengan halaman Sales (tunai bersih kembalian, split payment per porsi, batal/ditolak tak ikut).
 */
const props = defineProps({ eventId: { type: [Number, String], default: '' } });
const { t } = useI18n();

const report = ref(null);
const loading = ref(false);
const failed = ref(false);
const detail = ref(null);

let seq = 0;
async function load() {
  const mine = ++seq;
  loading.value = true;
  failed.value = false;
  try {
    const res = await salesByPaymentMethodReport({ event_id: props.eventId || undefined });
    if (mine === seq) report.value = res;
  } catch {
    if (mine === seq) failed.value = true;
  } finally {
    if (mine === seq) loading.value = false;
  }
}
onMounted(load);
watch(() => props.eventId, load);

// Baris tak punya `id` alami (tunai tak punya kanal): kunci sintetis, supaya DataTable tak menyatukan baris.
const rows = computed(() => (report.value?.data ?? []).map((r) => ({ ...r, key: `${r.method}__${r.channel_id ?? 'none'}` })));
const columns = computed(() => [
  { key: 'label', label: t('sales_reports.col_method') },
  { key: 'transaction_count', label: t('sales_reports.col_transactions') },
  { key: 'amount', label: t('sales_reports.col_amount') },
  { key: 'verified_amount', label: t('sales_reports.col_verified') },
  { key: 'unverified_amount', label: t('sales_reports.col_unverified') },
  { key: 'actions', label: '' },
]);

const methodText = (r) => (r.method === 'cash' ? paymentMethodLabel(t, 'cash') : `${paymentMethodLabel(t, r.method)}${r.label && r.label !== r.method ? ` · ${r.label}` : ''}`);

const detailLoader = computed(() => (page) => salesByPaymentMethodTransactions({
  method: detail.value.method, channel_id: detail.value.channel_id ?? undefined, event_id: props.eventId || undefined, page,
}));
</script>

<template>
  <div class="flex flex-col gap-3" data-testid="sales-by-payment-method">
    <div v-if="failed" class="flex flex-col items-start gap-2 text-[13px]">
      <span class="text-danger-text">{{ t('sales_reports.load_failed') }}</span>
      <BaseButton variant="secondary" size="sm" @click="load">{{ t('common.retry') }}</BaseButton>
    </div>

    <div v-else class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="rows" row-key="key" :loading="loading" :empty-message="t('sales_reports.no_sales')">
        <template #cell-label="{ row }"><span class="font-semibold">{{ methodText(row) }}</span></template>
        <template #cell-amount="{ row }"><span class="whitespace-nowrap font-semibold">{{ formatIDR(row.amount) }}</span></template>
        <template #cell-verified_amount="{ row }"><span class="whitespace-nowrap">{{ formatIDR(row.verified_amount) }}</span></template>
        <template #cell-unverified_amount="{ row }">
          <span class="whitespace-nowrap" :class="Number(row.unverified_amount) > 0 ? 'font-semibold text-warn-text' : ''">{{ formatIDR(row.unverified_amount) }}</span>
        </template>
        <template #cell-actions="{ row }">
          <button type="button" class="whitespace-nowrap text-[12.5px] font-semibold text-brand-active hover:underline" data-testid="open-transactions" @click="detail = row">{{ t('sales_reports.view_transactions') }}</button>
        </template>
      </DataTable>
      <div v-if="report && rows.length" class="flex flex-wrap items-baseline justify-between gap-2 border-t border-line-3 bg-surface-subtle px-4 py-3 text-[12.5px] font-semibold" data-testid="method-totals">
        <span>{{ t('sales_reports.grand_total') }}</span>
        <span>{{ formatIDR(report.totals.amount) }} · {{ t('sales_reports.transactions_n', { count: report.totals.transaction_count }) }} · {{ t('sales_reports.col_unverified') }} {{ formatIDR(report.totals.unverified_amount) }}</span>
      </div>
    </div>

    <ReportTransactionsModal
      :open="detail !== null"
      :title="detail ? t('sales_reports.transactions_of', { name: methodText(detail) }) : ''"
      :loader="detailLoader"
      :amount-label="t('sales_reports.col_method_amount')"
      show-verification
      @close="detail = null"
    />
  </div>
</template>
