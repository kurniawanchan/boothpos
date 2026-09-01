<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { listEvents } from '../api/events';
import {
  salesReport,
  artistSettlements,
  profitReport,
  artistProfitReport,
  recordSettlementPayment,
  exportReport,
} from '../api/reports';
import { formatIDR, parseMoney, toMoneyString } from '../utils/money';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import DataTable from '../components/ui/DataTable.vue';
import ProductDetailModal from '../components/product/ProductDetailModal.vue';
import ReceiptModal from '../components/receipt/ReceiptModal.vue';
import ArtistTransactionsModal from '../components/report/ArtistTransactionsModal.vue';
import { formatDateTime } from '../utils/date';

const auth = useAuthStore();
const toast = useToastStore();

const events = ref([]);
const eventId = ref('');
const activeTab = ref('sales');
const groupBy = ref('product');

const sales = ref(null);
const settlements = ref(null);
const profit = ref(null);
const artistProfit = ref(null);
const loading = ref(false);

const tabs = computed(() => {
  const t = [{ key: 'sales', label: 'Penjualan' }];
  if (auth.isOwnerOrAdmin) {
    t.push(
      { key: 'settlement', label: 'Rekap Artist' },
      { key: 'profit', label: 'Modal & Untung' },
      // F9.5 — laporan modal & laba kotor PER ARTIST, tab terpisah dari
      // "Modal & Untung" (yang berskala event) karena angkanya sengaja
      // TIDAK dikurangi biaya event dan akan salah dibaca kalau ditumpuk
      // di tab yang sama tanpa pemisahan visual yang jelas.
      { key: 'artist-profit', label: 'Modal Artist' }
    );
  }
  return t;
});

onMounted(async () => {
  events.value = (await listEvents({ per_page: 100 })).data;
  const active = events.value.find((e) => e.status === 'active');
  eventId.value = active?.id ?? events.value[0]?.id ?? '';
  await loadActiveTab();
});

watch([eventId, activeTab, groupBy], loadActiveTab);

async function loadActiveTab() {
  loading.value = true;
  try {
    if (activeTab.value === 'sales') {
      sales.value = await salesReport({ event_id: eventId.value || undefined, group_by: groupBy.value });
    } else if (activeTab.value === 'settlement' && eventId.value) {
      const res = await artistSettlements(eventId.value);
      settlements.value = res.data;
    } else if (activeTab.value === 'profit' && eventId.value) {
      profit.value = await profitReport(eventId.value);
    } else if (activeTab.value === 'artist-profit' && eventId.value) {
      const res = await artistProfitReport(eventId.value);
      artistProfit.value = res.data;
    }
  } finally {
    loading.value = false;
  }
}

async function doExport(report) {
  try {
    await exportReport(report, { event_id: eventId.value || undefined });
  } catch {
    toast.error('Gagal mengekspor laporan.');
  }
}

const showSettlementPay = ref(false);
const settlementTarget = ref(null);
const settlementAmount = ref('0');
const settlementNotes = ref('');
const payingSettlement = ref(false);

function openSettlementPay(row) {
  settlementTarget.value = row;
  settlementAmount.value = row.outstanding;
  settlementNotes.value = '';
  showSettlementPay.value = true;
}

async function submitSettlementPayment() {
  payingSettlement.value = true;
  try {
    await recordSettlementPayment(settlementTarget.value.id, {
      amount: toMoneyString(settlementAmount.value),
      notes: settlementNotes.value || null,
    });
    toast.success('Pembayaran ke artist tercatat.');
    showSettlementPay.value = false;
    await loadActiveTab();
  } catch (err) {
    if (err.isValidation) toast.error(Object.values(err.errors)[0]?.[0] ?? err.message);
  } finally {
    payingSettlement.value = false;
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
// bukan lewat parameter query baru (lihat komentar di sales() backend:
// kriteria penerimaannya eksplisit meminta "tanpa perlu memuat ulang
// seluruh laporan"). Nama pelanggan nullable untuk order walk-in — baris
// itu cukup tidak cocok pada kriteria nama, bukan error.
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

// F11.6 — drill-down transaksi per artist dari tab Rekap Artist.
const showArtistTransactions = ref(false);
const artistTransactionsTarget = ref(null);

function openArtistTransactions(row) {
  artistTransactionsTarget.value = row;
  showArtistTransactions.value = true;
}
</script>

<template>
  <div class="flex flex-col gap-4 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="flex gap-1.5 rounded-lg bg-track p-1">
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          class="rounded-md px-3.5 py-2 text-[13px] font-bold transition-colors"
          :class="activeTab === t.key ? 'bg-white text-brand-active shadow-sm' : 'text-muted-4'"
          @click="activeTab = t.key"
        >
          {{ t.label }}
        </button>
      </div>
      <BaseSelect class="w-56" v-model="eventId" placeholder="Semua event" :options="events.map((e) => ({ value: e.id, label: e.name }))" />
      <BaseSelect
        v-if="activeTab === 'sales'"
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
      <BaseButton v-if="activeTab === 'sales'" variant="secondary" @click="doExport('sales')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        Ekspor .xlsx
      </BaseButton>
      <BaseButton v-if="activeTab === 'settlement'" variant="secondary" @click="doExport('artist-settlements')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        Ekspor .xlsx
      </BaseButton>
      <BaseButton v-if="activeTab === 'profit'" variant="secondary" @click="doExport('profit')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        Ekspor .xlsx
      </BaseButton>
      <BaseButton v-if="activeTab === 'artist-profit'" variant="secondary" @click="doExport('artist-profit')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        Ekspor .xlsx
      </BaseButton>
    </div>

    <!-- Sales tab -->
    <template v-if="activeTab === 'sales'">
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
    </template>

    <!-- Settlement tab (owner/admin only) -->
    <template v-else-if="activeTab === 'settlement'">
      <EmptyState v-if="!eventId" icon="ph-calendar-dots" message="Pilih event untuk melihat rekap artist." />
      <div v-else class="overflow-hidden rounded-card border border-line-2 bg-white">
        <DataTable
          :columns="[
            { key: 'artist_name', label: 'Artist' },
            { key: 'total_units', label: 'Unit' },
            { key: 'total_sales', label: 'Penjualan' },
            { key: 'payable_amount', label: 'Wajib dibayar' },
            { key: 'paid_amount', label: 'Sudah dibayar' },
            { key: 'outstanding', label: 'Sisa' },
            { key: 'status', label: 'Status' },
            { key: 'actions', label: '' },
          ]"
          :rows="settlements ?? []"
          :loading="loading"
          row-key="artist_id"
          empty-message="Belum ada artist aktif — rekap ini mendaftar setiap artist aktif untuk event ini, sekalipun belum ada penjualan."
        >
          <template #cell-total_sales="{ row }">{{ formatIDR(row.total_sales) }}</template>
          <template #cell-payable_amount="{ row }">{{ formatIDR(row.payable_amount) }}</template>
          <template #cell-paid_amount="{ row }">{{ formatIDR(row.paid_amount) }}</template>
          <template #cell-outstanding="{ row }">{{ formatIDR(row.outstanding) }}</template>
          <template #cell-status="{ row }">
            <span class="text-[12px] font-semibold capitalize" :class="row.status === 'paid' ? 'text-brand-active' : 'text-warn-text'">{{ row.status }}</span>
          </template>
          <template #cell-actions="{ row }">
            <div class="flex items-center justify-end gap-3">
              <!-- F11.6 — drill-down tersedia untuk artist manapun di rekap
                   ini (memakai artist_id, yang selalu ada — lihat komentar
                   row-key di atas), bukan hanya yang sudah punya baris
                   settlement, supaya "belum ada penjualan" juga bisa
                   diverifikasi langsung sebagai daftar kosong, bukan
                   kontrol yang hilang begitu saja. -->
              <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openArtistTransactions(row)">Detail transaksi</button>
              <!-- id is null until a real settlement row exists (an artist
                   with zero sales this event) — there is nothing to record a
                   payment against yet, so the action must stay hidden rather
                   than firing a request the backend can't resolve. -->
              <button v-if="row.id !== null && parseMoney(row.outstanding) > 0" type="button" class="text-[12.5px] font-semibold text-brand-active" @click="openSettlementPay(row)">Catat bayar</button>
            </div>
          </template>
        </DataTable>
      </div>
    </template>

    <!-- Profit tab (owner/admin only — hidden entirely for cashier/inventory) -->
    <template v-else-if="activeTab === 'profit'">
      <EmptyState v-if="!eventId" icon="ph-calendar-dots" message="Pilih event untuk melihat laporan modal & keuntungan." />
      <div v-else-if="profit" class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Pendapatan</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.revenue) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Harga pokok</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.cost_of_goods) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Laba kotor</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.gross_profit) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Biaya event</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.event_cost) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-mint-border bg-mint-50 p-4"><span class="text-[11.5px] font-semibold text-brand-active">Laba bersih</span><span class="text-[21px] font-extrabold tracking-tight text-brand-active">{{ formatIDR(profit.net_profit) }}</span></div>
      </div>
      <p class="text-[11.5px] leading-relaxed text-muted-3">Laba kotor memakai nilai snapshot pada order_items, bukan harga master saat laporan dibuka.</p>
    </template>

    <!-- Artist profit tab (F9.5, owner/admin only) -->
    <template v-else-if="activeTab === 'artist-profit'">
      <EmptyState v-if="!eventId" icon="ph-calendar-dots" message="Pilih event untuk melihat laporan modal & laba per artist." />
      <template v-else>
        <!-- Penjelasan ini WAJIB ada — angka di sini sengaja tidak dikurangi
             event_cost (lihat kriteria penerimaan F9.5), dan tanpa catatan
             ini pengguna gampang membacanya sebagai versi turunan/salah
             dari "Modal & Untung" tingkat event, padahal itu laporan lain. -->
        <div class="flex items-start gap-2.5 rounded-lg border border-line-2 bg-surface-subtle px-4 py-3">
          <i class="ph-duotone ph-info text-[16px] text-muted-3" aria-hidden="true"></i>
          <p class="text-[12px] leading-relaxed text-muted-3">
            Laba kotor per artist di sini <span class="font-semibold text-muted-4">belum dikurangi biaya event</span> — biaya event sudah diperhitungkan terpisah pada laba bersih tingkat event di tab "Modal & Untung", supaya tidak dobel-hitung atau dialokasikan tidak adil antar artist.
          </p>
        </div>
        <div class="overflow-hidden rounded-card border border-line-2 bg-white">
          <DataTable
            :columns="[
              { key: 'artist_name', label: 'Artist' },
              { key: 'total_sales', label: 'Penjualan' },
              { key: 'modal', label: 'Modal' },
              { key: 'gross_profit', label: 'Laba kotor' },
            ]"
            :rows="artistProfit ?? []"
            :loading="loading"
            row-key="artist_id"
            empty-message="Belum ada penjualan artist pada event ini."
          >
            <template #cell-total_sales="{ row }">{{ formatIDR(row.total_sales) }}</template>
            <template #cell-modal="{ row }">{{ formatIDR(row.modal) }}</template>
            <template #cell-gross_profit="{ row }"><span class="font-semibold text-brand-active">{{ formatIDR(row.gross_profit) }}</span></template>
          </DataTable>
        </div>
      </template>
    </template>

    <BaseModal :open="showSettlementPay" title="Catat pembayaran ke artist" max-width-class="max-w-[400px]" @close="showSettlementPay = false">
      <div class="flex flex-col gap-3.5 px-6 py-5">
        <p class="text-[13px] text-muted-4">{{ settlementTarget?.artist_name }} · sisa {{ formatIDR(settlementTarget?.outstanding) }}</p>
        <BaseInput v-model="settlementAmount" type="number" min="0" label="Jumlah dibayar (Rp)" />
        <BaseInput v-model="settlementNotes" label="Catatan (opsional)" />
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showSettlementPay = false">Batal</BaseButton>
          <BaseButton :loading="payingSettlement" @click="submitSettlementPayment">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ProductDetailModal :open="showProductDetail" :product-id="detailProductId" @close="showProductDetail = false" />
    <ReceiptModal :open="showReceipt" :order-id="receiptOrderId" close-label="Tutup" @close="showReceipt = false" />
    <ArtistTransactionsModal
      :open="showArtistTransactions"
      :artist-id="artistTransactionsTarget?.artist_id"
      :artist-name="artistTransactionsTarget?.artist_name"
      :event-id="eventId"
      @close="showArtistTransactions = false"
    />
  </div>
</template>
