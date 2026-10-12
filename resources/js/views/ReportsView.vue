<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { listEvents } from '../api/events';
import { listOutlets } from '../api/outlets';
import {
  outletSettlements,
  profitReport,
  outletProfitReport,
  purchasesReport,
  stockByOutletReport,
  preorderReport,
  exportReport,
} from '../api/reports';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import PageHeader from '../components/page/PageHeader.vue';
import TabBar from '../components/page/TabBar.vue';
import FilterBar from '../components/page/FilterBar.vue';
import SettlementTab from './reports/SettlementTab.vue';
import ProfitTab from './reports/ProfitTab.vue';
import OutletProfitTab from './reports/OutletProfitTab.vue';
import PurchasesTab from './reports/PurchasesTab.vue';
import StockByOutletTab from './reports/StockByOutletTab.vue';
import PreorderTab from './reports/PreorderTab.vue';

// 041 (US2) — halaman ini dipecah: induk hanya memegang pemuatan data, filter, dan ekspor; tiap tab laporan
// ada di views/reports/*. Perilaku, endpoint, dan semantik filter tidak berubah.
//
// Tab "Penjualan" dikeluarkan menjadi halaman/menu tersendiri (SalesView.vue)
// — laporan penjualan terbuka untuk semua peran, sedangkan ketiga tab yang
// tersisa di sini (Rekap Outlet, Modal & Untung, Modal Outlet) sengaja
// dibatasi owner/admin saja (PRD 7.13: kasir tidak boleh mengakses laporan
// modal/keuntungan). Karena SEMUA tab di sini kini owner/admin-only, rute
// halaman ini sendiri digerbang lewat meta.roles di router — bukan cuma
// tab-nya — supaya kasir/inventory tidak pernah sampai ke halaman kosong.
const auth = useAuthStore();
const { t } = useI18n();
const toast = useToastStore();

const events = ref([]);
const eventId = ref('');
const activeTab = ref('settlement');

const settlements = ref(null);
const profit = ref(null);
const outletProfit = ref(null);
const purchases = ref(null);
const stockByOutlet = ref(null);
const preorderStats = ref(null);
// 012-seller-preorder-report-detail-export (US2) — toggle antara ringkasan
// status × payment_completeness (default, sudah ada) dan breakdown per
// outlet (`breakdown=outlet`, lihat research.md R2). Breakdown dimuat
// terpisah, hanya saat toggle diaktifkan — bukan sekaligus dengan ringkasan
// — mengikuti pola "jangan eager-fetch apa yang belum tentu dilihat" yang
// sudah dipakai StockByOutletDetailModal di tab lain.
const preorderView = ref('summary');
const preorderByOutlet = ref(null);
// 039 — subtotal per outlet dari server (PreorderSellerSubtotals); layar hanya menempatkannya, tidak menghitung.
const preorderBySubtotals = ref([]);
const outlets = ref([]);
const purchasesStatusFilter = ref('');
const loading = ref(false);
// 041 — gagal memuat tab aktif: tampilkan keadaan galat dengan tombol coba lagi, bukan area kosong.
const loadError = ref(false);

// Tambahan: filter "semua outlet" vs satu outlet, dipakai di semua tab yang
// punya dimensi per-outlet (settlement, outlet-profit, stock-by-outlet, dan
// preorder view "per outlet"). Difilter di sisi klien karena semua tab ini
// SUDAH memuat baris untuk seluruh outlet sekaligus (row-per-outlet) — tidak
// ada endpoint baru yang dibutuhkan, filter ini hanya mempersempit tampilan.
const outletFilter = ref('');

// Digerbang di sini JUGA (bukan cuma meta.roles di router) — pola
// pertahanan berlapis yang sudah dipakai di seluruh aplikasi ini (mis.
// license gate, nav item lain yang punya `roles`). Router mencegah
// navigasi ke sini untuk peran lain, tapi komponennya sendiri tetap
// gagal aman kalau suatu saat ter-render lewat jalur lain.
const tabs = computed(() =>
  auth.canAccessMenu('reports')
    ? [
        { key: 'settlement', label: t('reports.tab_settlement') },
        { key: 'profit', label: t('reports.tab_profit') },
        // F9.5 — laporan modal & laba kotor PER OUTLET, tab terpisah dari
        // "Modal & Untung" (yang berskala event) karena angkanya sengaja
        // TIDAK dikurangi biaya event dan akan salah dibaca kalau ditumpuk
        // di tab yang sama tanpa pemisahan visual yang jelas.
        { key: 'outlet-profit', label: t('reports.tab_artist_profit') },
        // 006-purchase-order-and-ops (US9/US10) — dua tab baru ini SENGAJA
        // tidak diskop event (lihat komentar di api/reports.js): pembelian
        // dan stok gudang bukan konsep per-event seperti tiga tab di atas.
        { key: 'purchases', label: t('reports.tab_purchases') },
        { key: 'stock-by-outlet', label: t('reports.tab_stock_by_artist') },
        // 010-split-payment-preorder-reports (US6) — laporan pre-order baru,
        // event_id-nya OPSIONAL di backend (bukan wajib seperti tiga tab
        // event-scoped di atas), jadi tab ini sengaja TIDAK dimasukkan ke
        // EVENT_SCOPED_TABS (yang menahan pemuatan sampai eventId terisi) —
        // filter event di sini hanya mempersempit, bukan prasyarat.
        { key: 'preorder', label: t('reports.tab_preorder') },
      ]
    : []
);
// Chip jumlah pada tab sengaja TIDAK ada: tiap tab dimuat hanya saat dibuka, jadi jumlah tab lain tidak diketahui
// dari data yang sudah dimuat (aturan kit: chip hanya bila jumlahnya diketahui).

// Tab yang butuh eventId dipilih sebelum bisa dimuat — dipakai watch()
// di bawah supaya tab tanpa event (purchases/stock-by-outlet) tidak ikut
// menunggu eventId ter-set lebih dulu.
const EVENT_SCOPED_TABS = ['settlement', 'profit', 'outlet-profit'];

onMounted(async () => {
  events.value = (await listEvents({ per_page: 100 })).data;
  const active = events.value.find((e) => e.status === 'active');
  eventId.value = active?.id ?? events.value[0]?.id ?? '';
  outlets.value = (await listOutlets({ per_page: 100, is_active: true })).data;
  await loadActiveTab();
});

watch([eventId, activeTab], loadActiveTab);
watch([purchasesStatusFilter], () => { if (activeTab.value === 'purchases') loadActiveTab(); });

async function loadActiveTab() {
  if (EVENT_SCOPED_TABS.includes(activeTab.value) && !eventId.value) return;
  loading.value = true;
  loadError.value = false;
  try {
    if (activeTab.value === 'settlement') {
      const res = await outletSettlements(eventId.value);
      settlements.value = res.data;
    } else if (activeTab.value === 'profit') {
      profit.value = await profitReport(eventId.value);
    } else if (activeTab.value === 'outlet-profit') {
      const res = await outletProfitReport(eventId.value);
      outletProfit.value = res.data;
    } else if (activeTab.value === 'purchases') {
      purchases.value = await purchasesReport({ status: purchasesStatusFilter.value || undefined });
    } else if (activeTab.value === 'stock-by-outlet') {
      // Tanpa outlet_id — respons ringkasan (array per-outlet) tidak
      // berubah, per research.md R9. Detail per-varian hanya diambil saat
      // baris diklik (lihat StockByOutletTab / StockByOutletDetailModal),
      // BUKAN dieksekusi eagerly untuk semua outlet sekaligus.
      const res = await stockByOutletReport();
      stockByOutlet.value = res.data;
    } else if (activeTab.value === 'preorder') {
      const res = await preorderReport({ event_id: eventId.value || undefined });
      // BUG YANG DITEMUKAN & DIPERBAIKI (010-split-payment-preorder-reports, verifikasi browser):
      // baris laporan ini tidak punya field `id`, sedangkan DataTable.vue memakai
      // `row[rowKey]` (default 'id') sebagai :key v-for — tanpa id, setiap baris
      // ber-key `undefined` dan Vue salah mengenali baris sebagai satu node yang
      // sama, sehingga sebagian baris (mis. status dp_paid) tidak tampil dan baris
      // lain menampilkan data yang salah. Diperbaiki dengan composite key sintetis.
      preorderStats.value = res.rows.map((row) => ({ ...row, id: `${row.status}__${row.payment_completeness}` }));
      if (preorderView.value === 'outlet') await loadPreorderByOutlet();
    }
  } catch {
    // Pesan spesifik (mis. 403/5xx) tetap ditangani handler galat pusat; di sini hanya menandai agar
    // pengguna mendapat tombol coba lagi alih-alih tabel kosong yang menyesatkan.
    loadError.value = true;
  } finally {
    loading.value = false;
  }
}

// 012-seller-preorder-report-detail-export (US2, T010) — breakdown per
// outlet, dipanggil saat toggle "Per Outlet" diaktifkan (baik dari watch
// di bawah maupun dari loadActiveTab() di atas kalau toggle sudah aktif
// ketika tab/eventId berganti). Baris ini juga tidak punya `id` alami —
// sama seperti bug composite-key ringkasan di atas — jadi disintesis
// dengan pola yang sama, kali ini disertakan outlet_id supaya unik per
// kombinasi outlet × status × payment_completeness.
async function loadPreorderByOutlet() {
  const res = await preorderReport({ event_id: eventId.value || undefined, breakdown: 'outlet' });
  preorderByOutlet.value = res.rows.map((row) => ({
    ...row,
    id: `${row.outlet_id}__${row.status}__${row.payment_completeness}`,
  }));
  preorderBySubtotals.value = res.subtotals ?? [];
}

watch(preorderView, async (view) => {
  if (activeTab.value === 'preorder' && view === 'outlet' && !preorderByOutlet.value) {
    loading.value = true;
    loadError.value = false;
    try {
      await loadPreorderByOutlet();
    } catch {
      loadError.value = true;
    } finally {
      loading.value = false;
    }
  }
});

// Baris terfilter per tab (BUKAN memfilter `.value` sumbernya sendiri —
// supaya kalau filter dikosongkan lagi, seluruh baris asli masih ada tanpa
// perlu reload). Tiap tab menjumlah Grand Total-nya dari baris yang SEDANG
// TAMPIL (jadi kalau pengguna memilih satu outlet, "Grand Total"-nya
// otomatis menjadi subtotal outlet itu saja — itulah cara fitur ini
// "memisahkan total per outlet" tanpa endpoint atau tabel terpisah).
const byOutletFilter = (rows) =>
  !outletFilter.value ? (rows ?? []) : (rows ?? []).filter((r) => String(r.outlet_id) === String(outletFilter.value));
const filteredSettlements = computed(() => byOutletFilter(settlements.value));
const filteredOutletProfit = computed(() => byOutletFilter(outletProfit.value));
const filteredStockByOutlet = computed(() => byOutletFilter(stockByOutlet.value));
const filteredPreorderByOutlet = computed(() => byOutletFilter(preorderByOutlet.value));

// Penghitung "N data" = jumlah baris yang benar-benar tampil di tab aktif (sesudah filter), null selama
// belum ada data / sedang memuat / tab tanpa tabel (Modal & Untung).
const rowCount = computed(() => {
  if (loading.value || loadError.value) return null;
  switch (activeTab.value) {
    case 'settlement': return eventId.value && settlements.value ? filteredSettlements.value.length : null;
    case 'outlet-profit': return eventId.value && outletProfit.value ? filteredOutletProfit.value.length : null;
    case 'purchases': return purchases.value ? (purchases.value.rows ?? []).length : null;
    case 'stock-by-outlet': return stockByOutlet.value ? filteredStockByOutlet.value.length : null;
    case 'preorder':
      if (preorderView.value === 'outlet') return preorderByOutlet.value ? filteredPreorderByOutlet.value.length : null;
      return preorderStats.value ? preorderStats.value.length : null;
    default: return null;
  }
});

// Kunci ekspor tiap tab = kunci tab-nya, kecuali Rekap Outlet yang memakai nama laporan server.
const exportKey = computed(() => (activeTab.value === 'settlement' ? 'outlet-settlements' : activeTab.value));

async function doExport(report) {
  try {
    await exportReport(report, { event_id: eventId.value || undefined });
  } catch {
    toast.error(t('reports.export_report_failed'));
  }
}
</script>

<template>
  <div class="pb-10">
    <PageHeader :title="t('nav.reports')" :description="t('nav.reports_subtitle')">
      <template v-if="auth.canAccessMenu('reports')" #actions>
        <BaseButton variant="secondary" @click="doExport(exportKey)">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          {{ t('common.export_xlsx') }}
        </BaseButton>
      </template>
    </PageHeader>

    <div class="mx-[26px] border-2 border-line bg-white pb-1">
      <TabBar v-model="activeTab" :tabs="tabs" :label="t('nav.reports')" />

      <FilterBar :searchable="false" :count="rowCount">
        <!-- BUG YANG DITEMUKAN & DIPERBAIKI (010-split-payment-preorder-reports,
             verifikasi browser): BaseSelect's `:placeholder` hanya teks tampilan
             saat kosong, BUKAN opsi yang bisa dipilih ulang untuk mengosongkan
             `eventId` — semua tab EVENT_SCOPED_TABS lain memang mewajibkan satu
             event (ada EmptyState kalau eventId kosong), jadi celah ini baru
             kelihatan di tab Pre-order, satu-satunya tab yang boleh eventId
             kosong (preorder boleh tidak terikat event). Tanpa opsi "All events"
             eksplisit, pengguna tidak bisa kembali ke tampilan semua-event
             setelah memilih satu event. -->
        <BaseSelect
          v-if="EVENT_SCOPED_TABS.includes(activeTab) || activeTab === 'preorder'"
          class="w-56"
          v-model="eventId"
          :placeholder="t('reports.all_events')"
          :options="
            activeTab === 'preorder'
              ? [{ value: '', label: t('reports.all_events') }, ...events.map((e) => ({ value: e.id, label: e.name }))]
              : events.map((e) => ({ value: e.id, label: e.name }))
          "
        />
        <BaseSelect
          v-else-if="activeTab === 'purchases'"
          class="w-56"
          v-model="purchasesStatusFilter"
          :placeholder="t('reports.purchases_all_status')"
          :options="['draft', 'ordered', 'received', 'paid', 'cancelled'].map((s) => ({ value: s, label: t(`purchase_orders.status_${s}`) }))"
        />
        <!-- Filter "semua outlet" vs satu outlet — hanya di tab yang punya dimensi per-outlet (baris =
             satu outlet, atau breakdown per-outlet di tab Pre-order). -->
        <BaseSelect
          v-if="['settlement', 'outlet-profit', 'stock-by-outlet'].includes(activeTab) || (activeTab === 'preorder' && preorderView === 'outlet')"
          class="w-56"
          v-model="outletFilter"
          :placeholder="t('reports.all_sellers')"
          :options="[{ value: '', label: t('reports.all_sellers') }, ...outlets.map((o) => ({ value: o.id, label: o.name }))]"
        />
      </FilterBar>

      <ErrorState v-if="loadError" @retry="loadActiveTab" />
      <template v-else>
        <SettlementTab v-if="activeTab === 'settlement'" :rows="filteredSettlements" :loading="loading" :event-id="eventId" />
        <!-- Modal & Untung (owner/admin saja — disembunyikan sepenuhnya untuk kasir/inventory lewat `tabs`). -->
        <ProfitTab v-else-if="activeTab === 'profit'" :profit="profit" :event-id="eventId" />
        <OutletProfitTab v-else-if="activeTab === 'outlet-profit'" :rows="filteredOutletProfit" :loading="loading" :event-id="eventId" />
        <PurchasesTab v-else-if="activeTab === 'purchases'" :purchases="purchases" :loading="loading" />
        <StockByOutletTab v-else-if="activeTab === 'stock-by-outlet'" :rows="filteredStockByOutlet" :loading="loading" />
        <PreorderTab
          v-else-if="activeTab === 'preorder'"
          v-model:view="preorderView"
          :stats="preorderStats"
          :by-outlet="filteredPreorderByOutlet"
          :subtotals="preorderBySubtotals"
          :loading="loading"
        />
      </template>
    </div>
  </div>
</template>
