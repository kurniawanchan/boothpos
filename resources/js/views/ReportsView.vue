<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { toLocalDateKey } from '../utils/date';
import { listOutlets } from '../api/outlets';
import {
  outletSettlements,
  profitReport,
  outletProfitReport,
  purchasesReport,
  stockByOutletReport,
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

// 046 — periode laporan = rentang tanggal BISNIS; bawaan hari ini (hari bisnis berjalan). Tidak ada lagi event.
const today = toLocalDateKey(new Date());
const dateFrom = ref(today);
const dateTo = ref(today);
const activeTab = ref('settlement');

const settlements = ref(null);
const profit = ref(null);
const outletProfit = ref(null);
const purchases = ref(null);
const stockByOutlet = ref(null);
const outlets = ref([]);
const purchasesStatusFilter = ref('');
const loading = ref(false);
// 041 — gagal memuat tab aktif: tampilkan keadaan galat dengan tombol coba lagi, bukan area kosong.
const loadError = ref(false);

// Tambahan: filter "semua outlet" vs satu outlet, dipakai di semua tab yang
// punya dimensi per-outlet (settlement, outlet-profit, stock-by-outlet). Difilter di sisi klien karena semua tab ini
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
        // F9.5 — laporan modal & laba kotor PER OUTLET.
        { key: 'outlet-profit', label: t('reports.tab_artist_profit') },
        // 006-purchase-order-and-ops (US9/US10) — dua tab ini tidak memakai periode: pembelian
        // dan stok gudang bukan angka per-periode seperti tiga tab di atas.
        { key: 'purchases', label: t('reports.tab_purchases') },
        { key: 'stock-by-outlet', label: t('reports.tab_stock_by_artist') },
      ]
    : []
);
// Chip jumlah pada tab sengaja TIDAK ada: tiap tab dimuat hanya saat dibuka, jadi jumlah tab lain tidak diketahui
// dari data yang sudah dimuat (aturan kit: chip hanya bila jumlahnya diketahui).

// Tab yang mengikuti periode tanggal; purchases/stock-by-outlet tidak.
const PERIOD_TABS = ['settlement', 'profit', 'outlet-profit'];
const periodParams = () => ({ date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined });

onMounted(async () => {
  outlets.value = (await listOutlets({ per_page: 100, is_active: true })).data;
  await loadActiveTab();
});

watch([dateFrom, dateTo, activeTab], loadActiveTab);
watch([purchasesStatusFilter], () => { if (activeTab.value === 'purchases') loadActiveTab(); });

async function loadActiveTab() {
  loading.value = true;
  loadError.value = false;
  try {
    if (activeTab.value === 'settlement') {
      const res = await outletSettlements(periodParams());
      settlements.value = res.data;
    } else if (activeTab.value === 'profit') {
      profit.value = await profitReport(periodParams());
    } else if (activeTab.value === 'outlet-profit') {
      const res = await outletProfitReport(periodParams());
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
    }
  } catch {
    // Pesan spesifik (mis. 403/5xx) tetap ditangani handler galat pusat; di sini hanya menandai agar
    // pengguna mendapat tombol coba lagi alih-alih tabel kosong yang menyesatkan.
    loadError.value = true;
  } finally {
    loading.value = false;
  }
}

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

// Penghitung "N data" = jumlah baris yang benar-benar tampil di tab aktif (sesudah filter), null selama
// belum ada data / sedang memuat / tab tanpa tabel (Modal & Untung).
const rowCount = computed(() => {
  if (loading.value || loadError.value) return null;
  switch (activeTab.value) {
    case 'settlement': return settlements.value ? filteredSettlements.value.length : null;
    case 'outlet-profit': return outletProfit.value ? filteredOutletProfit.value.length : null;
    case 'purchases': return purchases.value ? (purchases.value.rows ?? []).length : null;
    case 'stock-by-outlet': return stockByOutlet.value ? filteredStockByOutlet.value.length : null;
    default: return null;
  }
});

// Kunci ekspor tiap tab = kunci tab-nya, kecuali Rekap Outlet yang memakai nama laporan server.
const exportKey = computed(() => (activeTab.value === 'settlement' ? 'outlet-settlements' : activeTab.value));

async function doExport(report) {
  try {
    await exportReport(report, PERIOD_TABS.includes(activeTab.value) ? periodParams() : {});
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
        <!-- 046 — periode (tanggal bisnis) menggantikan pemilih event; hanya untuk tab yang mengikuti periode. -->
        <template v-if="PERIOD_TABS.includes(activeTab)">
          <input v-model="dateFrom" type="date" :max="dateTo || undefined" :aria-label="t('reports.filter_date_from')" class="h-9 border-2 border-line bg-white px-2.5 text-[13px]" data-testid="period-from" />
          <input v-model="dateTo" type="date" :min="dateFrom || undefined" :aria-label="t('reports.filter_date_to')" class="h-9 border-2 border-line bg-white px-2.5 text-[13px]" data-testid="period-to" />
        </template>
        <BaseSelect
          v-if="activeTab === 'purchases'"
          class="w-56"
          v-model="purchasesStatusFilter"
          :placeholder="t('reports.purchases_all_status')"
          :options="['draft', 'ordered', 'received', 'paid', 'cancelled'].map((s) => ({ value: s, label: t(`purchase_orders.status_${s}`) }))"
        />
        <!-- Filter "semua outlet" vs satu outlet — hanya di tab yang punya dimensi per-outlet (baris =
             satu outlet). -->
        <BaseSelect
          v-if="['settlement', 'outlet-profit', 'stock-by-outlet'].includes(activeTab)"
          class="w-56"
          v-model="outletFilter"
          :placeholder="t('reports.all_sellers')"
          :options="[{ value: '', label: t('reports.all_sellers') }, ...outlets.map((o) => ({ value: o.id, label: o.name }))]"
        />
      </FilterBar>

      <ErrorState v-if="loadError" @retry="loadActiveTab" />
      <template v-else>
        <SettlementTab v-if="activeTab === 'settlement'" :rows="filteredSettlements" :loading="loading" :date-from="dateFrom" :date-to="dateTo" />
        <!-- Modal & Untung (owner/admin saja — disembunyikan sepenuhnya untuk kasir/inventory lewat `tabs`). -->
        <ProfitTab v-else-if="activeTab === 'profit'" :profit="profit" />
        <OutletProfitTab v-else-if="activeTab === 'outlet-profit'" :rows="filteredOutletProfit" :loading="loading" />
        <PurchasesTab v-else-if="activeTab === 'purchases'" :purchases="purchases" :loading="loading" />
        <StockByOutletTab v-else-if="activeTab === 'stock-by-outlet'" :rows="filteredStockByOutlet" :loading="loading" />
      </template>
    </div>
  </div>
</template>
