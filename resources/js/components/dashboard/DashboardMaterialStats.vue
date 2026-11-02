<script setup>
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { getDashboardMaterials } from '../../api/dashboard';
import { useOutletStore } from '../../stores/outlet';
import { formatIDR } from '../../utils/money';
import ErrorState from '../ui/ErrorState.vue';

/**
 * 088 US3 — statistik bahan & stok di Dashboard. Angka dari GET /dashboard/materials = ringkasan Laporan → Bahan
 * dengan ambang bawaan, jadi klik kartu membuka laporan tersaring dengan angka yang sama (FR-014/015). Rupiah hanya
 * tampil bila server mengirimnya (menu `reports`). Memuat sendiri; galat tidak memecah Dashboard.
 */
const props = defineProps({ period: { type: String, default: 'today' } });
const { t } = useI18n();
const router = useRouter();
const outletStore = useOutletStore();

const stats = ref(null);
const failed = ref(false);
let seq = 0;

async function load() {
  const mine = ++seq;
  failed.value = false;
  try {
    const res = await getDashboardMaterials({ period: props.period });
    if (mine === seq) stats.value = res;
  } catch {
    if (mine === seq) failed.value = true;
  }
}
onMounted(load);
watch(() => props.period, load);
watch(() => outletStore.selected, load);

const open = (status) => router.push({ name: 'reports', query: { tab: 'materials', ...(status ? { status } : {}) } });
const CARDS = [
  { key: 'negative', status: 'negative', tone: 'text-danger-text' },
  { key: 'out', status: 'out', tone: 'text-danger-text' },
  { key: 'low', status: 'low', tone: 'text-warn-text' },
];
</script>

<template>
  <section class="mx-[26px] border-2 border-line bg-white" data-testid="dashboard-material-stats">
    <header class="flex items-baseline justify-between gap-3 border-b-2 border-line px-5 py-3">
      <h2 class="text-[15px] font-extrabold">{{ t('dashboard.materials_title') }}</h2>
      <span v-if="stats" class="text-[11.5px] text-muted-3">{{ t('dashboard.materials_basis', { days: stats.params.window_days, risk: stats.params.risk_days }) }}</span>
    </header>
    <ErrorState v-if="failed" @retry="load" />
    <div v-else-if="stats" class="grid grid-cols-2 gap-px bg-line sm:grid-cols-3 lg:grid-cols-6">
      <button
        v-for="c in CARDS"
        :key="c.key"
        type="button"
        class="flex flex-col items-start gap-1 bg-white px-4 py-3 text-left hover:bg-mint-50"
        :data-testid="`dms-${c.key}`"
        @click="open(c.status)"
      >
        <span class="text-[11.5px] font-semibold text-muted-4">{{ t(`reports.mat_status_${c.key}`) }}</span>
        <span class="text-[24px] font-extrabold tabular-nums" :class="stats.counts[c.key] > 0 ? c.tone : ''">{{ stats.counts[c.key] }}</span>
      </button>
      <button type="button" class="flex flex-col items-start gap-1 bg-white px-4 py-3 text-left hover:bg-mint-50" data-testid="dms-products" @click="open(null)">
        <span class="text-[11.5px] font-semibold text-muted-4">{{ t('reports.mat_at_risk_products') }}</span>
        <span class="text-[24px] font-extrabold tabular-nums">{{ stats.counts.at_risk_products }}</span>
      </button>
      <RouterLink :to="{ name: 'stock', query: { low: '1' } }" class="flex flex-col items-start gap-1 bg-white px-4 py-3 hover:bg-mint-50" data-testid="dms-low-stock-products">
        <span class="text-[11.5px] font-semibold text-muted-4">{{ t('dashboard.materials_low_stock_products') }}</span>
        <span class="text-[24px] font-extrabold tabular-nums">{{ stats.low_stock_products }}</span>
      </RouterLink>
      <div v-if="stats.stock_value !== undefined || stats.wasted_value !== undefined" class="flex flex-col gap-1 bg-white px-4 py-3">
        <span v-if="stats.stock_value !== undefined" data-testid="dms-stock-value" class="text-[12px]">
          <span class="block font-semibold text-muted-4">{{ t('reports.mat_stock_value') }}</span>
          <span class="text-[15px] font-extrabold tabular-nums">{{ stats.stock_value === null ? '—' : formatIDR(Number(stats.stock_value)) }}</span>
        </span>
        <span v-if="stats.wasted_value !== undefined" data-testid="dms-wasted-value" class="text-[12px]">
          <span class="block font-semibold text-muted-4">{{ t('dashboard.materials_wasted_value') }}</span>
          <span class="text-[15px] font-extrabold tabular-nums">{{ formatIDR(Number(stats.wasted_value)) }}</span>
        </span>
      </div>
    </div>
  </section>
</template>
