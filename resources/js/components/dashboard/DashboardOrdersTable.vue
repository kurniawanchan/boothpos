<script setup>
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { orders as fetchOrders, downloadOrdersCsv } from '../../api/dashboard';
import { useQueryState } from '../../composables/useQueryState';
import { useDebouncedFn } from '../../composables/useDebouncedFn';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import { formatIDR, formatCount } from '../../utils/formatters';
import { formatClock } from '../../utils/dashboardDates';
import { paymentMethodLabel } from '../../utils/paymentMethods';
import TabBar from '../page/TabBar.vue';
import FilterBar from '../page/FilterBar.vue';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import TablePagination from '../ui/TablePagination.vue';
import BaseMultiSelect from '../ui/BaseMultiSelect.vue';

// Detail order = modal yang sama dengan halaman Sales. Dimuat malas: berat (struk, pembayaran, detail produk) dan
// baru dibutuhkan saat satu baris dibuka, jadi tidak membebani render pertama Dashboard.
const TransactionItemsModal = defineAsyncComponent(() => import('../sales/TransactionItemsModal.vue'));

/**
 * 042-dashboard-revamp (US3) — "Order terbaru": tab dengan jumlah, cari + filter, tabel, dan detail order.
 *
 * Prinsip yang dijaga:
 *  - SATU sumber: GET /dashboard/orders. Jumlah tab (`counts`) dihitung server dengan semua filter KECUALI tab,
 *    jadi chip tiap tab = baris yang ditampilkan tab itu; klien tidak menghitung apa pun sendiri.
 *  - Tab 'Pre-bill' dan 'Ditutup' tidak ada datanya di produk ini; server menyebutnya di `hidden_tabs` dan klien
 *    hanya merender tab yang TIDAK tercantum di sana (tidak ada tab yang selamanya nol).
 *  - Tab/cari/filter/halaman hidup di address bar lewat useQueryState; nilai tak dikenal diabaikan (disaring di
 *    `eff`), muat ulang memulihkan tampilan. Rentang tanggal kosong = mengikuti periode Dashboard.
 *  - Refresh 30 detik (`refresh()`, dipanggil DashboardView) mengambil ulang halaman SAAT INI tanpa mengubah
 *    filter dan tanpa menutup detail yang sedang terbuka (detail adalah state terpisah dari daftar), dan tanpa
 *    kerangka "memuat": data lama tetap tampil sampai yang baru tiba, atau tetap bila refresh gagal.
 *  - Respons dari permintaan yang sudah usang (pengguna berpindah tab/filter cepat) tidak menimpa yang terbaru.
 *
 * @prop {'today'|'7d'|'30d'} period  periode Dashboard
 * @prop {boolean} running            true selama pewaktu auto-refresh berjalan -> penanda "Real-time"
 * @prop {string} scopeText           cakupan pada subjudul (Stage B: nama outlet)
 */
const props = defineProps({
  period: { type: String, default: 'today' },
  running: { type: Boolean, default: false },
  scopeText: { type: String, default: '' },
});
const { t } = useI18n();
const settings = useSettingsStore();
const toast = useToastStore();

const TABS = ['all', 'open', 'partial', 'paid', 'cancelled'];
const TYPES = ['retail', 'dine_in', 'takeaway'];
const METHODS = ['cash', 'bank_transfer', 'qr_ewallet'];
const PSTATS = ['unpaid', 'pending', 'partial', 'paid'];
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const PER_PAGE = 25;

const { state, set } = useQueryState({ tab: 'all', q: '', type: [], method: [], pstat: [], from: '', to: '', page: 1 });

// Nilai dari URL yang tak dikenal dibuang di sini, sehingga tak pernah terkirim ke server (422) dan tak pernah fatal.
const eff = computed(() => {
  const from = DATE_RE.test(state.from) ? state.from : '';
  let to = DATE_RE.test(state.to) ? state.to : '';
  if (from && to && to < from) to = ''; // server menolak to < from (422); rentang terbalik tidak berarti apa-apa
  return {
    tab: TABS.includes(state.tab) ? state.tab : 'all',
    q: String(state.q ?? '').trim().slice(0, 100),
    type: state.type.filter((v) => TYPES.includes(v)),
    method: state.method.filter((v) => METHODS.includes(v)),
    pstat: state.pstat.filter((v) => PSTATS.includes(v)),
    from,
    to,
    page: Number.isInteger(state.page) && state.page >= 1 ? state.page : 1,
  };
});

/** Parameter filter TANPA halaman — dipakai bersama oleh daftar dan ekspor CSV (baris yang sama). */
const filterParams = computed(() => ({
  period: props.period,
  tab: eff.value.tab,
  q: eff.value.q,
  type: eff.value.type,
  method: eff.value.method,
  pstat: eff.value.pstat,
  from: eff.value.from,
  to: eff.value.to,
}));
const listParams = computed(() => ({ ...filterParams.value, page: eff.value.page, per_page: PER_PAGE }));
const requestKey = computed(() => JSON.stringify(listParams.value));

// --- data ----------------------------------------------------------------------------------------
const rows = ref([]);
const meta = ref({ current_page: 1, per_page: PER_PAGE, total: 0, last_page: 1 });
const counts = ref(null);
const hiddenTabs = ref([]);
const loading = ref(true);
const loadError = ref(false);
let seq = 0;

async function load({ silent = false } = {}) {
  const mine = ++seq;
  if (!silent) {
    loading.value = true;
    loadError.value = false;
  }
  try {
    const res = await fetchOrders(listParams.value);
    if (mine !== seq) return;
    rows.value = res.data ?? [];
    if (res.meta) meta.value = res.meta;
    counts.value = res.counts ?? null;
    hiddenTabs.value = res.hidden_tabs ?? [];
    loadError.value = false;
  } catch {
    if (mine !== seq) return;
    // Refresh senyap yang gagal tidak menghapus baris yang sudah tampil; galat penuh hanya untuk muat biasa.
    if (!silent) {
      rows.value = [];
      loadError.value = true;
    }
  } finally {
    if (mine === seq && !silent) loading.value = false;
  }
}

onMounted(() => load());
watch(requestKey, () => load());
// Pindah periode (tanpa rentang tanggal sendiri) kembali ke halaman 1; perubahan kuncinya memicu SATU muat ulang.
watch(() => props.period, () => { if (state.page !== 1) set({ page: 1 }); });

defineExpose({ refresh: () => load({ silent: true }) });

// --- tab -----------------------------------------------------------------------------------------
const tabs = computed(() => TABS
  .filter((key) => !hiddenTabs.value.includes(key))
  .map((key) => ({
    key,
    label: t(`dashboard.orders_tab_${key}`),
    count: counts.value ? counts.value[key] : undefined,
  })));

function onTab(key) {
  set({ tab: key, page: 1 });
}

// --- cari & filter -------------------------------------------------------------------------------
const searchText = ref(state.q);
const pushSearch = useDebouncedFn((value) => set({ q: value.trim(), page: 1 }), 300);
function onSearch(value) {
  searchText.value = value;
  pushSearch(value);
}
// Tombol back/forward atau tautan yang dibagikan mengubah q dari luar.
watch(() => state.q, (q) => { if (q !== searchText.value.trim()) searchText.value = q; });

const typeOptions = computed(() => TYPES.map((value) => ({ value, label: t(`dashboard.orders_type_${value}`) })));
const methodOptions = computed(() => METHODS.map((value) => ({ value, label: paymentMethodLabel(t, value) })));
const pstatOptions = computed(() => PSTATS.map((value) => ({ value, label: t(`dashboard.orders_pstat_${value}`) })));

const filtersActive = computed(() => !!(eff.value.q || eff.value.type.length || eff.value.method.length
  || eff.value.pstat.length || eff.value.from || eff.value.to));

function resetFilters() {
  searchText.value = '';
  set({ q: '', type: [], method: [], pstat: [], from: '', to: '', page: 1 });
}

// Chip yang bisa dilepas satu per satu: kunci = bagian state yang dikosongkan.
const chips = computed(() => {
  const e = eff.value;
  const list = [];
  if (e.q) list.push({ id: 'q', label: t('dashboard.orders_chip_search', { q: e.q }), clear: () => { searchText.value = ''; set({ q: '', page: 1 }); } });
  e.type.forEach((v) => list.push({ id: `type:${v}`, label: t(`dashboard.orders_type_${v}`), clear: () => set({ type: e.type.filter((x) => x !== v), page: 1 }) }));
  e.method.forEach((v) => list.push({ id: `method:${v}`, label: paymentMethodLabel(t, v), clear: () => set({ method: e.method.filter((x) => x !== v), page: 1 }) }));
  e.pstat.forEach((v) => list.push({ id: `pstat:${v}`, label: t(`dashboard.orders_pstat_${v}`), clear: () => set({ pstat: e.pstat.filter((x) => x !== v), page: 1 }) }));
  if (e.from) list.push({ id: 'from', label: t('dashboard.orders_chip_from', { date: e.from }), clear: () => set({ from: '', page: 1 }) });
  if (e.to) list.push({ id: 'to', label: t('dashboard.orders_chip_to', { date: e.to }), clear: () => set({ to: '', page: 1 }) });
  return list;
});

const inputClass = 'h-[46px] border border-line bg-white px-3 text-[12.5px] outline-none focus:border-brand';

// --- tabel ---------------------------------------------------------------------------------------
const columns = computed(() => [
  { key: 'order_number', label: t('dashboard.orders_col_number') },
  { key: 'at', label: t('dashboard.orders_col_time') },
  { key: 'type', label: t('dashboard.orders_col_type') },
  { key: 'items_summary', label: t('dashboard.orders_col_items') },
  { key: 'tab', label: t('dashboard.orders_col_status') },
  { key: 'payment_state', label: t('dashboard.orders_col_payment') },
  { key: 'total_amount', label: t('dashboard.orders_col_total') },
]);

const TAB_VARIANT = { open: 'info', partial: 'warning', paid: 'success', cancelled: 'danger' };
const PSTAT_VARIANT = { unpaid: 'neutral', pending: 'warning', partial: 'info', paid: 'success' };

const typeText = (row) => {
  const label = TYPES.includes(row.type) ? t(`dashboard.orders_type_${row.type}`) : (row.type ?? '—');
  return row.table ? `${label} · ${row.table}` : label;
};
// Nilai di luar daftar yang dikenal (status baru di server) tampil apa adanya, bukan kunci terjemahan mentah.
const tabText = (key) => (TABS.includes(key) ? t(`dashboard.orders_tab_${key}`) : String(key ?? '—'));
const pstatText = (key) => (PSTATS.includes(key) ? t(`dashboard.orders_pstat_${key}`) : String(key ?? '—'));
const methodsText = (row) => (row.methods ?? []).map((m) => paymentMethodLabel(t, m)).join(', ');
const timeText = (row) => formatClock(row.at, settings.storeTimezone) || '—';

// --- detail --------------------------------------------------------------------------------------
// State terpisah dari daftar: refresh otomatis mengganti `rows` tanpa menutup atau memuat ulang detail ini.
const detailOpen = ref(false);
const detailOrderId = ref(null);
function openDetail(row) {
  detailOrderId.value = row.id;
  detailOpen.value = true;
}
/**
 * DataTable tidak menyediakan klik baris; satu pendengar di akar tabel memetakan <tr> yang diklik ke datanya lewat
 * urutan barisnya. Nomor order adalah tombol sungguhan (bisa difokus, Enter/Spasi) untuk papan ketik.
 */
function onTableClick(e) {
  if (e.target.closest('button, a')) return;
  const tr = e.target.closest('tbody tr');
  if (!tr?.parentElement) return;
  const row = rows.value[Array.prototype.indexOf.call(tr.parentElement.children, tr)];
  if (row) openDetail(row);
}

// --- ekspor --------------------------------------------------------------------------------------
const exporting = ref(false);
async function onExport() {
  exporting.value = true;
  try {
    await downloadOrdersCsv(filterParams.value);
  } catch {
    toast.error(t('dashboard.orders_export_failed'));
  } finally {
    exporting.value = false;
  }
}

// --- subjudul ------------------------------------------------------------------------------------
const periodText = computed(() => {
  if (eff.value.from || eff.value.to) {
    return t('dashboard.orders_period_range', { from: eff.value.from || '…', to: eff.value.to || '…' });
  }
  return t(`dashboard.orders_period_${props.period}`);
});
const subtitle = computed(() => t('dashboard.orders_subtitle', {
  count: formatCount(meta.value.total ?? 0),
  period: periodText.value,
  scope: props.scopeText || t('dashboard.attention_scope_all'),
}));
</script>

<template>
  <section class="mx-[26px] mb-5 border-2 border-line bg-white" :aria-label="t('dashboard.orders_title')" data-testid="dashboard-orders">
    <header class="flex flex-wrap items-start justify-between gap-3 border-b-2 border-line px-5 py-3.5">
      <div class="flex min-w-0 flex-col gap-0.5">
        <h2 class="flex flex-wrap items-center gap-2.5 text-[14px] font-bold tracking-tight">
          {{ t('dashboard.orders_title') }}
          <span v-if="running" class="inline-flex items-center gap-1.5 text-[11.5px] font-semibold text-success-text" data-testid="realtime-marker">
            <span class="h-2 w-2 animate-pulse bg-marker-success" aria-hidden="true"></span>{{ t('page.realtime') }}
          </span>
        </h2>
        <p v-if="!loadError" class="text-[12.5px] text-muted" data-testid="orders-subtitle">{{ subtitle }}</p>
      </div>
      <button
        type="button"
        data-testid="export-orders"
        class="inline-flex h-9 items-center gap-2 border-2 border-line bg-white px-3 text-[12.5px] font-bold text-muted-5 transition-colors hover:border-brand hover:text-brand-active focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-mint-100 disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="exporting"
        @click="onExport"
      >
        <i class="ph-duotone text-[16px]" :class="exporting ? 'ph-circle-notch animate-spin' : 'ph-download-simple'" aria-hidden="true"></i>
        {{ t('dashboard.orders_export_csv') }}
      </button>
    </header>

    <TabBar :tabs="tabs" :model-value="eff.tab" :label="t('dashboard.orders_tabs_label')" @update:model-value="onTab" />

    <FilterBar
      :search="searchText"
      :placeholder="t('dashboard.orders_search_placeholder')"
      :search-label="t('dashboard.orders_search_label')"
      :count="loading || loadError ? null : meta.total"
      :active="filtersActive"
      @update:search="onSearch"
      @reset="resetFilters"
    >
      <BaseMultiSelect class="w-48" :model-value="eff.type" :options="typeOptions" :all-label="t('dashboard.orders_filter_type_all')" @update:model-value="(v) => set({ type: v, page: 1 })" />
      <BaseMultiSelect class="w-48" :model-value="eff.method" :options="methodOptions" :all-label="t('dashboard.orders_filter_method_all')" @update:model-value="(v) => set({ method: v, page: 1 })" />
      <BaseMultiSelect class="w-52" :model-value="eff.pstat" :options="pstatOptions" :all-label="t('dashboard.orders_filter_pstat_all')" @update:model-value="(v) => set({ pstat: v, page: 1 })" />
      <input type="date" :value="eff.from" :aria-label="t('dashboard.orders_filter_date_from')" :class="inputClass" data-testid="orders-from" @change="set({ from: $event.target.value, page: 1 })" />
      <input type="date" :value="eff.to" :aria-label="t('dashboard.orders_filter_date_to')" :class="inputClass" data-testid="orders-to" @change="set({ to: $event.target.value, page: 1 })" />
    </FilterBar>

    <ul v-if="chips.length" class="flex flex-wrap gap-2 px-5 pb-3" data-testid="orders-chips">
      <li v-for="chip in chips" :key="chip.id">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 border-2 border-line bg-white px-2 py-0.5 text-[12px] font-semibold text-muted-5 transition-colors hover:border-brand focus-visible:outline-none focus-visible:border-brand"
          :aria-label="t('dashboard.orders_chip_remove', { label: chip.label })"
          @click="chip.clear"
        >
          {{ chip.label }}
          <i class="ph-duotone ph-x text-[12px]" aria-hidden="true"></i>
        </button>
      </li>
    </ul>

    <div class="border-t-2 border-line" @click="onTableClick">
      <DataTable
        :columns="columns"
        :rows="rows"
        :loading="loading"
        :error="loadError ? t('dashboard.orders_load_failed') : ''"
        :empty-message="t('dashboard.orders_empty')"
        :row-class="() => 'cursor-pointer'"
        @retry="load()"
      >
        <template #cell-order_number="{ row }">
          <button
            type="button"
            class="font-mono text-[12.5px] font-bold text-brand-active underline decoration-dotted hover:text-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-mint-100"
            :aria-label="t('dashboard.orders_open_detail', { number: row.order_number })"
            @click="openDetail(row)"
          >{{ row.order_number }}</button>
        </template>
        <template #cell-at="{ row }"><span class="whitespace-nowrap tabular-nums text-muted-4">{{ timeText(row) }}</span></template>
        <template #cell-type="{ row }">{{ typeText(row) }}</template>
        <template #cell-items_summary="{ row }">
          <span class="block max-w-[240px] truncate text-muted-4" :title="row.items_summary">{{ row.items_summary || '—' }}</span>
        </template>
        <template #cell-tab="{ row }">
          <StatusPill :variant="TAB_VARIANT[row.tab] ?? 'neutral'">{{ tabText(row.tab) }}</StatusPill>
        </template>
        <template #cell-payment_state="{ row }">
          <div class="flex flex-col items-start gap-1">
            <StatusPill :variant="PSTAT_VARIANT[row.payment_state] ?? 'neutral'">{{ pstatText(row.payment_state) }}</StatusPill>
            <span v-if="methodsText(row)" class="text-[11.5px] text-muted">{{ methodsText(row) }}</span>
          </div>
        </template>
        <template #cell-total_amount="{ row }"><span class="font-bold tabular-nums">{{ formatIDR(row.total_amount) }}</span></template>
      </DataTable>
      <TablePagination v-if="!loadError && !loading && meta.total > 0" :meta="meta" @change="(page) => set({ page })" />
    </div>

    <TransactionItemsModal v-if="detailOrderId !== null" :open="detailOpen" :order-id="detailOrderId" @close="detailOpen = false" @changed="load({ silent: true })" />
  </section>
</template>
