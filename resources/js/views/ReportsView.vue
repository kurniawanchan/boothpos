<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { listEvents } from '../api/events';
import { listArtists } from '../api/artists';
import {
  artistSettlements,
  profitReport,
  artistProfitReport,
  purchasesReport,
  stockByArtistReport,
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
import ArtistProfitTab from './reports/ArtistProfitTab.vue';
import PurchasesTab from './reports/PurchasesTab.vue';
import StockByArtistTab from './reports/StockByArtistTab.vue';
import PreorderTab from './reports/PreorderTab.vue';

// 041 (US2) — halaman ini dipecah: induk hanya memegang pemuatan data, filter, dan ekspor; tiap tab laporan
// ada di views/reports/*. Perilaku, endpoint, dan semantik filter tidak berubah.
//
// Tab "Penjualan" dikeluarkan menjadi halaman/menu tersendiri (SalesView.vue)
// — laporan penjualan terbuka untuk semua peran, sedangkan ketiga tab yang
// tersisa di sini (Rekap Artist, Modal & Untung, Modal Artist) sengaja
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
const artistProfit = ref(null);
const purchases = ref(null);
const stockByArtist = ref(null);
const preorderStats = ref(null);
// 012-seller-preorder-report-detail-export (US2) — toggle antara ringkasan
// status × payment_completeness (default, sudah ada) dan breakdown per
// penjual (`breakdown=artist`, lihat research.md R2). Breakdown dimuat
// terpisah, hanya saat toggle diaktifkan — bukan sekaligus dengan ringkasan
// — mengikuti pola "jangan eager-fetch apa yang belum tentu dilihat" yang
// sudah dipakai StockByArtistDetailModal di tab lain.
const preorderView = ref('summary');
const preorderByArtist = ref(null);
// 039 — subtotal per penjual dari server (PreorderSellerSubtotals); layar hanya menempatkannya, tidak menghitung.
const preorderBySubtotals = ref([]);
const artists = ref([]);
const purchasesStatusFilter = ref('');
const loading = ref(false);
// 041 — gagal memuat tab aktif: tampilkan keadaan galat dengan tombol coba lagi, bukan area kosong.
const loadError = ref(false);

// Tambahan: filter "semua penjual" vs satu penjual, dipakai di semua tab yang
// punya dimensi per-penjual (settlement, artist-profit, stock-by-artist, dan
// preorder view "per penjual"). Difilter di sisi klien karena semua tab ini
// SUDAH memuat baris untuk seluruh penjual sekaligus (row-per-artist) — tidak
// ada endpoint baru yang dibutuhkan, filter ini hanya mempersempit tampilan.
const artistFilter = ref('');

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
        // F9.5 — laporan modal & laba kotor PER ARTIST, tab terpisah dari
        // "Modal & Untung" (yang berskala event) karena angkanya sengaja
        // TIDAK dikurangi biaya event dan akan salah dibaca kalau ditumpuk
        // di tab yang sama tanpa pemisahan visual yang jelas.
        { key: 'artist-profit', label: t('reports.tab_artist_profit') },
        // 006-purchase-order-and-ops (US9/US10) — dua tab baru ini SENGAJA
        // tidak diskop event (lihat komentar di api/reports.js): pembelian
        // dan stok gudang bukan konsep per-event seperti tiga tab di atas.
        { key: 'purchases', label: t('reports.tab_purchases') },
        { key: 'stock-by-artist', label: t('reports.tab_stock_by_artist') },
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
// di bawah supaya tab tanpa event (purchases/stock-by-artist) tidak ikut
// menunggu eventId ter-set lebih dulu.
const EVENT_SCOPED_TABS = ['settlement', 'profit', 'artist-profit'];

onMounted(async () => {
  events.value = (await listEvents({ per_page: 100 })).data;
  const active = events.value.find((e) => e.status === 'active');
  eventId.value = active?.id ?? events.value[0]?.id ?? '';
  artists.value = (await listArtists({ per_page: 100, is_active: true })).data;
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
      const res = await artistSettlements(eventId.value);
      settlements.value = res.data;
    } else if (activeTab.value === 'profit') {
      profit.value = await profitReport(eventId.value);
    } else if (activeTab.value === 'artist-profit') {
      const res = await artistProfitReport(eventId.value);
      artistProfit.value = res.data;
    } else if (activeTab.value === 'purchases') {
      purchases.value = await purchasesReport({ status: purchasesStatusFilter.value || undefined });
    } else if (activeTab.value === 'stock-by-artist') {
      // Tanpa artist_id — respons ringkasan (array per-penjual) tidak
      // berubah, per research.md R9. Detail per-varian hanya diambil saat
      // baris diklik (lihat StockByArtistTab / StockByArtistDetailModal),
      // BUKAN dieksekusi eagerly untuk semua penjual sekaligus.
      const res = await stockByArtistReport();
      stockByArtist.value = res.data;
    } else if (activeTab.value === 'preorder') {
      const res = await preorderReport({ event_id: eventId.value || undefined });
      // BUG YANG DITEMUKAN & DIPERBAIKI (010-split-payment-preorder-reports, verifikasi browser):
      // baris laporan ini tidak punya field `id`, sedangkan DataTable.vue memakai
      // `row[rowKey]` (default 'id') sebagai :key v-for — tanpa id, setiap baris
      // ber-key `undefined` dan Vue salah mengenali baris sebagai satu node yang
      // sama, sehingga sebagian baris (mis. status dp_paid) tidak tampil dan baris
      // lain menampilkan data yang salah. Diperbaiki dengan composite key sintetis.
      preorderStats.value = res.rows.map((row) => ({ ...row, id: `${row.status}__${row.payment_completeness}` }));
      if (preorderView.value === 'artist') await loadPreorderByArtist();
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
// penjual, dipanggil saat toggle "Per Penjual" diaktifkan (baik dari watch
// di bawah maupun dari loadActiveTab() di atas kalau toggle sudah aktif
// ketika tab/eventId berganti). Baris ini juga tidak punya `id` alami —
// sama seperti bug composite-key ringkasan di atas — jadi disintesis
// dengan pola yang sama, kali ini disertakan artist_id supaya unik per
// kombinasi penjual × status × payment_completeness.
async function loadPreorderByArtist() {
  const res = await preorderReport({ event_id: eventId.value || undefined, breakdown: 'artist' });
  preorderByArtist.value = res.rows.map((row) => ({
    ...row,
    id: `${row.artist_id}__${row.status}__${row.payment_completeness}`,
  }));
  preorderBySubtotals.value = res.subtotals ?? [];
}

watch(preorderView, async (view) => {
  if (activeTab.value === 'preorder' && view === 'artist' && !preorderByArtist.value) {
    loading.value = true;
    loadError.value = false;
    try {
      await loadPreorderByArtist();
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
// TAMPIL (jadi kalau pengguna memilih satu penjual, "Grand Total"-nya
// otomatis menjadi subtotal penjual itu saja — itulah cara fitur ini
// "memisahkan total per penjual" tanpa endpoint atau tabel terpisah).
const byArtistFilter = (rows) =>
  !artistFilter.value ? (rows ?? []) : (rows ?? []).filter((r) => String(r.artist_id) === String(artistFilter.value));
const filteredSettlements = computed(() => byArtistFilter(settlements.value));
const filteredArtistProfit = computed(() => byArtistFilter(artistProfit.value));
const filteredStockByArtist = computed(() => byArtistFilter(stockByArtist.value));
const filteredPreorderByArtist = computed(() => byArtistFilter(preorderByArtist.value));

// Penghitung "N data" = jumlah baris yang benar-benar tampil di tab aktif (sesudah filter), null selama
// belum ada data / sedang memuat / tab tanpa tabel (Modal & Untung).
const rowCount = computed(() => {
  if (loading.value || loadError.value) return null;
  switch (activeTab.value) {
    case 'settlement': return eventId.value && settlements.value ? filteredSettlements.value.length : null;
    case 'artist-profit': return eventId.value && artistProfit.value ? filteredArtistProfit.value.length : null;
    case 'purchases': return purchases.value ? (purchases.value.rows ?? []).length : null;
    case 'stock-by-artist': return stockByArtist.value ? filteredStockByArtist.value.length : null;
    case 'preorder':
      if (preorderView.value === 'artist') return preorderByArtist.value ? filteredPreorderByArtist.value.length : null;
      return preorderStats.value ? preorderStats.value.length : null;
    default: return null;
  }
});

// Kunci ekspor tiap tab = kunci tab-nya, kecuali Rekap Penjual yang memakai nama laporan server.
const exportKey = computed(() => (activeTab.value === 'settlement' ? 'artist-settlements' : activeTab.value));

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
        <!-- Filter "semua penjual" vs satu penjual — hanya di tab yang punya dimensi per-penjual (baris =
             satu penjual, atau breakdown per-penjual di tab Pre-order). -->
        <BaseSelect
          v-if="['settlement', 'artist-profit', 'stock-by-artist'].includes(activeTab) || (activeTab === 'preorder' && preorderView === 'artist')"
          class="w-56"
          v-model="artistFilter"
          :placeholder="t('reports.all_sellers')"
          :options="[{ value: '', label: t('reports.all_sellers') }, ...artists.map((a) => ({ value: a.id, label: a.name }))]"
        />
      </FilterBar>

      <ErrorState v-if="loadError" @retry="loadActiveTab" />
      <template v-else>
        <SettlementTab v-if="activeTab === 'settlement'" :rows="filteredSettlements" :loading="loading" :event-id="eventId" />
        <!-- Modal & Untung (owner/admin saja — disembunyikan sepenuhnya untuk kasir/inventory lewat `tabs`). -->
        <ProfitTab v-else-if="activeTab === 'profit'" :profit="profit" :event-id="eventId" />
        <ArtistProfitTab v-else-if="activeTab === 'artist-profit'" :rows="filteredArtistProfit" :loading="loading" :event-id="eventId" />
        <PurchasesTab v-else-if="activeTab === 'purchases'" :purchases="purchases" :loading="loading" />
        <StockByArtistTab v-else-if="activeTab === 'stock-by-artist'" :rows="filteredStockByArtist" :loading="loading" />
        <PreorderTab
          v-else-if="activeTab === 'preorder'"
          v-model:view="preorderView"
          :stats="preorderStats"
          :by-artist="filteredPreorderByArtist"
          :subtotals="preorderBySubtotals"
          :loading="loading"
        />
      </template>
    </div>
  </div>
</template>
