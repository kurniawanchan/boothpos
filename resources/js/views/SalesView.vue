<script setup>
import { ref, computed, watch, onMounted, nextTick, defineAsyncComponent } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { salesReport, exportSalesTransactions } from '../api/reports';
import { verifyOrderPayments } from '../api/payments';
import { useToastStore } from '../stores/toast';
import { useAuthStore } from '../stores/auth';
import { useSalesFilters } from '../composables/useSalesFilters';
import { useQueryState } from '../composables/useQueryState';
import { formatIDR } from '../utils/money';
import { formatCount } from '../utils/formatters';
import { formatDateTime, toLocalDateKey } from '../utils/date';
import { paymentMethodLabel } from '../utils/paymentMethods';
import { saveBlob } from '../utils/saveBlob';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseMultiSelect from '../components/ui/BaseMultiSelect.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import DataTable from '../components/ui/DataTable.vue';
import PageHeader from '../components/page/PageHeader.vue';
import KpiStrip from '../components/page/KpiStrip.vue';
import ViewToggle from '../components/page/ViewToggle.vue';
import FilterBar from '../components/page/FilterBar.vue';
import TransactionItemsModal from '../components/sales/TransactionItemsModal.vue';
import { useOutletStore } from '../stores/outlet';
import ShiftSummaryPanel from '../components/sales/ShiftSummaryPanel.vue';
import CustomerQuickDetailModal from '../components/sales/CustomerQuickDetailModal.vue';
import ReceiptModal from '../components/receipt/ReceiptModal.vue';
import OpenOrderDetail from '../components/sales/OpenOrderDetail.vue';

// 044 — papan Meja dimuat malas: kodenya (dan polling lantai) hanya dibutuhkan pengguna yang membuka tampilan
// Meja, jadi tak ikut membebani chunk utama maupun pemuatan awal daftar order.
const FloorBoard = defineAsyncComponent(() => import('../components/sales/FloorBoard.vue'));

// Dikeluarkan dari ReportsView.vue menjadi menu tersendiri — laporan
// penjualan terbuka untuk semua peran (termasuk kasir), berbeda dari
// Rekap Outlet/Modal & Untung/Modal Outlet di halaman Laporan yang
// sengaja dibatasi owner/admin saja (PRD 7.13). Menggabungkannya dalam
// satu halaman membuat kasir melihat tab kosong tak berguna sebelum ini.
//
// Logika daftar (filter, urutan, ringkasan, shift, query URL) ada di
// composables/useSalesFilters.js; berkas ini hanya tampilan & interaksi.
const toast = useToastStore();
const { t } = useI18n();
const route = useRoute();
const router = useRouter(); // undefined bila tak ada router (mis. test lama) — sinkron URL dilewati
const auth = useAuthStore();
const outletStore = useOutletStore();

// --- saklar Daftar order | Meja (044-sales-table-view) -----------------------------------------
// Pilihan tersimpan di URL (?view=tables; kosong = daftar). Saklar HANYA ada bagi pengguna dengan akses lantai
// (disembunyikan, bukan dinonaktifkan); URL yang meminta Meja tanpa akses, atau nilai tak dikenal, jatuh ke daftar.
// Server tetap penjaga sebenarnya (GET /floor & /tabs/{id} menolak peran tanpa menu `floor`).
const { state: viewState, set: setViewState } = useQueryState({ view: 'list' });
const canSeeTables = computed(() => auth.canAccessMenu('floor'));
const view = computed(() => (viewState.view === 'tables' && canSeeTables.value ? 'tables' : 'list'));
const viewOptions = computed(() => [
  { value: 'list', label: t('sales.view_list'), icon: 'ph-list-bullets' },
  { value: 'tables', label: t('sales.view_tables'), icon: 'ph-squares-four' },
]);

const sales = ref(null);
const loading = ref(false);
// 041 — kegagalan memuat ditampilkan di tabel (dengan "Coba lagi"), bukan area kosong. Pesan spesifik
// (403/422/…) tetap ditoast oleh interceptor global; ini hanya jalan keluar yang jelas bagi pengguna.
const loadError = ref('');

const transactions = computed(() => sales.value?.transactions ?? []);
const sessions = computed(() => sales.value?.sessions ?? []);
const {
  search, filters, includeVoided, sortKey, sortDir,
  cashierOptions, outletOptions, paymentMethods,
  filtered, sorted, summary, shiftSummary, hasMargin,
  activeCount, hasFilterOtherThanSearch,
  reset, sortBy, toQuery, applyQuery,
} = useSalesFilters({ transactions, sessions });

// --- muat data --------------------------------------------------------------
let restored = false; // false selama query URL dipulihkan → jangan muat ganda

onMounted(async () => {
  // 046 — tanpa event: bawaan hari ini (tanggal bisnis); query URL `from`/`to` menimpanya, dan mengosongkan
  // tanggal berarti "semua".
  const query = route?.query ?? {};
  applyQuery(query);
  if (!('from' in query) && !('to' in query)) {
    const today = toLocalDateKey(new Date());
    filters.dateFrom = today;
    filters.dateTo = today;
  }
  restored = true;
  await load();
});

// Rentang tanggal juga dikirim ke SERVER (daftar dimuat per periode, bukan seluruh riwayat); sisanya tetap disaring di klien.
watch(() => [filters.dateFrom, filters.dateTo], () => { if (restored) load(); });
// 'sync': dijalankan seketika saat applyQuery() mengubah nilainya (restored masih false).
watch(includeVoided, () => { if (restored) load(); }, { flush: 'sync' });

async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    // group_by tidak dikirim (009 US2): hanya daftar transaksi yang dipakai. Transaksi
    // batal diminta ke SERVER — tidak ikut angka ringkasan, dan sengaja tidak dimuat
    // kecuali diminta.
    sales.value = await salesReport({
      date_from: filters.dateFrom || undefined,
      date_to: filters.dateTo || undefined,
      include_voided: includeVoided.value ? 1 : undefined,
    });
  } catch {
    // Data lama dibuang: angka KPI & baris dari pemuatan sebelumnya tak boleh tampak seolah masih berlaku.
    sales.value = null;
    loadError.value = t('page.load_failed');
  } finally {
    loading.value = false;
  }
}

// --- filter tersimpan di URL (bisa di-bookmark / dibagikan) -----------------------
const OUR_QUERY_KEYS = ['q', 'from', 'to', 'cashier', 'outlet', 'pay', 'session', 'cust', 'pstate', 'min', 'max', 'voided', 'sort', 'dir'];
watch(() => JSON.stringify(toQuery()), (json) => {
  if (!restored || !router) return;
  const others = Object.fromEntries(Object.entries(route.query).filter(([k]) => !OUR_QUERY_KEYS.includes(k)));
  router.replace({ query: { ...others, ...JSON.parse(json) } });
});

// --- opsi filter -------------------------------------------------------------------
const paymentLabel = (method) => paymentMethodLabel(t, method);
const paymentOptions = computed(() => paymentMethods.value.map((value) => ({ value, label: paymentLabel(value) })));
const customerTypeOptions = computed(() => [
  { value: 'named', label: t('reports.filter_customer_named') },
  { value: 'walkin', label: t('reports.filter_customer_walkin') },
]);
const paymentStateOptions = computed(() => [
  { value: 'pending', label: t('reports.paystate_needs_verification') },
  { value: 'rejected', label: t('reports.paystate_rejected') },
  { value: 'verified', label: t('reports.paystate_verified') },
]);
// 028-partial-split-payment — status pembayaran turunan (beda dari status verifikasi di atas).
const settlementOptions = computed(() => [
  { value: 'partially_paid', label: t('payment_ledger.status_partially_paid') },
  { value: 'unpaid', label: t('payment_ledger.status_unpaid') },
  { value: 'fully_paid', label: t('payment_ledger.status_fully_paid') },
]);
const sessionOptions = computed(() =>
  sessions.value.map((s) => ({
    value: s.id,
    label: `${s.cashier_name ?? '—'} · ${formatDateTime(s.opened_at)}${s.status === 'open' ? ` · ${t('reports.shift_active')}` : ''}`,
  }))
);

// --- preset tanggal: Hari ini dan Kemarin -------------------------
const datePresets = computed(() => {
  const yesterday = new Date();
  yesterday.setDate(yesterday.getDate() - 1);
  const list = [
    { id: 'today', label: t('reports.preset_today'), date: toLocalDateKey(new Date()) },
    { id: 'yesterday', label: t('reports.preset_yesterday'), date: toLocalDateKey(yesterday) },
  ];
  return list;
});
const isPresetActive = (p) => filters.dateFrom === p.date && filters.dateTo === p.date;
function applyPreset(p) {
  const clear = isPresetActive(p);
  filters.dateFrom = clear ? '' : p.date;
  filters.dateTo = clear ? '' : p.date;
}

// --- tabel -------------------------------------------------------------------------
// Kolom pilih tetap dirender di sini (bukan DataTable `selectable`): label per baris "Pilih {nomor}" jauh
// lebih jelas bagi pembaca layar daripada "Pilih baris" generik, dan tes/perilaku pilihan bergantung padanya.
const columns = computed(() => [
  { key: 'select', label: '' },
  { key: 'order_number', label: t('reports.col_transaction_no'), sortable: true },
  { key: 'customer_name', label: t('reports.col_customer'), sortable: true },
  { key: 'outlet_names', label: t('reports.col_artist'), sortable: true },
  { key: 'created_at', label: t('reports.col_time'), sortable: true },
  { key: 'cashier_name', label: t('reports.col_cashier'), sortable: true },
  // 045 Stage 4 — outlet tempat penjualan (outlet shift); hanya bila ada lebih dari satu outlet.
  ...(outletStore.multi ? [{ key: 'sold_at_name', label: t('reports.col_sold_at'), sortable: true }] : []),
  { key: 'item_count', label: t('reports.col_item'), sortable: true },
  { key: 'payment_methods', label: t('reports.col_payment'), sortable: true },
  // Kolom margin HANYA ada bila server mengirimnya (owner/admin) — bukan disembunyikan di sini.
  ...(hasMargin.value ? [{ key: 'margin_amount', label: t('reports.col_margin'), sortable: true }] : []),
  { key: 'total_amount', label: t('reports.col_total'), sortable: true },
  { key: 'actions', label: '' },
]);

// --- strip empat angka ------------------------------------------------------------------
// Angka = transaksi dari POS saja, sama dengan daftar di bawahnya. Angka laporan bersama
// (unit_count/gross_sales/net_sales) sama dengan angka POS sejak 046 (tanpa pre-order) dan dipakai Dashboard/Laporan;
// halaman ini memakai pos_* (dengan cadangan ke angka bersama untuk respons lama yang belum punya pos_*).
// Belum termuat / gagal memuat → "—" (unavailable), bukan 0.
const kpiItems = computed(() => {
  const totals = sales.value?.totals ?? null;
  const missing = totals === null;
  return [
    { key: 'transactions', label: t('reports.transactions'), tone: 'info', unavailable: missing, value: missing ? null : formatCount(totals.order_count ?? 0) },
    { key: 'units', label: t('reports.units_sold'), tone: 'accent', unavailable: missing, value: missing ? null : formatCount(totals.pos_unit_count ?? totals.unit_count ?? 0) },
    { key: 'gross', label: t('reports.gross_sales'), tone: 'warning', unavailable: missing, value: missing ? null : formatIDR(totals.pos_gross_sales ?? totals.gross_sales ?? 0) },
    { key: 'net', label: t('reports.net_sales'), tone: 'success', unavailable: missing, value: missing ? null : formatIDR(totals.pos_net_sales ?? totals.net_sales ?? 0) },
  ];
});

// Gaya kotak isian tanggal/angka — token kit (siku, border-2), seragam dengan FilterBar.
const inputClass = 'h-10 border-2 border-line bg-white px-3 text-[12.5px] outline-none focus:border-brand';

const isVoided = (row) => row.status === 'voided';
const rowClass = (row) => (isVoided(row) ? 'opacity-60' : '');
const plain = (n) => Number(Number(n).toFixed(2));

const summaryText = computed(() => {
  const s = summary.value;
  const parts = [
    t('reports.sales_summary_line', { shown: s.shown, total: s.total, units: s.units, amount: formatIDR(s.amount) }),
    t('reports.summary_cash', { amount: formatIDR(s.cash) }),
    t('reports.summary_noncash', { amount: formatIDR(s.noncash) }),
  ];
  if (s.outstanding > 0) parts.push(t('payment_ledger.summary_outstanding', { amount: formatIDR(s.outstanding) }));
  if (s.margin !== null) parts.push(t('reports.summary_margin', { amount: formatIDR(s.margin) }));
  if (s.voided > 0) parts.push(t('reports.summary_voided', { count: s.voided }));
  return parts.join(' · ');
});

// --- pilihan baris & ekspor ------------------------------------------------------------
const selected = ref(new Set());
const visibleKeys = computed(() => sorted.value.map((tx) => tx.key));
const chosenKeys = computed(() => visibleKeys.value.filter((k) => selected.value.has(k)));
const exportKeys = computed(() => (chosenKeys.value.length ? chosenKeys.value : visibleKeys.value));
const exportLabel = computed(() =>
  chosenKeys.value.length
    ? t('reports.export_selected', { count: chosenKeys.value.length })
    : t('reports.export_view', { count: visibleKeys.value.length })
);
const allVisibleSelected = computed(() => visibleKeys.value.length > 0 && chosenKeys.value.length === visibleKeys.value.length);

function toggleRow(key) {
  const next = new Set(selected.value);
  if (next.has(key)) next.delete(key);
  else next.add(key);
  selected.value = next;
}
function toggleAllVisible() {
  const next = new Set(selected.value);
  if (allVisibleSelected.value) visibleKeys.value.forEach((k) => next.delete(k));
  else visibleKeys.value.forEach((k) => next.add(k));
  selected.value = next;
}

const exporting = ref(false);
async function doExport() {
  exporting.value = true;
  try {
    // Hanya KUNCI baris yang dikirim; server membangun ulang isi berkas (nominal tak dipercaya dari klien).
    const blob = await exportSalesTransactions(
      { date_from: filters.dateFrom || undefined, date_to: filters.dateTo || undefined, include_voided: includeVoided.value ? 1 : undefined },
      exportKeys.value
    );
    saveBlob(blob, 'transaksi-penjualan.xlsx');
  } catch {
    toast.error(t('reports.export_report_failed'));
  } finally {
    exporting.value = false;
  }
}

// --- verifikasi massal (032-mark-payment-verified, US3) -------------------------------------
// Pilih transaksi → "Tandai terverifikasi (N)" → konfirmasi (FINAL, tak bisa dibatalkan) → ringkasan.
// Klien hanya mengirim ID penjualan yang dipilih; server memutuskan pembayaran mana yang boleh dan
// MELEWATI sisanya (milik pemanggil sendiri, sudah terverifikasi, batal, ditolak) dengan hitungan per
// alasan. Dipakai bersama filter status pembayaran "Perlu verifikasi" + "Pilih semua" untuk
// memverifikasi satu hari sekaligus.
const showBulkVerify = ref(false);
const bulkVerifying = ref(false);
const chosenOrderIds = computed(() =>
  chosenKeys.value.filter((k) => k.startsWith('order:')).map((k) => Number(k.slice('order:'.length))).filter(Number.isInteger)
);

function bulkVerifySummary(result) {
  const skipped = result.skipped ?? {};
  const reasons = [
    ['own_payment', 'payment_ledger.verify_skip_own'],
    ['already_verified', 'payment_ledger.verify_skip_already'],
    ['voided', 'payment_ledger.verify_skip_voided'],
    ['rejected', 'payment_ledger.verify_skip_rejected'],
  ].filter(([key]) => (skipped[key] ?? 0) > 0).map(([key, msg]) => t(msg, { count: skipped[key] }));
  const skippedText = result.skipped_total > 0
    ? t('payment_ledger.verify_bulk_skipped', { total: result.skipped_total, reasons: reasons.join(', ') })
    : '';
  const head = result.verified > 0
    ? t('payment_ledger.verify_bulk_result', { verified: result.verified, orders: result.verified_orders })
    : t('payment_ledger.verify_bulk_nothing');
  return { ok: result.verified > 0, text: [head, skippedText].filter(Boolean).join(' ') };
}

async function doBulkVerify() {
  if (bulkVerifying.value || chosenOrderIds.value.length === 0) return;
  bulkVerifying.value = true;
  try {
    const result = await verifyOrderPayments(chosenOrderIds.value);
    const summary = bulkVerifySummary(result);
    if (summary.ok) toast.success(summary.text);
    else toast.error(summary.text);
    await load();
    // Pilihan hanya dipertahankan untuk baris yang MASIH belum terverifikasi.
    const stillPending = new Set(transactions.value.filter((tx) => tx.payment_state === 'pending').map((tx) => tx.key));
    selected.value = new Set([...selected.value].filter((k) => stillPending.has(k)));
  } catch {
    // 403/422 sudah di-toast interceptor global.
  } finally {
    showBulkVerify.value = false;
    bulkVerifying.value = false;
  }
}

// --- aksi baris --------------------------------------------------------------------------
// Klik nomor transaksi membuka detail transaksi (dan dari sana struk / pembatalan).
const showItems = ref(false);
const itemsOrderId = ref(null);

function openDetail(row) {
  itemsOrderId.value = row.id;
  showItems.value = true;
}

const showReceipt = ref(false);
const receiptOrderId = ref(null);
function openReceipt(row) {
  receiptOrderId.value = row.id;
  showReceipt.value = true;
}

// Follow-up 2 (FR-023) — klik nama outlet adalah pintasan mengisi kotak pencarian.
function searchByOutlet(name) {
  search.value = name;
}

// Follow-up 2 (FR-022) — popover ringan pakai data yang SUDAH ada di baris transaksi.
const detailCustomer = ref(null);
function showCustomerDetail(row) {
  if (!row.customer_name) return;
  detailCustomer.value = { name: row.customer_name, phone: row.customer_phone, email: row.customer_email };
}

// --- detail order terbuka dari papan Meja (044, US3) --------------------------------------------
// Hanya-baca. Elemen pembuka (ubin) disimpan di sini: BaseDrawer memulihkan fokus ke `document.activeElement` saat
// dibuka, tetapi Safari tidak memberi fokus pada tombol yang diklik — jadi fokus dikembalikan ke ubin secara eksplisit.
const openOrderId = ref(null);
const openOrderTile = ref(null);
let openOrderOpener = null;

function openOrderDetail(orderId, opener, tile) {
  openOrderOpener = opener ?? null;
  openOrderTile.value = tile ?? null;
  openOrderId.value = orderId;
}

async function closeOrderDetail({ restoreFocus = true } = {}) {
  openOrderId.value = null;
  openOrderTile.value = null;
  const opener = openOrderOpener;
  openOrderOpener = null;
  if (!restoreFocus || !opener) return;
  await nextTick();
  if (opener.isConnected) opener.focus();
}

// Pindah ke daftar saat panel terbuka (mis. tombol back/URL) → panel dilepas; jangan terbuka lagi sendiri nanti.
watch(view, (v) => { if (v !== 'tables') closeOrderDetail({ restoreFocus: false }); });

// Order ternyata sudah dibayar: tutup panel lalu buka popup transaksi selesai yang sudah ada (order yang sama).
function viewFinishedTransaction(orderId) {
  closeOrderDetail({ restoreFocus: false });
  openDetail({ id: orderId });
}
</script>

<template>
  <div class="pb-10">
    <PageHeader :title="t('nav.sales')" :description="t('nav.sales_subtitle')">
      <template #actions>
        <ViewToggle v-if="canSeeTables" :model-value="view" :options="viewOptions" :label="t('sales.view_aria')" @update:model-value="(v) => setViewState({ view: v })" />
        <!-- Tindakan khusus daftar (verifikasi massal, ekspor) tak berlaku di tampilan Meja. -->
        <template v-if="view === 'list'">
          <BaseButton v-if="chosenOrderIds.length > 0" variant="secondary" data-testid="bulk-verify" @click="showBulkVerify = true">
            <i class="ph-duotone ph-seal-check text-[16px]" aria-hidden="true"></i>
            {{ t('payment_ledger.verify_bulk', { count: chosenOrderIds.length }) }}
          </BaseButton>
          <BaseButton variant="secondary" :loading="exporting" :disabled="exportKeys.length === 0" @click="doExport">
            <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
            {{ exportLabel }}
          </BaseButton>
        </template>
      </template>
    </PageHeader>

    <!-- Daftar order. v-if (bukan v-show): templatnya dilepas saat Meja aktif, tetapi seluruh KEADAAN (filter, urutan,
         pilihan, data termuat) hidup di setup() di atas, jadi kembali ke daftar tidak kehilangan apa pun dan tak memuat ulang. -->
    <KpiStrip v-if="view === 'list'" data-testid="kpi-cards" :items="kpiItems" />

    <section v-if="view === 'list'" class="mx-[26px] border-2 border-line bg-white">
      <!-- Filter lengkap: pencarian teks + tanggal (dengan pintasan) + kasir + shift + outlet + pembayaran +
           status bayar + jenis pelanggan + rentang total. Semua bekerja langsung (tanpa memuat ulang) dan
           tersimpan di URL. "N data" = baris yang benar-benar tampil setelah filter. -->
      <FilterBar
        v-model:search="search"
        :placeholder="t('reports.search_transaction_placeholder')"
        :search-label="t('reports.search_transaction')"
        :count="filtered.length"
        :active="activeCount > 0"
        @reset="reset"
      >
        <span v-if="activeCount > 0" class="text-[12px] font-semibold text-brand-active">{{ t('reports.filters_active', { count: activeCount }) }}</span>
        <div class="flex w-full flex-col gap-2.5">
          <div class="flex flex-wrap items-center gap-1.5">
            <button
              v-for="p in datePresets"
              :key="p.id"
              type="button"
              :aria-pressed="isPresetActive(p)"
              class="border-2 px-3 py-1 text-[12px] font-semibold transition-colors"
              :class="isPresetActive(p) ? 'border-brand bg-brand text-white' : 'border-line bg-white text-muted-4 hover:border-brand'"
              @click="applyPreset(p)"
            >{{ p.label }}</button>
          </div>

          <div class="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
            <input v-model="filters.dateFrom" type="date" :aria-label="t('reports.filter_date_from')" :class="inputClass" />
            <input v-model="filters.dateTo" type="date" :aria-label="t('reports.filter_date_to')" :class="inputClass" />

            <BaseMultiSelect v-model="filters.cashiers" :options="cashierOptions" :all-label="t('reports.filter_cashier_all')" />
            <BaseMultiSelect v-model="filters.sessions" :options="sessionOptions" :all-label="t('reports.filter_session_all')" />
            <BaseMultiSelect v-model="filters.outlets" :options="outletOptions" :all-label="t('reports.filter_seller_all')" />

            <BaseMultiSelect v-model="filters.payments" :options="paymentOptions" :all-label="t('reports.filter_payment_all')" />
            <BaseSelect v-model="filters.paymentState" :placeholder="t('reports.filter_paystate_all')" :options="paymentStateOptions" />
            <BaseSelect v-model="filters.customerType" :placeholder="t('reports.filter_customer_all')" :options="customerTypeOptions" />
            <BaseSelect v-model="filters.settlement" data-testid="filter-settlement" :placeholder="t('payment_ledger.filter_all')" :options="settlementOptions" />

            <input v-model="filters.minTotal" type="number" min="0" inputmode="numeric" :aria-label="t('reports.filter_min_total')" :placeholder="t('reports.filter_min_total')" :class="inputClass" />
            <input v-model="filters.maxTotal" type="number" min="0" inputmode="numeric" :aria-label="t('reports.filter_max_total')" :placeholder="t('reports.filter_max_total')" :class="inputClass" />
            <label class="flex h-10 items-center gap-2 text-[12.5px] font-semibold text-muted-4">
              <input v-model="includeVoided" type="checkbox" class="h-4 w-4 border-line accent-brand" />
              {{ t('reports.filter_show_voided') }}
            </label>
          </div>
        </div>
      </FilterBar>

      <div class="flex flex-wrap items-baseline justify-between gap-2 border-t-2 border-line px-5 py-3">
        <span class="text-[13px] font-bold tracking-tight">{{ t('reports.transaction_list', { count: filtered.length }) }}</span>
        <span class="text-[12px] text-muted-3">{{ summaryText }}</span>
      </div>

      <!-- Ringkasan kas satu shift — hanya saat TEPAT satu shift dipilih. -->
      <ShiftSummaryPanel v-if="shiftSummary" :summary="shiftSummary" />

      <label class="flex w-fit items-center gap-2 border-t-2 border-line px-5 py-2.5 text-[12px] font-semibold text-muted-4">
        <input type="checkbox" class="h-4 w-4 border-line accent-brand" :checked="allVisibleSelected" @change="toggleAllVisible" />
        {{ t('reports.select_all') }}
      </label>

      <div class="border-t-2 border-line">
        <DataTable
          :columns="columns"
          :rows="sorted"
          row-key="key"
          :loading="loading"
          :error="loadError"
          :sort-key="sortKey"
          :sort-dir="sortDir"
          :row-class="rowClass"
          :empty-message="hasFilterOtherThanSearch ? t('reports.no_matching_filters') : search ? t('reports.no_matching_transactions') : t('reports.no_transactions')"
          @sort="sortBy"
          @retry="load"
        >
          <template #cell-select="{ row }">
            <input
              type="checkbox"
              class="h-4 w-4 border-line accent-brand"
              :aria-label="t('reports.select_row', { number: row.order_number })"
              :checked="selected.has(row.key)"
              @change="toggleRow(row.key)"
            />
          </template>
          <template #cell-order_number="{ row }">
            <div class="flex flex-col items-start gap-1">
              <button type="button" class="font-mono text-[12.5px] font-bold text-brand-active underline decoration-dotted" :class="{ 'line-through': isVoided(row) }" @click="openDetail(row)">{{ row.order_number }}</button>
              <StatusPill v-if="isVoided(row)" variant="danger">{{ t('reports.status_voided') }}</StatusPill>
            </div>
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
          <template #cell-outlet_names="{ row }">
            <span class="flex flex-wrap gap-x-1 text-[12.5px] text-muted-4">
              <template v-if="(row.outlet_names ?? []).length">
                <span v-for="(name, i) in row.outlet_names" :key="name">
                  <button type="button" class="underline decoration-dotted hover:text-brand-active" @click="searchByOutlet(name)">{{ name }}</button><template v-if="i < row.outlet_names.length - 1">,</template>
                </span>
              </template>
              <span v-else>—</span>
            </span>
          </template>
          <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
          <template #cell-item_count="{ row }">
            <div class="flex max-w-[240px] flex-col gap-0.5">
              <span>{{ row.item_count }}</span>
              <template v-if="(row.items_preview ?? []).length">
                <span v-for="item in row.items_preview" :key="item.name" class="truncate text-[11.5px] text-muted-3">{{ item.name }} ×{{ plain(item.qty) }}</span>
                <span v-if="row.items_more > 0" class="text-[11.5px] font-semibold text-muted-3">{{ t('reports.items_more', { count: row.items_more }) }}</span>
              </template>
            </div>
          </template>
          <template #cell-payment_methods="{ row }">
            <div class="flex flex-col items-start gap-1">
              <span v-if="(row.payment_methods ?? []).length" class="flex flex-wrap gap-1">
                <span v-for="method in row.payment_methods" :key="method" class="whitespace-nowrap bg-line-7 px-2 py-0.5 text-[11px] font-semibold text-muted-4">{{ paymentLabel(method) }}</span>
              </span>
              <span v-else class="text-muted-3">—</span>
              <!-- 028 — penjualan yang belum lunas ditandai langsung di daftar, lengkap dengan sisa tagihannya. -->
              <StatusPill v-if="row.payment_status === 'partially_paid'" variant="warning" data-testid="row-partial">
                {{ t('payment_ledger.status_partially_paid') }} · {{ formatIDR(row.balance_amount) }}
              </StatusPill>
              <StatusPill v-else-if="row.payment_status === 'unpaid'" variant="danger" data-testid="row-unpaid">{{ t('payment_ledger.status_unpaid') }}</StatusPill>
              <StatusPill v-if="row.payment_state === 'pending'" variant="warning">{{ t('reports.payment_state_pending') }}</StatusPill>
              <StatusPill v-else-if="row.payment_state === 'rejected'" variant="danger">{{ t('reports.payment_state_rejected') }}</StatusPill>
            </div>
          </template>
          <template #cell-margin_amount="{ row }">
            <span v-if="row.margin_amount !== undefined" class="whitespace-nowrap">{{ formatIDR(row.margin_amount) }} <span class="text-[11.5px] text-muted-3">({{ row.margin_percent }}%)</span></span>
            <span v-else class="text-muted-3">—</span>
          </template>
          <template #cell-total_amount="{ row }">
            {{ formatIDR(row.total_amount) }}
          </template>
          <template #cell-actions="{ row }">
            <!-- Dua tautan ditumpuk rata kiri dan tak boleh terbelah baris: dulu berdampingan dengan
                 margin, sehingga di kolom sempit tiap tautan terbungkus dan keduanya tak sejajar. -->
            <div class="flex flex-col items-start gap-1.5">
              <button type="button" class="whitespace-nowrap text-[12.5px] font-semibold text-brand-active hover:underline" @click="openDetail(row)">{{ t('reports.view_items') }}</button>
              <button type="button" class="whitespace-nowrap text-[12.5px] font-semibold text-brand-active hover:underline" @click="openReceipt(row)">{{ t('reports.view_receipt') }}</button>
            </div>
          </template>
        </DataTable>
      </div>
    </section>
    <FloorBoard v-else @select-order="openOrderDetail" />

    <ConfirmDialog
      :open="showBulkVerify"
      :title="t('payment_ledger.verify_confirm_title')"
      :message="t('payment_ledger.verify_bulk_confirm_message', { count: chosenOrderIds.length })"
      :confirm-label="t('payment_ledger.verify_confirm_label')"
      :loading="bulkVerifying"
      @close="showBulkVerify = false"
      @confirm="doBulkVerify"
    />

    <OpenOrderDetail
      v-if="view === 'tables'"
      :order-id="openOrderId"
      :tile="openOrderTile"
      @close="closeOrderDetail()"
      @view-transaction="viewFinishedTransaction"
    />

    <TransactionItemsModal :open="showItems" :order-id="itemsOrderId" @close="showItems = false" @changed="load" />
    <ReceiptModal :open="showReceipt" :order-id="receiptOrderId" @close="showReceipt = false" />
    <CustomerQuickDetailModal :customer="detailCustomer" @close="detailCustomer = null" />
  </div>
</template>
