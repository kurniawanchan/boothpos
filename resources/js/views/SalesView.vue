<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { listEvents } from '../api/events';
import { salesReport, exportReport } from '../api/reports';
import { useToastStore } from '../stores/toast';
import { formatIDR } from '../utils/money';
import { formatDateTime } from '../utils/date';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import DataTable from '../components/ui/DataTable.vue';
import TransactionItemsModal from '../components/sales/TransactionItemsModal.vue';
import ReceiptModal from '../components/receipt/ReceiptModal.vue';

// Dikeluarkan dari ReportsView.vue menjadi menu tersendiri — laporan
// penjualan terbuka untuk semua peran (termasuk kasir), berbeda dari
// Rekap Artist/Modal & Untung/Modal Artist di halaman Laporan yang
// sengaja dibatasi owner/admin saja (PRD 7.13). Menggabungkannya dalam
// satu halaman membuat kasir melihat tab kosong tak berguna sebelum ini.
const toast = useToastStore();
const { t } = useI18n();

const events = ref([]);
const eventId = ref('');
const sales = ref(null);
const loading = ref(false);

onMounted(async () => {
  events.value = (await listEvents({ per_page: 100 })).data;
  const active = events.value.find((e) => e.status === 'active');
  eventId.value = active?.id ?? events.value[0]?.id ?? '';
  await load();
});

watch(eventId, load);

async function load() {
  loading.value = true;
  try {
    // 009-ui-ux-refinements US2 (T016) — group_by tidak lagi dikirim: tabel
    // ringkasan per produk/kategori/artist/hari dihapus dari halaman ini,
    // hanya daftar transaksi mentah yang dipakai sekarang. Backend tetap
    // default ke 'product' bila parameter ini tak ada (ReportController::sales()),
    // jadi totals/transactions tidak berubah bentuk.
    sales.value = await salesReport({ event_id: eventId.value || undefined });
  } finally {
    loading.value = false;
  }
}

async function doExport() {
  try {
    await exportReport('sales', { event_id: eventId.value || undefined });
  } catch {
    toast.error(t('reports.export_report_failed'));
  }
}

const transactionColumns = computed(() => [
  { key: 'order_number', label: t('reports.col_transaction_no') },
  { key: 'customer_name', label: t('reports.col_customer') },
  { key: 'artist_names', label: t('reports.col_artist') },
  { key: 'created_at', label: t('reports.col_time') },
  { key: 'cashier_name', label: t('reports.col_cashier') },
  { key: 'item_count', label: t('reports.col_item') },
  { key: 'total_amount', label: t('reports.col_total') },
  { key: 'actions', label: '' },
]);

// F10.6 — pencarian client-side atas transactions[] yang sudah dimuat,
// bukan lewat parameter query baru (kriteria penerimaannya eksplisit
// meminta "tanpa perlu memuat ulang seluruh laporan"). Nama pelanggan
// nullable untuk order walk-in — baris itu cukup tidak cocok pada
// kriteria nama, bukan error.
const transactionSearch = ref('');

const filteredTransactions = computed(() => {
  const all = sales.value?.transactions ?? [];
  const q = transactionSearch.value.trim().toLowerCase();
  if (!q) return all;
  return all.filter((t) => {
    const orderNumber = (t.order_number ?? '').toLowerCase();
    const customerName = (t.customer_name ?? '').toLowerCase();
    const cashierName = (t.cashier_name ?? '').toLowerCase();
    // 003-seed-demo-live follow-up (FR-018) — cocok bila SALAH SATU nama
    // artist di transaksi ini mengandung kata kunci (satu order booth
    // multi-artist bisa berisi barang dari beberapa artist sekaligus).
    const artistNames = (t.artist_names ?? []).join(' ').toLowerCase();
    return orderNumber.includes(q) || customerName.includes(q) || cashierName.includes(q) || artistNames.includes(q);
  });
});

// T017 — klik nomor transaksi tetap membuka popup "Produk Terjual"
// (TransactionItemsModal, T015/T016), bukan ReceiptModal (struk cetak).
// 014-sales-receipt-event-footer US1 — struk itu sendiri kini bisa
// dilihat lagi lewat tombol "View receipt" terpisah di kolom aksi.
const showItems = ref(false);
const itemsOrderId = ref(null);

function openItems(transaction) {
  itemsOrderId.value = transaction.id;
  showItems.value = true;
}

const showReceipt = ref(false);
const receiptOrderId = ref(null);

function openReceipt(row) {
  receiptOrderId.value = row.id;
  showReceipt.value = true;
}

// Follow-up 2 (FR-023) — klik nama artist adalah pintasan mengisi kotak
// pencarian yang sudah ada (Follow-up 1), bukan layar/filter baru.
function searchByArtist(name) {
  transactionSearch.value = name;
}

// Follow-up 2 (FR-022) — popover ringan pakai data yang SUDAH ada di baris
// transaksi (customer_phone/customer_email dari ReportController::sales()),
// bukan endpoint GET /customers/{id} baru (tidak ada di CustomerController).
const detailCustomer = ref(null);

function showCustomerDetail(row) {
  if (!row.customer_name) return;
  detailCustomer.value = { name: row.customer_name, phone: row.customer_phone, email: row.customer_email };
}
</script>

<template>
  <div class="flex flex-col gap-4 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <BaseSelect class="w-56" v-model="eventId" :placeholder="t('reports.all_events')" :options="events.map((e) => ({ value: e.id, label: e.name }))" />
      <span class="flex-1"></span>
      <BaseButton variant="secondary" @click="doExport">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        {{ t('common.export_xlsx') }}
      </BaseButton>
    </div>

    <div class="grid grid-cols-2 gap-3.5 sm:grid-cols-4">
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.transactions') }}</span><span class="text-[23px] font-extrabold tracking-tight">{{ sales?.totals?.order_count ?? 0 }}</span></div>
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.units_sold') }}</span><span class="text-[23px] font-extrabold tracking-tight">{{ sales?.totals?.unit_count ?? 0 }}</span></div>
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.gross_sales') }}</span><span class="text-[23px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.gross_sales ?? 0) }}</span></div>
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.net_sales') }}</span><span class="text-[23px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.net_sales ?? 0) }}</span></div>
    </div>

    <!-- 009-ui-ux-refinements US2 (T016) — daftar transaksi mentah kini
         konten utama/paling atas halaman ini; tabel ringkasan per
         produk/kategori/artist/hari (dan selektor group_by-nya) DIHAPUS,
         bukan disusun ulang, per FR-004/Acceptance Scenario 1. -->
    <div class="flex flex-col gap-2">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="text-[13px] font-bold tracking-tight">{{ t('reports.transaction_list', { count: filteredTransactions.length }) }}</span>
        <div class="relative w-64">
          <i class="ph-duotone ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[14px] text-muted-3" aria-hidden="true"></i>
          <input
            v-model="transactionSearch"
            type="search"
            :placeholder="t('reports.search_transaction_placeholder')"
            class="w-full rounded-lg border border-line-2 bg-white py-2 pl-8 pr-3 text-[12.5px] outline-none focus:border-brand-active"
            :aria-label="t('reports.search_transaction')"
          />
        </div>
      </div>
      <div class="overflow-hidden rounded-card border border-line-2 bg-white">
        <DataTable
          :columns="transactionColumns"
          :rows="filteredTransactions"
          :loading="loading"
          :empty-message="transactionSearch ? t('reports.no_matching_transactions') : t('reports.no_transactions')"
        >
          <template #cell-order_number="{ row }">
            <button type="button" class="font-mono text-[12.5px] font-bold text-brand-active underline decoration-dotted" @click="openItems(row)">{{ row.order_number }}</button>
          </template>
          <template #cell-customer_name="{ row }">
            <button
              v-if="row.customer_name"
              type="button"
              class="text-left underline decoration-dotted hover:text-brand-active"
              @click="showCustomerDetail(row)"
            >{{ row.customer_name }}</button>
            <span v-else class="text-muted-3">{{ t('reports.walkin') }}</span>
          </template>
          <template #cell-artist_names="{ row }">
            <span class="flex flex-wrap gap-x-1 text-[12.5px] text-muted-4">
              <template v-if="(row.artist_names ?? []).length">
                <span v-for="(name, i) in row.artist_names" :key="name">
                  <button type="button" class="underline decoration-dotted hover:text-brand-active" @click="searchByArtist(name)">{{ name }}</button><template v-if="i < row.artist_names.length - 1">,</template>
                </span>
              </template>
              <span v-else>—</span>
            </span>
          </template>
          <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
          <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
          <template #cell-actions="{ row }">
            <button type="button" class="text-[12.5px] font-semibold text-brand-active" @click="openItems(row)">{{ t('reports.view_items') }}</button>
            <button type="button" class="ml-3 text-[12.5px] font-semibold text-brand-active" @click="openReceipt(row)">{{ t('reports.view_receipt') }}</button>
          </template>
        </DataTable>
      </div>
    </div>

    <TransactionItemsModal :open="showItems" :order-id="itemsOrderId" @close="showItems = false" />
    <ReceiptModal :open="showReceipt" :order-id="receiptOrderId" @close="showReceipt = false" />

    <BaseModal :open="detailCustomer !== null" :title="t('reports.customer_detail')" max-width-class="max-w-[360px]" @close="detailCustomer = null">
      <div v-if="detailCustomer" class="flex flex-col gap-2.5 px-6 py-5 text-[13.5px]">
        <div class="flex flex-col gap-0.5">
          <span class="text-[11.5px] font-semibold text-muted-3">{{ t('reports.col_customer') }}</span>
          <span class="font-semibold">{{ detailCustomer.name }}</span>
        </div>
        <div v-if="detailCustomer.phone" class="flex flex-col gap-0.5">
          <span class="text-[11.5px] font-semibold text-muted-3">{{ t('settings.phone') }}</span>
          <span>{{ detailCustomer.phone }}</span>
        </div>
        <div v-if="detailCustomer.email" class="flex flex-col gap-0.5">
          <span class="text-[11.5px] font-semibold text-muted-3">{{ t('settings.email') }}</span>
          <span>{{ detailCustomer.email }}</span>
        </div>
      </div>
    </BaseModal>
  </div>
</template>
