<script setup>
import { ref, computed, onMounted } from 'vue';
import { useAuthStore } from '../stores/auth';
import { listEvents } from '../api/events';
import { salesReport, profitReport, artistSettlements } from '../api/reports';
import { lowStock } from '../api/stock';
import { listPreorders } from '../api/preorders';
import { formatIDR, parseMoney } from '../utils/money';
import StatusPill from '../components/ui/StatusPill.vue';
import EmptyState from '../components/ui/EmptyState.vue';

const auth = useAuthStore();

const loading = ref(true);
const activeEvent = ref(null);
const sales = ref(null);
const profit = ref(null);
const settlements = ref([]);
const lowStockItems = ref([]);
const preorderAlerts = ref([]);

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
      salesReport({ event_id: eventId, group_by: 'day' }).then((r) => (sales.value = r)),
      lowStock().then((r) => (lowStockItems.value = r.data)),
      listPreorders({ status: 'dp_paid', per_page: 5 }).then((r) => (preorderAlerts.value = r.data)),
    ];
    if (eventId && auth.isOwnerOrAdmin) {
      tasks.push(profitReport(eventId).then((r) => (profit.value = r)));
      tasks.push(artistSettlements(eventId).then((r) => (settlements.value = r.data)));
    }
    await Promise.allSettled(tasks);
  } finally {
    loading.value = false;
  }
});

const maxDayAmount = computed(() => Math.max(1, ...(sales.value?.rows ?? []).map((r) => parseMoney(r.amount))));
const maxSettlement = computed(() => Math.max(1, ...settlements.value.map((s) => parseMoney(s.total_sales))));
</script>

<template>
  <div class="flex flex-col gap-5 px-[26px] pb-10 pt-[22px]">
    <div v-if="!loading && !activeEvent" class="flex items-center gap-3 rounded-card border border-warn-border bg-warn-bg px-4 py-3.5 text-[13px] text-warn-text">
      <i class="ph-duotone ph-info text-[19px]" aria-hidden="true"></i>
      Tidak ada event berstatus aktif. Buka satu di layar Event untuk melihat ringkasan performa.
    </div>

    <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
      <div class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2"><i class="ph-duotone ph-chart-line-up text-[18px] text-brand" aria-hidden="true"></i><span class="text-[12px] font-semibold text-muted-2">Penjualan bersih</span></div>
        <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.net_sales ?? 0) }}</span>
        <span class="text-[11.5px] text-muted-3">{{ activeEvent?.name ?? 'Seluruh event' }}</span>
      </div>
      <div class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2"><i class="ph-duotone ph-receipt text-[18px] text-brand" aria-hidden="true"></i><span class="text-[12px] font-semibold text-muted-2">Transaksi</span></div>
        <span class="text-[26px] font-extrabold tracking-tight">{{ sales?.totals?.order_count ?? 0 }}</span>
        <span class="text-[11.5px] text-muted-3">{{ sales?.totals?.unit_count ?? 0 }} unit terjual</span>
      </div>
      <div v-if="auth.isOwnerOrAdmin" class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2"><i class="ph-duotone ph-vault text-[18px] text-brand" aria-hidden="true"></i><span class="text-[12px] font-semibold text-muted-2">Keuntungan kotor</span></div>
        <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(profit?.gross_profit ?? 0) }}</span>
        <span class="text-[11.5px] text-muted-3">Setelah modal, sebelum biaya event</span>
      </div>
      <div v-else class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2"><i class="ph-duotone ph-tag text-[18px] text-brand" aria-hidden="true"></i><span class="text-[12px] font-semibold text-muted-2">Diskon diberikan</span></div>
        <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.discount_total ?? 0) }}</span>
        <span class="text-[11.5px] text-muted-3">Total potongan transaksi</span>
      </div>
      <div class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-[17px]">
        <div class="flex items-center gap-2"><i class="ph-duotone ph-warning text-[18px] text-brand" aria-hidden="true"></i><span class="text-[12px] font-semibold text-muted-2">Stok menipis</span></div>
        <span class="text-[26px] font-extrabold tracking-tight">{{ lowStockItems.length }}</span>
        <span class="text-[11.5px] text-muted-3">Varian di bawah ambang</span>
      </div>
    </div>

    <div class="grid grid-cols-1 items-start gap-4" :class="auth.isOwnerOrAdmin ? 'xl:grid-cols-[1.6fr_1fr]' : ''">
      <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
        <div class="flex items-baseline justify-between">
          <span class="text-[14.5px] font-bold">Penjualan per hari</span>
          <span class="text-[11.5px] text-muted-3">GET /reports/sales?group_by=day</span>
        </div>
        <EmptyState v-if="!loading && !sales?.rows?.length" icon="ph-chart-bar" message="Belum ada penjualan tercatat." />
        <div v-else class="flex h-[170px] items-end gap-2">
          <div v-for="row in sales?.rows ?? []" :key="row.label" class="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
            <span class="text-[10.5px] font-semibold text-muted-2">{{ formatIDR(row.amount) }}</span>
            <div class="w-full rounded-t-sm bg-brand" :style="{ height: `${(parseMoney(row.amount) / maxDayAmount) * 100}%`, minHeight: '3px' }"></div>
            <span class="text-[10.5px] text-muted-3">{{ row.label }}</span>
          </div>
        </div>
      </div>

      <div v-if="auth.isOwnerOrAdmin" class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">Hasil per artist</span>
        <!-- GET /reports/artist-settlements now lists every active artist,
             not only those with sales — so this only stays empty when
             there are no active artists at all for the event, never
             because sales are still zero. -->
        <EmptyState v-if="!loading && !settlements.length" icon="ph-users-three" message="Belum ada artist aktif terdaftar." />
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
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">Stok menipis</span>
        <EmptyState v-if="!loading && !lowStockItems.length" icon="ph-check-circle" message="Semua stok masih aman." />
        <div v-for="item in lowStockItems" :key="item.variant_id" class="flex items-center gap-3 border-b border-line-6 py-2.5 last:border-b-0">
          <div class="flex flex-1 flex-col gap-0.5"><span class="text-[13px] font-semibold">{{ item.product_name }}</span><span class="font-mono text-[10.5px] text-muted-3">{{ item.sku }}</span></div>
          <StatusPill variant="warn">{{ item.current_stock }} / {{ item.low_stock_alert }}</StatusPill>
        </div>
      </div>
      <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[14.5px] font-bold">Pre-order perlu tindakan</span>
        <EmptyState v-if="!loading && !preorderAlerts.length" icon="ph-check-circle" message="Tidak ada pre-order menunggu." />
        <div v-for="po in preorderAlerts" :key="po.id" class="flex items-center gap-3 border-b border-line-6 py-2.5 last:border-b-0">
          <div class="flex flex-1 flex-col gap-0.5"><span class="text-[13px] font-semibold">{{ po.customer_name }}</span><span class="text-[11px] text-muted-3">{{ po.preorder_number }}</span></div>
          <StatusPill variant="warn">Menunggu barang</StatusPill>
        </div>
      </div>
    </div>
  </div>
</template>
