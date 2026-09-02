<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { listEvents } from '../api/events';
import { salesReport, exportReport } from '../api/reports';
import { useToastStore } from '../stores/toast';
import { formatIDR } from '../utils/money';
import { formatDateTime } from '../utils/date';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import DataTable from '../components/ui/DataTable.vue';
import ProductDetailModal from '../components/product/ProductDetailModal.vue';
import ReceiptModal from '../components/receipt/ReceiptModal.vue';

// Dikeluarkan dari ReportsView.vue menjadi menu tersendiri — laporan
// penjualan terbuka untuk semua peran (termasuk kasir), berbeda dari
// Rekap Artist/Modal & Untung/Modal Artist di halaman Laporan yang
// sengaja dibatasi owner/admin saja (PRD 7.13). Menggabungkannya dalam
// satu halaman membuat kasir melihat tab kosong tak berguna sebelum ini.
const toast = useToastStore();

const events = ref([]);
const eventId = ref('');
const groupBy = ref('product');
const sales = ref(null);
const loading = ref(false);

onMounted(async () => {
  events.value = (await listEvents({ per_page: 100 })).data;
  const active = events.value.find((e) => e.status === 'active');
  eventId.value = active?.id ?? events.value[0]?.id ?? '';
  await load();
});

watch([eventId, groupBy], load);

async function load() {
  loading.value = true;
  try {
    sales.value = await salesReport({ event_id: eventId.value || undefined, group_by: groupBy.value });
  } finally {
    loading.value = false;
  }
}

async function doExport() {
  try {
    await exportReport('sales', { event_id: eventId.value || undefined });
  } catch {
    toast.error('Gagal mengekspor laporan.');
  }
}

// Server supplies the column label per group_by ("Produk"/"Kategori"/"Artist"/"Tanggal")
// — never hardcode it here, that's the exact bug the user reported.
const salesColumns = computed(() => [{ key: 'label', label: sales.value?.group_label ?? 'Label' }, { key: 'unit_count', label: 'Unit' }, { key: 'amount', label: 'Jumlah' }]);

const showProductDetail = ref(false);
const detailProductId = ref(null);

function openRowDetail(row) {
  if (groupBy.value !== 'product' || !row.entity_id) return;
  detailProductId.value = row.entity_id;
  showProductDetail.value = true;
}

const transactionColumns = [
  { key: 'order_number', label: 'No. transaksi' },
  { key: 'customer_name', label: 'Pelanggan' },
  { key: 'created_at', label: 'Waktu' },
  { key: 'cashier_name', label: 'Kasir' },
  { key: 'item_count', label: 'Item' },
  { key: 'total_amount', label: 'Total' },
  { key: 'actions', label: '' },
];

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
    return orderNumber.includes(q) || customerName.includes(q) || cashierName.includes(q);
  });
});

const showReceipt = ref(false);
const receiptOrderId = ref(null);

function openReceipt(transaction) {
  receiptOrderId.value = transaction.id;
  showReceipt.value = true;
}
</script>

<template>
  <div class="flex flex-col gap-4 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <BaseSelect class="w-56" v-model="eventId" placeholder="Semua event" :options="events.map((e) => ({ value: e.id, label: e.name }))" />
      <BaseSelect
        class="w-40"
        v-model="groupBy"
        :options="[
          { value: 'product', label: 'Per produk' },
          { value: 'category', label: 'Per kategori' },
          { value: 'artist', label: 'Per artist' },
          { value: 'day', label: 'Per hari' },
        ]"
      />
      <span class="flex-1"></span>
      <BaseButton variant="secondary" @click="doExport">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        Ekspor .xlsx
      </BaseButton>
    </div>

    <div class="grid grid-cols-2 gap-3.5 sm:grid-cols-4">
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Transaksi</span><span class="text-[23px] font-extrabold tracking-tight">{{ sales?.totals?.order_count ?? 0 }}</span></div>
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Unit terjual</span><span class="text-[23px] font-extrabold tracking-tight">{{ sales?.totals?.unit_count ?? 0 }}</span></div>
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Penjualan kotor</span><span class="text-[23px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.gross_sales ?? 0) }}</span></div>
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Penjualan bersih</span><span class="text-[23px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.net_sales ?? 0) }}</span></div>
    </div>
    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="salesColumns" :rows="sales?.rows ?? []" :loading="loading" empty-message="Belum ada data penjualan.">
        <template #cell-label="{ row }">
          <button
            v-if="groupBy === 'product' && row.entity_id"
            type="button"
            class="text-left font-semibold text-brand-active underline decoration-dotted hover:text-brand"
            @click="openRowDetail(row)"
          >
            {{ row.label }}
          </button>
          <span v-else>{{ row.label }}</span>
        </template>
        <template #cell-amount="{ row }">{{ formatIDR(row.amount) }}</template>
      </DataTable>
    </div>

    <!-- Daftar transaksi asli — ringkasan per produk di atas mengelompokkan
         per produk (2 baris bisa mewakili 3 transaksi bila dua di antaranya
         membeli produk yang sama), jadi daftar ini ditampilkan berdampingan
         supaya jumlah transaksi selalu bisa diverifikasi langsung. -->
    <div class="flex flex-col gap-2">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="text-[13px] font-bold tracking-tight">Daftar transaksi ({{ filteredTransactions.length }})</span>
        <div class="relative w-64">
          <i class="ph-duotone ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[14px] text-muted-3" aria-hidden="true"></i>
          <input
            v-model="transactionSearch"
            type="search"
            placeholder="Cari no. transaksi, pelanggan, atau kasir…"
            class="w-full rounded-lg border border-line-2 bg-white py-2 pl-8 pr-3 text-[12.5px] outline-none focus:border-brand-active"
            aria-label="Cari transaksi"
          />
        </div>
      </div>
      <div class="overflow-hidden rounded-card border border-line-2 bg-white">
        <DataTable
          :columns="transactionColumns"
          :rows="filteredTransactions"
          :loading="loading"
          :empty-message="transactionSearch ? 'Tidak ada transaksi yang cocok dengan pencarian.' : 'Belum ada transaksi.'"
        >
          <template #cell-order_number="{ row }"><span class="font-mono text-[12.5px] font-bold text-brand-active">{{ row.order_number }}</span></template>
          <template #cell-customer_name="{ row }"><span :class="!row.customer_name ? 'text-muted-3' : ''">{{ row.customer_name ?? 'Walk-in' }}</span></template>
          <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
          <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
          <template #cell-actions="{ row }">
            <button type="button" class="text-[12.5px] font-semibold text-brand-active" @click="openReceipt(row)">Lihat struk</button>
          </template>
        </DataTable>
      </div>
    </div>

    <ProductDetailModal :open="showProductDetail" :product-id="detailProductId" @close="showProductDetail = false" />
    <ReceiptModal :open="showReceipt" :order-id="receiptOrderId" close-label="Tutup" @close="showReceipt = false" />
  </div>
</template>
