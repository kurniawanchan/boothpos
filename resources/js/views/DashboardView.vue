<script setup>
import { computed, defineAsyncComponent, h, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { summary as fetchSummary, attention as fetchAttention, exportDashboard } from '../api/dashboard';
import { useDashboardPeriod, DASHBOARD_PERIODS } from '../composables/useDashboardPeriod';
import { useAutoRefresh } from '../composables/useAutoRefresh';
import { useSettingsStore } from '../stores/settings';
import { useToastStore } from '../stores/toast';
import { useOutletStore } from '../stores/outlet';
import { formatBusinessDate, formatClock, todayInZone } from '../utils/dashboardDates';
import PageHeader from '../components/page/PageHeader.vue';
import DashboardKpis from '../components/dashboard/DashboardKpis.vue';
import DashboardOrdersTable from '../components/dashboard/DashboardOrdersTable.vue';
import AttentionPanel from '../components/page/AttentionPanel.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingState from '../components/ui/LoadingState.vue';

/**
 * 042-dashboard-revamp (US1) — Dashboard: kepala halaman (tanggal bisnis, "Diperbarui", periode, Ekspor), empat
 * angka utama dari SATU endpoint (GET /dashboard/summary), "Perlu tindakan" dan "Order terbaru". (046: bagian
 * "Per event" sudah dihapus bersama modul Event.)
 *
 * Prinsip: tiap wilayah punya keadaan sendiri. Kegagalan ringkasan hanya menampilkan galat + Coba lagi di
 * wilayah angka; header dan tabel order tetap tampil (contracts/ui-contracts.md "States").
 */
const { t } = useI18n();
const settings = useSettingsStore();
const toast = useToastStore();
const outletStore = useOutletStore();
// 045 Stage 2 — baris cakupan: outlet terpilih, atau "Semua outlet · N". Hanya bila ada lebih dari satu outlet.
const scopeText = computed(() => {
  if (!outletStore.multi) return '';
  const chosen = outletStore.outlets.find((o) => o.id === outletStore.selected);
  return chosen ? t('dashboard.scope_outlet', { outlet: chosen.name }) : t('dashboard.scope_all', { count: outletStore.outlets.length });
});
const { period, setPeriod } = useDashboardPeriod();

// "Rincian periode" memuat chart.js dan beberapa panggilan API sendiri. defineAsyncComponent menjaga chart.js di
// luar potongan pertama; AWAL render-nya ditunda sampai ringkasan selesai (lihat `ordersReady`) karena PHP di
// instalasi toko melayani permintaan hampir berurutan — angka utama harus mendapat giliran pertama.
const PeriodSection = defineAsyncComponent({
  loader: () => import('../components/dashboard/PeriodSection.vue'),
  loadingComponent: { render: () => h(LoadingState, { rows: 6 }) },
  errorComponent: { render: () => h(ErrorState, { retryable: false }) },
  delay: 150,
});

const data = ref(null);
const state = ref('loading'); // loading | error | ready
const ordersReady = ref(false);
const exporting = ref(false);

// Respons dari periode yang sudah ditinggalkan (pengguna berpindah cepat) tidak boleh menimpa yang terbaru.
let requestSeq = 0;

/** @param {{silent?: boolean}} opts silent = refresh otomatis: tanpa kerangka "memuat", data lama dipertahankan saat gagal */
async function loadSummary({ silent = false } = {}) {
  const seq = ++requestSeq;
  if (!silent) state.value = 'loading';
  try {
    const res = await fetchSummary({ period: period.value });
    if (seq !== requestSeq) return;
    data.value = res;
    state.value = 'ready';
  } catch {
    if (seq !== requestSeq) return;
    // Refresh otomatis yang gagal tidak boleh menghapus angka yang sudah tampil (jam "Diperbarui" sudah
    // menunjukkan bahwa datanya tidak segar); galat penuh hanya bila belum ada data sama sekali.
    if (!silent || !data.value) state.value = 'error';
  } finally {
    if (seq === requestSeq) ordersReady.value = true;
  }
}

watch(period, () => loadSummary());

// "Perlu tindakan" menggambarkan HARI BISNIS SAAT INI, bukan periode terpilih (research D6), jadi tidak ikut
// berpindah saat periode berganti; ia hanya dimuat sekali dan ikut refresh 30 detik. Kegagalannya hanya
// mengubah panelnya sendiri.
const attention = ref(null);
const attentionState = ref('loading');
let attentionSeq = 0;
async function loadAttention({ silent = false } = {}) {
  const seq = ++attentionSeq;
  if (!silent) attentionState.value = 'loading';
  try {
    const res = await fetchAttention();
    if (seq !== attentionSeq) return;
    attention.value = res;
    attentionState.value = 'ready';
  } catch {
    if (seq !== attentionSeq) return;
    if (!silent || !attention.value) attentionState.value = 'error';
  }
}

// Tabel "Order terbaru" memegang filter/halaman/detailnya sendiri; refresh hanya memintanya mengambil ulang
// halaman yang sedang tampil (refresh() tidak mengubah filter dan tidak menutup detail yang terbuka).
const ordersTable = ref(null);

const refresher = useAutoRefresh(
  () => Promise.all([
    loadSummary({ silent: true }),
    loadAttention({ silent: true }),
    ordersTable.value?.refresh(),
  ]),
  30_000,
);

let fallbackTimer = null;
onMounted(() => {
  loadSummary();
  loadAttention();
  refresher.start();
  // Jaring pengaman: bila ringkasan menggantung, tabel order tetap muncul (isinya tidak boleh hilang).
  fallbackTimer = setTimeout(() => { ordersReady.value = true; }, 3000);
});
onBeforeUnmount(() => clearTimeout(fallbackTimer));

const tz = computed(() => settings.storeTimezone);
const businessDateText = computed(() => formatBusinessDate(data.value?.business_date ?? todayInZone(tz.value)));
const updatedText = computed(() => {
  const time = formatClock(data.value?.computed_at, tz.value);
  return time ? t('page.updated_at', { time }) : '';
});

const PERIOD_LABELS = { today: 'dashboard.period_today', '7d': 'dashboard.period_7d', '30d': 'dashboard.period_30d' };

async function onExport() {
  exporting.value = true;
  try {
    await exportDashboard({ period: period.value });
  } catch {
    toast.error(t('dashboard.export_failed'));
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <div class="pb-10">
    <PageHeader :title="t('nav.dashboard')">
      <template #meta>
        <span data-testid="business-date">{{ businessDateText }} · {{ t('dashboard.business_date_note') }}</span>
        <span v-if="scopeText" class="inline-flex items-center gap-1.5 border-l border-line pl-3 font-semibold" data-testid="dashboard-scope">{{ scopeText }}</span>
        <span v-if="updatedText" class="inline-flex items-center gap-1.5 border-l border-line pl-3" data-testid="updated-at">
          <span class="h-2 w-2 bg-marker-success" aria-hidden="true"></span>{{ updatedText }}
        </span>
      </template>
      <template #actions>
        <div role="tablist" :aria-label="t('dashboard.period_label')" class="inline-flex border-2 border-line bg-white">
          <button
            v-for="p in DASHBOARD_PERIODS"
            :key="p"
            type="button"
            role="tab"
            :aria-selected="period === p"
            :data-testid="`period-${p}`"
            class="h-9 px-3.5 text-[13px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-mint-100"
            :class="period === p ? 'bg-brand text-white' : 'text-muted-5 hover:bg-mint-50 hover:text-brand-active'"
            @click="setPeriod(p)"
          >
            {{ t(PERIOD_LABELS[p]) }}
          </button>
        </div>
        <button
          type="button"
          data-testid="export-dashboard"
          class="inline-flex h-10 items-center gap-2 border-2 border-line bg-white px-3.5 text-[13px] font-bold text-muted-5 transition-colors hover:border-brand hover:text-brand-active focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-mint-100 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="exporting"
          @click="onExport"
        >
          <i class="ph-duotone text-[16px]" :class="exporting ? 'ph-circle-notch animate-spin' : 'ph-download-simple'" aria-hidden="true"></i>
          {{ t('dashboard.export') }}
        </button>
      </template>
    </PageHeader>

    <!-- 045: dengan satu outlet terpilih, kartu stok (seluruh organisasi) memberi catatannya. -->
    <DashboardKpis :summary="data" :state="state" :outlet-selected="outletStore.selected !== null" :tax-label="settings.taxLabel" @retry="loadSummary()" />

    <!-- Cakupan satu outlet: "Seluruh toko" (Stage B menggantinya dengan nama outlet / "Semua outlet"). -->
    <AttentionPanel
      :items="attention?.items ?? []"
      :count="attention?.count ?? 0"
      :scope-text="t('dashboard.attention_scope_all')"
      :computed-at="attention?.computed_at ?? ''"
      :state="attentionState"
      @retry="loadAttention()"
    />

    <!-- Tabel menunggu ringkasan selesai (ordersReady) agar angka utama mendapat giliran pertama pada PHP toko
         yang melayani permintaan hampir berurutan; sementara itu wilayahnya tampil sebagai kerangka. -->
    <DashboardOrdersTable v-if="ordersReady" ref="ordersTable" :period="period" :running="refresher.running.value" :scope-text="t('dashboard.attention_scope_all')" />
    <section v-else class="mx-[26px] mb-5 border-2 border-line bg-white" data-testid="orders-slot"><LoadingState :rows="6" /></section>

    <PeriodSection v-if="ordersReady" />

  </div>
</template>
