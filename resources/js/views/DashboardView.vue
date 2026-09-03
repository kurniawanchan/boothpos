<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Bar, Doughnut } from 'vue-chartjs';
import { Chart, BarElement, ArcElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js';
import { useAuthStore } from '../stores/auth';
import { listEvents } from '../api/events';
import { salesReport, profitReport, artistSettlements } from '../api/reports';
import { lowStock } from '../api/stock';
import { listPreorders } from '../api/preorders';
import { formatIDR, parseMoney } from '../utils/money';
import StatusPill from '../components/ui/StatusPill.vue';
import EmptyState from '../components/ui/EmptyState.vue';

// 005-ux-enhancements-dashboard (US2) — chart.js dynamically ends up in
// this view's own code-split chunk (this is the only importer in the
// whole app), so it never inflates any other screen's bundle. Only the
// pieces actually used are registered, not the auto-registration bundle.
Chart.register(BarElement, ArcElement, CategoryScale, LinearScale, Tooltip, Legend);

const CHART_COLORS = ['#2f9e6b', '#f0a63f', '#4f7ac9', '#c463c9', '#e2695c', '#3fb0c9', '#9a8f3f', '#7a6fd6'];

const auth = useAuthStore();
const { t } = useI18n();

const loading = ref(true);
const activeEvent = ref(null);
const sales = ref(null);
const profit = ref(null);
const settlements = ref([]);
const lowStockItems = ref([]);
const preorderAlerts = ref([]);
const pendingPreorderCount = ref(0);

// US2 — filter hari untuk panel "Penjualan per hari" (FR-009). Default
// kosong = tanpa batas tanggal (perilaku lama, seluruh riwayat event).
const dateFrom = ref('');
const dateTo = ref('');

const categoryReport = ref(null);
const artistChartReport = ref(null);
const eventReport = ref(null);

// US2 (FR-007) — shortcut aksi umum, digerbang menu_keys yang sama persis
// dengan yang sudah dipakai AppSidebar.vue (Constitution IV: server sudah
// jadi gerbang sebenarnya lewat endpoint masing-masing tindakan; ini
// murni kenyamanan navigasi, sama seperti sidebar).
const SHORTCUTS = [
  { key: 'new_sale', route: 'pos', menuKey: 'pos', icon: 'ph-shopping-cart-simple' },
  { key: 'new_preorder', route: 'preorders', menuKey: 'preorders', icon: 'ph-clock-countdown' },
  { key: 'stock_adjustment', route: 'stock', menuKey: 'stock', icon: 'ph-package' },
  { key: 'add_product', route: 'products', menuKey: 'products', icon: 'ph-plus-circle' },
];
const shortcuts = computed(() => SHORTCUTS.filter((s) => auth.canAccessMenu(s.menuKey)));

async function loadSalesPanel() {
  const res = await salesReport({
    event_id: activeEvent.value?.id,
    group_by: 'day',
    date_from: dateFrom.value || undefined,
    date_to: dateTo.value || undefined,
  });
  sales.value = res;
}

async function loadBreakdownCharts() {
  const eventId = activeEvent.value?.id;
  const [cat, art, evt] = await Promise.all([
    salesReport({ event_id: eventId, group_by: 'category' }),
    salesReport({ event_id: eventId, group_by: 'artist' }),
    // Event breakdown intentionally NOT scoped to eventId — the whole
    // point of this chart is comparing across events.
    salesReport({ group_by: 'event' }),
  ]);
  categoryReport.value = cat;
  artistChartReport.value = art;
  eventReport.value = evt;
}

onMounted(async () => {
  try {
    // BoothPOS has no dedicated "current active event" endpoint — the
    // dashboard treats the first `status=active` event as the one in
    // progress right now. If none is active there is simply nothing
    // event-scoped to summarize yet.
    const eventsRes = await listEvents({ status: 'active', per_page: 1 });
    activeEvent.value = eventsRes.data[0] ?? null;

    const eventId = activeEvent.value?.id;
    const tasks = [
      loadSalesPanel(),
      loadBreakdownCharts(),
      lowStock().then((r) => (lowStockItems.value = r.data)),
      listPreorders({ status: 'dp_paid', per_page: 5 }).then((r) => {
        preorderAlerts.value = r.data;
        pendingPreorderCount.value = r.meta?.total ?? r.data.length;
      }),
    ];
    if (eventId && auth.canAccessMenu('reports')) {
      tasks.push(profitReport(eventId).then((r) => (profit.value = r)));
      tasks.push(artistSettlements(eventId).then((r) => (settlements.value = r.data)));
    }
    await Promise.allSettled(tasks);
  } finally {
    loading.value = false;
  }
});

watch([dateFrom, dateTo], loadSalesPanel);

const maxDayAmount = computed(() => Math.max(1, ...(sales.value?.rows ?? []).map((r) => parseMoney(r.amount))));
const maxSettlement = computed(() => Math.max(1, ...settlements.value.map((s) => parseMoney(s.total_sales))));

const outOfStockCount = computed(() => lowStockItems.value.filter((i) => i.current_stock === 0).length);

function chartDataFromRows(rows) {
  const list = rows ?? [];
  return {
    labels: list.map((r) => r.label),
    datasets: [
      {
        data: list.map((r) => parseMoney(r.amount)),
        backgroundColor: list.map((_, i) => CHART_COLORS[i % CHART_COLORS.length]),
        borderWidth: 0,
      },
    ],
  };
}
const categoryChartData = computed(() => chartDataFromRows(categoryReport.value?.rows));
const artistChartData = computed(() => chartDataFromRows(artistChartReport.value?.rows));
const eventChartData = computed(() => chartDataFromRows(eventReport.value?.rows));

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
  <div class="flex flex-col gap-5 px-[26px] pb-10 pt-[22px]">
    <div v-if="!loading && !activeEvent" class="flex items-center gap-3 rounded-card border border-warn-border bg-warn-bg px-4 py-3.5 text-[13px] text-warn-text">
      <i class="ph-duotone ph-info text-[19px]" aria-hidden="true"></i>
      {{ t('dashboard.no_active_event') }}
    </div>

    <!-- US2 (FR-007) — shortcut ke tindakan yang paling sering dipakai. -->
    <div v-if="shortcuts.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <RouterLink
        v-for="s in shortcuts"
        :key="s.key"
        :to="{ name: s.route }"
        class="flex flex-col items-center gap-2 rounded-card border border-line-2 bg-white p-4 text-center transition-colors hover:border-brand hover:bg-mint-100"
      >
        <i class="ph-duotone text-[22px] text-brand" :class="s.icon" aria-hidden="true"></i>
        <span class="text-[12px] font-bold">{{ t(`dashboard.shortcut_${s.key}`) }}</span>
      </RouterLink>
    </div>

    <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
      <div class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2"><i class="ph-duotone ph-chart-line-up text-[18px] text-brand" aria-hidden="true"></i><span class="text-[12px] font-semibold text-muted-2">{{ t('dashboard.net_sales') }}</span></div>
        <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.net_sales ?? 0) }}</span>
        <span class="text-[11.5px] text-muted-3">{{ activeEvent?.name ?? t('dashboard.all_events') }}</span>
      </div>
      <div class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2"><i class="ph-duotone ph-receipt text-[18px] text-brand" aria-hidden="true"></i><span class="text-[12px] font-semibold text-muted-2">{{ t('dashboard.transactions') }}</span></div>
        <span class="text-[26px] font-extrabold tracking-tight">{{ sales?.totals?.order_count ?? 0 }}</span>
        <span class="text-[11.5px] text-muted-3">{{ t('dashboard.units_sold', { count: sales?.totals?.unit_count ?? 0 }) }}</span>
      </div>
      <div v-if="auth.canAccessMenu('reports')" class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2"><i class="ph-duotone ph-vault text-[18px] text-brand" aria-hidden="true"></i><span class="text-[12px] font-semibold text-muted-2">{{ t('dashboard.gross_profit') }}</span></div>
        <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(profit?.gross_profit ?? 0) }}</span>
        <span class="text-[11.5px] text-muted-3">{{ t('dashboard.after_cost_before_event_expense') }}</span>
      </div>
      <div v-else class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2"><i class="ph-duotone ph-tag text-[18px] text-brand" aria-hidden="true"></i><span class="text-[12px] font-semibold text-muted-2">{{ t('dashboard.discount_given') }}</span></div>
        <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.discount_total ?? 0) }}</span>
        <span class="text-[11.5px] text-muted-3">{{ t('dashboard.total_transaction_discount') }}</span>
      </div>
      <div class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2"><i class="ph-duotone ph-warning text-[18px] text-brand" aria-hidden="true"></i><span class="text-[12px] font-semibold text-muted-2">{{ t('dashboard.low_stock') }}</span></div>
        <span class="text-[26px] font-extrabold tracking-tight">{{ lowStockItems.length }}</span>
        <span class="text-[11.5px] text-muted-3">{{ t('dashboard.variants_below_threshold') }}</span>
      </div>
    </div>

    <!-- US2 (FR-013) — statistik tambahan: stok habis total & pre-order menunggu. -->
    <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
      <div class="flex items-center justify-between gap-3 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2.5">
          <i class="ph-duotone ph-x-circle text-[18px] text-danger-text" aria-hidden="true"></i>
          <span class="text-[12.5px] font-semibold text-muted-2">{{ t('dashboard.out_of_stock') }}</span>
        </div>
        <span class="text-[20px] font-extrabold tracking-tight">{{ outOfStockCount }}</span>
      </div>
      <div class="flex items-center justify-between gap-3 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2.5">
          <i class="ph-duotone ph-clock-countdown text-[18px] text-warn-text" aria-hidden="true"></i>
          <span class="text-[12.5px] font-semibold text-muted-2">{{ t('dashboard.pending_preorders') }}</span>
        </div>
        <span class="text-[20px] font-extrabold tracking-tight">{{ pendingPreorderCount }}</span>
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
        <!-- GET /reports/artist-settlements now lists every active artist,
             not only those with sales — so this only stays empty when
             there are no active artists at all for the event, never
             because sales are still zero. -->
        <EmptyState v-if="!loading && !settlements.length" icon="ph-users-three" :message="t('dashboard.no_active_artists')" />
        <div v-for="row in settlements" :key="row.artist_id" class="flex flex-col gap-1.5">
          <div class="flex items-baseline justify-between">
            <span class="text-[12.5px] font-semibold">{{ row.artist_name }}</span>
            <span class="text-[12.5px] font-bold" :class="parseMoney(row.total_sales) > 0 ? 'text-brand-active' : 'text-muted-3'">{{ formatIDR(row.total_sales) }}</span>
          </div>
          <!-- A zero-earning artist still gets a visible (if minimal) bar
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

    <!-- US2 (FR-010) — grafik penjualan per kategori/artist/event. -->
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.sales_by_category') }}</span>
        <EmptyState v-if="!loading && !categoryReport?.rows?.length" icon="ph-chart-pie-slice" :message="t('dashboard.no_sales_recorded')" />
        <div v-else class="h-[190px]"><Doughnut :data="categoryChartData" :options="doughnutOptions" /></div>
        <RouterLink :to="{ name: 'reports' }" class="self-start text-[12px] font-semibold text-brand-active hover:underline">{{ t('dashboard.view_full_report') }} →</RouterLink>
      </div>
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.sales_by_artist') }}</span>
        <EmptyState v-if="!loading && !artistChartReport?.rows?.length" icon="ph-chart-bar" :message="t('dashboard.no_sales_recorded')" />
        <div v-else class="h-[190px]"><Bar :data="artistChartData" :options="chartOptions" /></div>
        <RouterLink :to="{ name: 'reports' }" class="self-start text-[12px] font-semibold text-brand-active hover:underline">{{ t('dashboard.view_full_report') }} →</RouterLink>
      </div>
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.sales_by_event') }}</span>
        <EmptyState v-if="!loading && !eventReport?.rows?.length" icon="ph-chart-bar" :message="t('dashboard.no_sales_recorded')" />
        <div v-else class="h-[190px]"><Bar :data="eventChartData" :options="chartOptions" /></div>
        <RouterLink :to="{ name: 'events' }" class="self-start text-[12px] font-semibold text-brand-active hover:underline">{{ t('dashboard.view_events') }} →</RouterLink>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.low_stock') }}</span>
        <EmptyState v-if="!loading && !lowStockItems.length" icon="ph-check-circle" :message="t('dashboard.all_stock_safe')" />
        <div v-for="item in lowStockItems" :key="item.variant_id" class="flex items-center gap-3 border-b border-line-6 py-2.5 last:border-b-0">
          <div class="flex flex-1 flex-col gap-0.5"><span class="text-[13px] font-semibold">{{ item.product_name }}</span><span class="font-mono text-[10.5px] text-muted-3">{{ item.sku }}</span></div>
          <StatusPill variant="warn">{{ item.current_stock }} / {{ item.low_stock_alert }}</StatusPill>
        </div>
        <RouterLink :to="{ name: 'stock' }" class="self-start text-[12px] font-semibold text-brand-active hover:underline">{{ t('dashboard.view_stock') }} →</RouterLink>
      </div>
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">{{ t('dashboard.action_needed_preorders') }}</span>
        <EmptyState v-if="!loading && !preorderAlerts.length" icon="ph-check-circle" :message="t('dashboard.no_preorders_waiting')" />
        <div v-for="po in preorderAlerts" :key="po.id" class="flex items-center gap-3 border-b border-line-6 py-2.5 last:border-b-0">
          <div class="flex flex-1 flex-col gap-0.5"><span class="text-[13px] font-semibold">{{ po.customer_name }}</span><span class="text-[11px] text-muted-3">{{ po.preorder_number }}</span></div>
          <StatusPill variant="warn">{{ t('dashboard.waiting_for_goods') }}</StatusPill>
        </div>
        <RouterLink :to="{ name: 'preorders' }" class="self-start text-[12px] font-semibold text-brand-active hover:underline">{{ t('dashboard.view_preorders') }} →</RouterLink>
      </div>
    </div>
  </div>
</template>
