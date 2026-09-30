<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { listEvents } from '../api/events';
import { salesReport, exportSalesTransactions } from '../api/reports';
import { useToastStore } from '../stores/toast';
import { useSalesFilters } from '../composables/useSalesFilters';
import { formatIDR } from '../utils/money';
import { formatDateTime, toLocalDateKey } from '../utils/date';
import { paymentMethodLabel } from '../utils/paymentMethods';
import { saveBlob } from '../utils/saveBlob';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseMultiSelect from '../components/ui/BaseMultiSelect.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import DataTable from '../components/ui/DataTable.vue';
import TransactionItemsModal from '../components/sales/TransactionItemsModal.vue';
import ReceiptModal from '../components/receipt/ReceiptModal.vue';

// Dikeluarkan dari ReportsView.vue menjadi menu tersendiri — laporan
// penjualan terbuka untuk semua peran (termasuk kasir), berbeda dari
// Rekap Artist/Modal & Untung/Modal Artist di halaman Laporan yang
// sengaja dibatasi owner/admin saja (PRD 7.13). Menggabungkannya dalam
// satu halaman membuat kasir melihat tab kosong tak berguna sebelum ini.
//
// Logika daftar (filter, urutan, ringkasan, shift, query URL) ada di
// composables/useSalesFilters.js; berkas ini hanya tampilan & interaksi.
const toast = useToastStore();
const { t } = useI18n();
const route = useRoute();
const router = useRouter(); // undefined bila tak ada router (mis. test lama) — sinkron URL dilewati

const events = ref([]);
const eventId = ref('');
const sales = ref(null);
const loading = ref(false);

const transactions = computed(() => sales.value?.transactions ?? []);
const sessions = computed(() => sales.value?.sessions ?? []);
const {
  search, filters, includeVoided, sortKey, sortDir,
  cashierOptions, sellerOptions, paymentMethods,
  filtered, sorted, summary, shiftSummary, hasMargin,
  activeCount, hasFilterOtherThanSearch,
  reset, sortBy, toQuery, applyQuery,
} = useSalesFilters({ transactions, sessions });

// --- muat data --------------------------------------------------------------
let restored = false; // false selama query URL dipulihkan → jangan muat ganda

onMounted(async () => {
  events.value = (await listEvents({ per_page: 100 })).data;
  const active = events.value.find((e) => e.status === 'active');
  eventId.value = active?.id ?? events.value[0]?.id ?? '';
  applyQuery(route?.query ?? {});
  restored = true;
  await load();
});

watch(eventId, () => { if (restored) load(); });
// 'sync': dijalankan seketika saat applyQuery() mengubah nilainya (restored masih false).
watch(includeVoided, () => { if (restored) load(); }, { flush: 'sync' });

async function load() {
  loading.value = true;
  try {
    // group_by tidak dikirim (009 US2): hanya daftar transaksi yang dipakai. Transaksi
    // batal diminta ke SERVER — tidak ikut angka ringkasan, dan sengaja tidak dimuat
    // kecuali diminta.
    sales.value = await salesReport({
      event_id: eventId.value || undefined,
      include_voided: includeVoided.value ? 1 : undefined,
    });
  } finally {
    loading.value = false;
  }
}

// --- filter tersimpan di URL (bisa di-bookmark / dibagikan) -----------------------
const OUR_QUERY_KEYS = ['q', 'from', 'to', 'cashier', 'seller', 'pay', 'session', 'cust', 'pstate', 'min', 'max', 'voided', 'sort', 'dir'];
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
const sessionOptions = computed(() =>
  sessions.value.map((s) => ({
    value: s.id,
    label: `${s.cashier_name ?? '—'} · ${formatDateTime(s.opened_at)}${s.status === 'open' ? ` · ${t('reports.shift_active')}` : ''}`,
  }))
);

// --- preset tanggal: Hari ini, Kemarin, dan setiap hari event -------------------------
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const datePresets = computed(() => {
  const yesterday = new Date();
  yesterday.setDate(yesterday.getDate() - 1);
  const list = [
    { id: 'today', label: t('reports.preset_today'), date: toLocalDateKey(new Date()) },
    { id: 'yesterday', label: t('reports.preset_yesterday'), date: toLocalDateKey(yesterday) },
  ];
  // start_date/end_date bisa berupa ISO ("2026-09-01T00:00:00Z"); hanya bagian tanggalnya.
  const start = String(sales.value?.event?.start_date ?? '').slice(0, 10);
  const end = String(sales.value?.event?.end_date ?? '').slice(0, 10);
  if (DATE_RE.test(start) && DATE_RE.test(end)) {
    for (let i = 0; i < 14; i += 1) {
      const d = new Date(`${start}T00:00:00Z`);
      d.setUTCDate(d.getUTCDate() + i);
      const date = d.toISOString().slice(0, 10);
      if (date > end) break;
      list.push({ id: `day-${i + 1}`, label: t('reports.preset_day', { n: i + 1 }), date });
    }
  }
  return list;
});
const isPresetActive = (p) => filters.dateFrom === p.date && filters.dateTo === p.date;
function applyPreset(p) {
  const clear = isPresetActive(p);
  filters.dateFrom = clear ? '' : p.date;
  filters.dateTo = clear ? '' : p.date;
}

// --- tabel -------------------------------------------------------------------------
const columns = computed(() => [
  { key: 'select', label: '' },
  { key: 'order_number', label: t('reports.col_transaction_no'), sortable: true },
  { key: 'customer_name', label: t('reports.col_customer'), sortable: true },
  { key: 'artist_names', label: t('reports.col_artist'), sortable: true },
  { key: 'created_at', label: t('reports.col_time'), sortable: true },
  { key: 'cashier_name', label: t('reports.col_cashier'), sortable: true },
  { key: 'item_count', label: t('reports.col_item'), sortable: true },
  { key: 'payment_methods', label: t('reports.col_payment'), sortable: true },
  // Kolom margin HANYA ada bila server mengirimnya (owner/admin) — bukan disembunyikan di sini.
  ...(hasMargin.value ? [{ key: 'margin_amount', label: t('reports.col_margin'), sortable: true }] : []),
  { key: 'total_amount', label: t('reports.col_total'), sortable: true },
  { key: 'actions', label: '' },
]);

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
      { event_id: eventId.value || undefined, include_voided: includeVoided.value ? 1 : undefined },
      exportKeys.value
    );
    saveBlob(blob, 'transaksi-penjualan.xlsx');
  } catch {
    toast.error(t('reports.export_report_failed'));
  } finally {
    exporting.value = false;
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

// Follow-up 2 (FR-023) — klik nama artist adalah pintasan mengisi kotak pencarian.
function searchByArtist(name) {
  search.value = name;
}

// Follow-up 2 (FR-022) — popover ringan pakai data yang SUDAH ada di baris transaksi.
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
      <BaseButton variant="secondary" :loading="exporting" :disabled="exportKeys.length === 0" @click="doExport">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        {{ exportLabel }}
      </BaseButton>
    </div>

    <!-- Kartu ringkasan = transaksi dari POS saja, sama dengan daftar di bawahnya. Angka laporan
         bersama (unit_count/gross_sales/net_sales) memuat pendapatan pre-order dan dipakai
         Dashboard/Laporan; halaman ini memakai pos_* (dengan cadangan ke angka bersama untuk
         respons lama yang belum punya pos_*). -->
    <div data-testid="kpi-cards" class="grid grid-cols-2 gap-3.5 sm:grid-cols-4">
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.transactions') }}</span><span class="text-[23px] font-extrabold tracking-tight">{{ sales?.totals?.order_count ?? 0 }}</span></div>
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.units_sold') }}</span><span class="text-[23px] font-extrabold tracking-tight">{{ sales?.totals?.pos_unit_count ?? sales?.totals?.unit_count ?? 0 }}</span></div>
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.gross_sales') }}</span><span class="text-[23px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.pos_gross_sales ?? sales?.totals?.gross_sales ?? 0) }}</span></div>
      <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.net_sales') }}</span><span class="text-[23px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.pos_net_sales ?? sales?.totals?.net_sales ?? 0) }}</span></div>
    </div>

    <div class="flex flex-col gap-2">
      <!-- Filter lengkap: pencarian teks + tanggal (dengan pintasan) + tipe + kasir + shift +
           penjual + pembayaran + status bayar + jenis pelanggan + rentang total. Semua bekerja
           langsung (tanpa memuat ulang) dan tersimpan di URL. -->
      <div class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-3.5">
        <div class="flex flex-wrap items-center gap-1.5">
          <button
            v-for="p in datePresets"
            :key="p.id"
            type="button"
            :aria-pressed="isPresetActive(p)"
            class="rounded-full border px-3 py-1 text-[12px] font-semibold transition-colors"
            :class="isPresetActive(p) ? 'border-transparent bg-brand text-white' : 'border-line-2 bg-white text-muted-4 hover:border-brand'"
            @click="applyPreset(p)"
          >{{ p.label }}</button>
        </div>

        <div class="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
          <div class="relative col-span-2">
            <i class="ph-duotone ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[14px] text-muted-3" aria-hidden="true"></i>
            <input
              v-model="search"
              type="search"
              :placeholder="t('reports.search_transaction_placeholder')"
              class="h-10 w-full rounded-lg border border-line-2 bg-white py-2 pl-8 pr-3 text-[12.5px] outline-none focus:border-brand-active"
              :aria-label="t('reports.search_transaction')"
            />
          </div>
          <input v-model="filters.dateFrom" type="date" :aria-label="t('reports.filter_date_from')" class="h-10 rounded-lg border border-line-2 bg-white px-3 text-[12.5px] outline-none focus:border-brand-active" />
          <input v-model="filters.dateTo" type="date" :aria-label="t('reports.filter_date_to')" class="h-10 rounded-lg border border-line-2 bg-white px-3 text-[12.5px] outline-none focus:border-brand-active" />

          <BaseMultiSelect v-model="filters.cashiers" :options="cashierOptions" :all-label="t('reports.filter_cashier_all')" />
          <BaseMultiSelect v-model="filters.sessions" :options="sessionOptions" :all-label="t('reports.filter_session_all')" />
          <BaseMultiSelect v-model="filters.sellers" :options="sellerOptions" :all-label="t('reports.filter_seller_all')" />

          <BaseMultiSelect v-model="filters.payments" :options="paymentOptions" :all-label="t('reports.filter_payment_all')" />
          <BaseSelect v-model="filters.paymentState" :placeholder="t('reports.filter_paystate_all')" :options="paymentStateOptions" />
          <BaseSelect v-model="filters.customerType" :placeholder="t('reports.filter_customer_all')" :options="customerTypeOptions" />
          <span></span>

          <input v-model="filters.minTotal" type="number" min="0" inputmode="numeric" :aria-label="t('reports.filter_min_total')" :placeholder="t('reports.filter_min_total')" class="h-10 rounded-lg border border-line-2 bg-white px-3 text-[12.5px] outline-none focus:border-brand-active" />
          <input v-model="filters.maxTotal" type="number" min="0" inputmode="numeric" :aria-label="t('reports.filter_max_total')" :placeholder="t('reports.filter_max_total')" class="h-10 rounded-lg border border-line-2 bg-white px-3 text-[12.5px] outline-none focus:border-brand-active" />
          <label class="flex h-10 items-center gap-2 text-[12.5px] font-semibold text-muted-4">
            <input v-model="includeVoided" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
            {{ t('reports.filter_show_voided') }}
          </label>
          <div class="flex items-center justify-end gap-3">
            <span v-if="activeCount > 0" class="text-[12px] font-semibold text-brand-active">{{ t('reports.filters_active', { count: activeCount }) }}</span>
            <BaseButton v-if="activeCount > 0" variant="secondary" size="sm" @click="reset">{{ t('reports.filters_reset') }}</BaseButton>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <span class="text-[13px] font-bold tracking-tight">{{ t('reports.transaction_list', { count: filtered.length }) }}</span>
        <span class="text-[12px] text-muted-3">{{ summaryText }}</span>
      </div>

      <!-- Ringkasan kas satu shift — hanya saat TEPAT satu shift dipilih. -->
      <div v-if="shiftSummary" data-testid="shift-summary" class="flex flex-wrap items-baseline gap-x-6 gap-y-1 rounded-card border border-line-2 bg-white px-4 py-3 text-[12.5px]">
        <span class="font-bold">{{ t('reports.shift_summary_title') }}</span>
        <span><span class="text-muted-3">{{ t('reports.shift_opening_cash') }}</span> <strong>{{ formatIDR(shiftSummary.opening) }}</strong></span>
        <span><span class="text-muted-3">{{ t('reports.shift_cash_sales') }}</span> <strong>{{ formatIDR(shiftSummary.cashSales) }}</strong></span>
        <span><span class="text-muted-3">{{ t('reports.shift_expected_cash') }}</span> <strong>{{ formatIDR(shiftSummary.expected) }}</strong></span>
        <template v-if="shiftSummary.counted !== null">
          <span><span class="text-muted-3">{{ t('reports.shift_counted_cash') }}</span> <strong>{{ formatIDR(shiftSummary.counted) }}</strong></span>
          <span><span class="text-muted-3">{{ t('reports.shift_difference') }}</span> <strong :class="shiftSummary.difference === 0 ? '' : 'text-danger-text'">{{ formatIDR(shiftSummary.difference) }}</strong></span>
        </template>
      </div>

      <label class="flex w-fit items-center gap-2 text-[12px] font-semibold text-muted-4">
        <input type="checkbox" class="h-4 w-4 rounded border-line accent-brand" :checked="allVisibleSelected" @change="toggleAllVisible" />
        {{ t('reports.select_all') }}
      </label>

      <div class="overflow-hidden rounded-card border border-line-2 bg-white">
        <DataTable
          :columns="columns"
          :rows="sorted"
          row-key="key"
          :loading="loading"
          :sort-key="sortKey"
          :sort-dir="sortDir"
          :row-class="rowClass"
          :empty-message="hasFilterOtherThanSearch ? t('reports.no_matching_filters') : search ? t('reports.no_matching_transactions') : t('reports.no_transactions')"
          @sort="sortBy"
        >
          <template #cell-select="{ row }">
            <input
              type="checkbox"
              class="h-4 w-4 rounded border-line accent-brand"
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
                <span v-for="method in row.payment_methods" :key="method" class="whitespace-nowrap rounded-full bg-line-7 px-2 py-0.5 text-[11px] font-semibold text-muted-4">{{ paymentLabel(method) }}</span>
              </span>
              <span v-else class="text-muted-3">—</span>
              <StatusPill v-if="row.payment_state === 'pending'" variant="warn">{{ t('reports.payment_state_pending') }}</StatusPill>
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
            <button type="button" class="text-[12.5px] font-semibold text-brand-active" @click="openDetail(row)">{{ t('reports.view_items') }}</button>
            <button type="button" class="ml-3 text-[12.5px] font-semibold text-brand-active" @click="openReceipt(row)">{{ t('reports.view_receipt') }}</button>
          </template>
        </DataTable>
      </div>
    </div>

    <TransactionItemsModal :open="showItems" :order-id="itemsOrderId" @close="showItems = false" @changed="load" />
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
