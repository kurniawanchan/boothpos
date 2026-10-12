<script setup>
// 042-dashboard-revamp (T025, research D14) — panel Dashboard LAMA sebagai bagian sekunder di bawah angka utama.
// 046: bagian ini dulu "Per event"; event dihapus dari produk, jadi seluruh isinya kini berskala PERIODE
// (rentang tanggal bisnis, bawaan hari ini) — tanpa event aktif, grafik per event, atau panel pre-order.
// Data yang dimuat, gerbang izin (reports), keadaan memuat/kosong, grafik, dan statistik pelanggan tidak berubah.
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Bar, Doughnut } from 'vue-chartjs';
import { Chart, BarElement, ArcElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js';
import { useAuthStore } from '../../stores/auth';
import { toLocalDateKey } from '../../utils/date';
import { salesReport, profitReport, outletSettlements } from '../../api/reports';
import { lowStock } from '../../api/stock';
import { formatIDR, parseMoney } from '../../utils/money';
import StatusPill from '../ui/StatusPill.vue';
import EmptyState from '../ui/EmptyState.vue';
import KpiStrip from '../page/KpiStrip.vue';

// 005-ux-enhancements-dashboard (US2) — chart.js ends up in a code-split chunk
// (this is the only importer in the whole app), so it never inflates any other
// screen's bundle. 042: kini komponen ini dimuat lewat defineAsyncComponent dari
// DashboardView, jadi chart.js juga tidak menunda angka utama di bagian atas. Only the
// pieces actually used are registered, not the auto-registration bundle.
Chart.register(BarElement, ArcElement, CategoryScale, LinearScale, Tooltip, Legend);

// 041 — warna grafik diambil dari token CSS (bukan hex mentah) supaya ikut tema toko (utils/theme.js menimpa
// --color-brand saat berjalan). Kanvas chart.js tidak bisa memakai kelas Tailwind, jadi nilainya dibaca sekali
// dari :root begitu data tiba (komponen sudah ter-mount).
const CHART_TOKENS = [
  '--color-brand',
  '--color-marker-warning',
  '--color-marker-info',
  '--color-marker-success',
  '--color-marker-danger',
  '--color-brand-active',
  '--color-marker-neutral',
  '--color-warn-text',
];
function chartColors() {
  const style = getComputedStyle(document.documentElement);
  return CHART_TOKENS.map((name) => style.getPropertyValue(name).trim() || 'currentColor');
}

const auth = useAuthStore();
const { t } = useI18n();

const loading = ref(true);
const sales = ref(null);
const profit = ref(null);
const settlements = ref([]);
const lowStockItems = ref([]);
// 041 — beda antara "belum/gagal dimuat" (KPI tampil —) dan "dimuat, nol item" (KPI tampil 0).
const lowStockLoaded = ref(false);

// US2 — filter hari untuk panel "Penjualan per hari" (FR-009). 046: bawaan hari ini (tanggal bisnis);
// mengosongkan kedua kotak berarti seluruh riwayat.
const dateFrom = ref(toLocalDateKey(new Date()));
const dateTo = ref(toLocalDateKey(new Date()));
const periodParams = () => ({ date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined });

const categoryReport = ref(null);
const outletChartReport = ref(null);
// US6 (T048/T049) — statistik per pelanggan, mengikuti filter tanggal
// yang sama dengan panel "Penjualan per hari" (bukan filter terpisah,
// sesuai FR-016/acceptance scenario 3).
const customerReport = ref(null);

// US2 (FR-007) — shortcut aksi umum, digerbang menu_keys yang sama persis
// dengan yang sudah dipakai RailNav.vue (Constitution IV: server sudah
// jadi gerbang sebenarnya lewat endpoint masing-masing tindakan; ini
// murni kenyamanan navigasi, sama seperti sidebar).
const SHORTCUTS = [
  { key: 'new_sale', route: 'pos', menuKey: 'pos', icon: 'ph-shopping-cart-simple' },
  { key: 'stock_adjustment', route: 'stock', menuKey: 'stock', icon: 'ph-package' },
  { key: 'add_product', route: 'products', menuKey: 'products', icon: 'ph-plus-circle' },
];
const shortcuts = computed(() => SHORTCUTS.filter((s) => auth.canAccessMenu(s.menuKey)));

async function loadSalesPanel() {
  sales.value = await salesReport({ group_by: 'day', ...periodParams() });
}

// US6 (T048/T049) — R8: group_by=customer di endpoint sales report yang sama, dengan filter tanggal yang
// sama seperti panel "Penjualan per hari" di atas.
async function loadCustomerPanel() {
  customerReport.value = await salesReport({ group_by: 'customer', ...periodParams() });
}

async function loadBreakdownCharts() {
  const [cat, art] = await Promise.all([
    salesReport({ group_by: 'category', ...periodParams() }),
    salesReport({ group_by: 'outlet', ...periodParams() }),
  ]);
  categoryReport.value = cat;
  outletChartReport.value = art;
}

// Rekap per outlet dan laba kotor mengikuti periode yang sama (hanya pengguna dengan menu laporan).
async function loadReportsPanels() {
  const [p, st] = await Promise.all([profitReport(periodParams()), outletSettlements(periodParams())]);
  profit.value = p;
  settlements.value = st.data;
}

onMounted(async () => {
  try {
    const tasks = [
      loadSalesPanel(),
      loadBreakdownCharts(),
      lowStock().then((r) => {
        lowStockItems.value = r.data;
        lowStockLoaded.value = true;
      }),
    ];
    if (auth.canAccessMenu('reports')) {
      tasks.push(loadReportsPanels());
      tasks.push(loadCustomerPanel());
    }
    await Promise.allSettled(tasks);
  } finally {
    loading.value = false;
  }
});

watch([dateFrom, dateTo], () => {
  loadSalesPanel();
  loadBreakdownCharts();
  if (auth.canAccessMenu('reports')) {
    loadReportsPanels();
    loadCustomerPanel();
  }
});

// 041 — empat angka ringkasan dari data yang SAMA seperti sebelumnya (tidak ada panggilan API baru). Angka yang
// belum/gagal dimuat tampil "—", bukan 0. Tanpa catatan "vs kemarin": datanya belum ada (endpoint dashboard
// datang di fase lain).
const kpiItems = computed(() => {
  const totals = sales.value?.totals;
  const canReports = auth.canAccessMenu('reports');
  return [
    {
      key: 'net_sales',
      tone: 'accent',
      label: t('dashboard.net_sales'),
      value: totals ? formatIDR(totals.net_sales ?? 0) : null,
      note: '',
    },
    {
      key: 'transactions',
      tone: 'info',
      label: t('dashboard.transactions'),
      value: totals ? (totals.order_count ?? 0) : null,
      note: totals ? t('dashboard.units_sold', { count: totals.unit_count ?? 0 }) : '',
    },
    canReports
      ? {
          key: 'gross_profit',
          tone: 'success',
          label: t('dashboard.gross_profit'),
          value: profit.value ? formatIDR(profit.value.gross_profit ?? 0) : null,
          note: '',
        }
      : {
          key: 'discount_given',
          tone: 'neutral',
          label: t('dashboard.discount_given'),
          value: totals ? formatIDR(totals.discount_total ?? 0) : null,
          note: t('dashboard.total_transaction_discount'),
        },
    {
      key: 'low_stock',
      tone: 'warning',
      label: t('dashboard.low_stock'),
      value: lowStockLoaded.value ? lowStockItems.value.length : null,
      note: t('dashboard.variants_below_threshold'),
    },
  ];
});

const maxDayAmount = computed(() => Math.max(1, ...(sales.value?.rows ?? []).map((r) => parseMoney(r.amount))));
const maxSettlement = computed(() => Math.max(1, ...settlements.value.map((s) => parseMoney(s.total_sales))));

const outOfStockCount = computed(() => lowStockItems.value.filter((i) => i.current_stock === 0).length);

function chartDataFromRows(rows) {
  const list = rows ?? [];
  const colors = chartColors();
  return {
    labels: list.map((r) => r.label),
    datasets: [
      {
        data: list.map((r) => parseMoney(r.amount)),
        backgroundColor: list.map((_, i) => colors[i % colors.length]),
        borderWidth: 0,
      },
    ],
  };
}
const categoryChartData = computed(() => chartDataFromRows(categoryReport.value?.rows));
const outletChartData = computed(() => chartDataFromRows(outletChartReport.value?.rows));

// US6 (T048) — bentuk baris group_by=customer beda dari grouping lain
// (customer_id/customer_name/total_amount, bukan entity_id/label/amount,
// lihat CLAUDE.md & ReportController), jadi dipetakan terpisah alih-alih
// memaksakannya lewat chartDataFromRows(). customer_name null berarti
// pembeli walk-in (LEFT JOIN customers), bukan baris yang harus hilang.
const customerChartData = computed(() => {
  const list = customerReport.value?.rows ?? [];
  const colors = chartColors();
  return {
    labels: list.map((r) => r.customer_name ?? t('dashboard.customer_walk_in')),
    datasets: [
      {
        data: list.map((r) => parseMoney(r.total_amount)),
        backgroundColor: list.map((_, i) => colors[i % colors.length]),
        borderWidth: 0,
      },
    ],
  };
});

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
};
const doughnutOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10.5 } } } },
};
</script>

<template>
  <section aria-labelledby="per-period-heading" class="pb-10" data-testid="per-period-section">
    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 px-[26px] pb-4 pt-2">
      <h2 id="per-period-heading" class="text-[16px] font-bold tracking-tight">{{ t('dashboard.per_period_heading') }}</h2>
      <!-- US2 (FR-007) — pintasan ke tindakan yang paling sering dipakai (dulu di kepala halaman, yang pertama
           terisi). Digerbang menu_keys yang sama persis dengan RailNav.vue (Constitution IV: server sudah jadi
           gerbang sebenarnya lewat endpoint masing-masing tindakan; ini murni kenyamanan navigasi). -->
      <div v-if="shortcuts.length" class="flex flex-wrap items-center gap-2.5">
        <RouterLink
          v-for="(s, i) in shortcuts"
          :key="s.key"
          :to="{ name: s.route }"
          class="inline-flex h-10 items-center gap-2 border px-3.5 text-[13px] font-bold transition-colors focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-mint-100"
          :class="i === 0 ? 'border-transparent bg-brand text-white hover:bg-brand-hover' : 'border-line bg-white text-muted-5 hover:border-brand hover:text-brand-active'"
        >
          <i class="ph-duotone text-[16px]" :class="s.icon" aria-hidden="true"></i>
          {{ t(`dashboard.shortcut_${s.key}`) }}
        </RouterLink>
      </div>
    </div>

    <KpiStrip :items="kpiItems" />

    <div class="flex flex-col gap-5 px-[26px]">
    <!-- US2 (FR-013) — statistik tambahan: stok habis total. -->
    <div class="grid grid-cols-1 gap-3.5">
      <div class="flex items-center justify-between gap-3 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2.5">
          <i class="ph-duotone ph-x-circle text-[18px] text-danger-text" aria-hidden="true"></i>
          <span class="text-[12.5px] font-semibold text-muted-2">{{ t('dashboard.out_of_stock') }}</span>
        </div>
        <span class="text-[20px] font-extrabold tracking-tight">{{ outOfStockCount }}</span>
      </div>
    </div>

    <div class="grid grid-cols-1 items-start gap-4" :class="auth.canAccessMenu('reports') ? 'xl:grid-cols-[1.6fr_1fr]' : ''">
      <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-[14.5px] font-bold">{{ t('dashboard.sales_per_day') }}</span>
          <div class="flex items-center gap-1.5">
            <!-- US2 (FR-009) — filter hari, refetch otomatis lewat watch(). -->
            <input v-model="dateFrom" type="date" class="h-8 rounded-md border border-line px-2 text-[12px] outline-none focus:border-brand" />
            <span class="text-[11px] text-muted-3">–</span>
            <input v-model="dateTo" type="date" class="h-8 rounded-md border border-line px-2 text-[12px] outline-none focus:border-brand" />
          </div>
        </div>
        <EmptyState v-if="!loading && !sales?.rows?.length" icon="ph-chart-bar" :message="t('dashboard.no_sales_recorded')" />
        <div v-else class="flex h-[170px] items-end gap-2">
          <div v-for="row in sales?.rows ?? []" :key="row.label" class="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
            <span class="text-[10.5px] font-semibold text-muted-2">{{ formatIDR(row.amount) }}</span>
            <div class="w-full rounded-t-sm bg-brand" :style="{ height: `${(parseMoney(row.amount) / maxDayAmount) * 100}%`, minHeight: '3px' }"></div>
            <span class="text-[10.5px] text-muted-3">{{ row.label }}</span>
          </div>
        </div>
        <RouterLink :to="{ name: 'reports' }" class="self-start text-[12px] font-semibold text-brand-active hover:underline">
          {{ t('dashboard.view_full_report') }} →
        </RouterLink>
      </div>

      <div v-if="auth.canAccessMenu('reports')" class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.results_per_artist') }}</span>
        <!-- GET /reports/outlet-settlements now lists every active outlet,
             not only those with sales — so this only stays empty when
             there are no active outlets at all, never
             because sales are still zero. -->
        <EmptyState v-if="!loading && !settlements.length" icon="ph-users-three" :message="t('dashboard.no_active_artists')" />
        <div v-for="row in settlements" :key="row.outlet_id" class="flex flex-col gap-1.5">
          <div class="flex items-baseline justify-between">
            <span class="text-[12.5px] font-semibold">{{ row.outlet_name }}</span>
            <span class="text-[12.5px] font-bold" :class="parseMoney(row.total_sales) > 0 ? 'text-brand-active' : 'text-muted-3'">{{ formatIDR(row.total_sales) }}</span>
          </div>
          <!-- A zero-earning outlet still gets a visible (if minimal) bar
               rather than a zero-width one, so a list that's entirely
               zero still reads as "no sales yet" instead of a rendering
               glitch. -->
          <div class="h-[7px] overflow-hidden rounded-xs bg-track">
            <div
              class="h-full rounded-xs"
              :class="parseMoney(row.total_sales) > 0 ? 'bg-brand' : 'bg-disabled-2'"
              :style="{ width: `${Math.max((parseMoney(row.total_sales) / maxSettlement) * 100, 3)}%` }"
            ></div>
          </div>
        </div>
        <RouterLink :to="{ name: 'reports' }" class="self-start text-[12px] font-semibold text-brand-active hover:underline">
          {{ t('dashboard.view_full_report') }} →
        </RouterLink>
      </div>
    </div>

    <!-- US2 (FR-010) — grafik penjualan per kategori/outlet. -->
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.sales_by_category') }}</span>
        <EmptyState v-if="!loading && !categoryReport?.rows?.length" icon="ph-chart-pie-slice" :message="t('dashboard.no_sales_recorded')" />
        <div v-else class="h-[190px]"><Doughnut :data="categoryChartData" :options="doughnutOptions" /></div>
        <RouterLink :to="{ name: 'reports' }" class="self-start text-[12px] font-semibold text-brand-active hover:underline">{{ t('dashboard.view_full_report') }} →</RouterLink>
      </div>
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.sales_by_artist') }}</span>
        <EmptyState v-if="!loading && !outletChartReport?.rows?.length" icon="ph-chart-bar" :message="t('dashboard.no_sales_recorded')" />
        <div v-else class="h-[190px]"><Bar :data="outletChartData" :options="chartOptions" /></div>
        <RouterLink :to="{ name: 'reports' }" class="self-start text-[12px] font-semibold text-brand-active hover:underline">{{ t('dashboard.view_full_report') }} →</RouterLink>
      </div>
    </div>

    <!-- US6 (T048/T049) — statistik per pelanggan: tabel + chart, mengikuti
         filter tanggal dateFrom/dateTo yang sama seperti panel "Penjualan
         per hari" di atas (FR-016). Digerbang canAccessMenu('reports')
         sama seperti panel analitik lain di Dashboard ini. -->
    <div v-if="auth.canAccessMenu('reports')" class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1.4fr_1fr]">
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.customer_statistics') }}</span>
        <EmptyState v-if="!loading && !customerReport?.rows?.length" icon="ph-users-three" :message="t('dashboard.no_customer_data')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-left text-[12.5px]">
            <thead>
              <tr class="border-b border-line-6 text-[11px] font-semibold uppercase text-muted-3">
                <th class="py-2 pr-2">{{ t('dashboard.customer_name') }}</th>
                <th class="py-2 pr-2 text-right">{{ t('dashboard.customer_transaction_count') }}</th>
                <th class="py-2 text-right">{{ t('dashboard.customer_total_amount') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in customerReport?.rows ?? []" :key="row.customer_id ?? 'walk-in'" class="border-b border-line-6 last:border-b-0">
                <td class="py-2 pr-2 font-semibold">{{ row.customer_name ?? t('dashboard.customer_walk_in') }}</td>
                <td class="py-2 pr-2 text-right">{{ row.transaction_count }}</td>
                <td class="py-2 text-right font-bold text-brand-active">{{ formatIDR(row.total_amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.customer_statistics') }}</span>
        <EmptyState v-if="!loading && !customerReport?.rows?.length" icon="ph-users-three" :message="t('dashboard.no_customer_data')" />
        <div v-else class="h-[220px]"><Doughnut :data="customerChartData" :options="doughnutOptions" /></div>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4">
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.low_stock') }}</span>
        <EmptyState v-if="!loading && !lowStockItems.length" icon="ph-check-circle" :message="t('dashboard.all_stock_safe')" />
        <div v-for="item in lowStockItems" :key="item.variant_id" class="flex items-center gap-3 border-b border-line-6 py-2.5 last:border-b-0">
          <div class="flex flex-1 flex-col gap-0.5"><span class="text-[13px] font-semibold">{{ item.product_name }}</span><span class="font-mono text-[10.5px] text-muted-3">{{ item.sku }}</span></div>
          <StatusPill variant="warn">{{ item.current_stock }} / {{ item.low_stock_alert }}</StatusPill>
        </div>
        <RouterLink :to="{ name: 'stock' }" class="self-start text-[12px] font-semibold text-brand-active hover:underline">{{ t('dashboard.view_stock') }} →</RouterLink>
      </div>
    </div>
  </div>
  </section>
</template>
