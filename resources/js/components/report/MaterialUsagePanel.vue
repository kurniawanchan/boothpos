<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { getMaterialUsage } from '../../api/reports';
import { useOutletStore } from '../../stores/outlet';
import { formatIDR } from '../../utils/money';
import { toLocalDateKey } from '../../utils/date';
import ErrorState from '../ui/ErrorState.vue';
import MaterialUsageByMenuModal from './MaterialUsageByMenuModal.vue';

/**
 * 088 US4 — sub-tab Pemakaian di Laporan → Bahan: per periode tanggal bisnis, total rupiah per jenis, tren harian
 * (pemakaian penjualan bersih & terbuang), top 10, dan angka per bahan per outlet. Klik nama bahan → menu yang
 * memakainya. Angka dari GET /reports/materials/usage (= buku besar bahan, FR-012).
 */
const props = defineProps({
  kind: { type: String, default: '' },
  search: { type: String, default: '' },
  // Saringan outlet dari tab Bahan (kosong = semua outlet yang boleh dilihat).
  outletIds: { type: Array, default: () => [] },
});
const { t } = useI18n();
const outletStore = useOutletStore();

const today = new Date();
const weekAgo = new Date(today.getTime() - 6 * 86400000);
const dateFrom = ref(toLocalDateKey(weekAgo));
const dateTo = ref(toLocalDateKey(today));

const data = ref(null);
const failed = ref(false);
const byMenu = ref(null);
let seq = 0;

async function load() {
  if (!dateFrom.value || !dateTo.value || dateFrom.value > dateTo.value) return;
  const mine = ++seq;
  failed.value = false;
  try {
    const res = await getMaterialUsage({
      date_from: dateFrom.value, date_to: dateTo.value,
      ...(props.kind ? { kind: [props.kind] } : {}),
      ...(props.search.trim() ? { search: props.search.trim() } : {}),
      ...(props.outletIds.length ? { outlet_id: props.outletIds } : {}),
    });
    if (mine === seq) data.value = res;
  } catch {
    if (mine === seq) failed.value = true;
  }
}
onMounted(load);
watch([dateFrom, dateTo, () => props.kind, () => props.search, () => props.outletIds.join(',')], load);

const showOutlet = computed(() => outletStore.multi && props.outletIds.length !== 1);
const money = (v) => (v === null || v === undefined ? '—' : formatIDR(Number(v)));
const qty = (v) => {
  const n = Number(v);
  return n === 0 ? '—' : `${n < 0 ? '−' : ''}${Math.abs(n).toLocaleString('id-ID', { maximumFractionDigits: 3 })}`;
};
// Tinggi batang tren relatif terhadap hari tertinggi (pemakaian + terbuang); hanya tampilan.
const trendMax = computed(() => Math.max(1, ...(data.value?.trend ?? []).map((d) => Number(d.sold_net_value) + Number(d.wasted_value))));
const pct = (v) => `${Math.round((Number(v) / trendMax.value) * 100)}%`;
const TOTALS = [
  { key: 'sold', field: 'sold_net_value', label: 'reports.mat_total_sold' },
  { key: 'wasted', field: 'wasted_value', label: 'reports.mat_total_wasted' },
  { key: 'adjusted-out', field: 'adjusted_out_value', label: 'reports.mat_total_adjusted_out' },
  { key: 'purchased', field: 'purchased_value', label: 'reports.mat_total_purchased' },
];
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-end gap-3">
      <label class="flex flex-col gap-1 text-[12px] font-semibold text-muted-4">
        {{ t('reports.filter_date_from') }}
        <input v-model="dateFrom" type="date" :max="dateTo" class="h-9 border-2 border-line bg-white px-2.5 text-[13px]" data-testid="mat-usage-from" />
      </label>
      <label class="flex flex-col gap-1 text-[12px] font-semibold text-muted-4">
        {{ t('reports.filter_date_to') }}
        <input v-model="dateTo" type="date" :min="dateFrom" class="h-9 border-2 border-line bg-white px-2.5 text-[13px]" data-testid="mat-usage-to" />
      </label>
    </div>

    <ErrorState v-if="failed" @retry="load" />
    <template v-else-if="data">
      <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
        <div v-for="c in TOTALS" :key="c.key" class="flex flex-col gap-1 border-2 border-line bg-white px-3 py-2.5" :data-testid="`mat-usage-total-${c.key}`">
          <span class="text-[11.5px] font-semibold text-muted-4">{{ t(c.label) }}</span>
          <span class="text-[17px] font-extrabold tabular-nums">{{ money(data.totals[c.field]) }}</span>
        </div>
      </div>

      <div class="grid gap-3 lg:grid-cols-3">
        <section class="border-2 border-line bg-white px-4 py-3 lg:col-span-2">
          <h3 class="mb-2 text-[13px] font-bold">{{ t('reports.mat_trend') }}</h3>
          <div class="flex h-28 items-end gap-1">
            <div v-for="d in data.trend" :key="d.date" class="flex flex-1 flex-col justify-end" :title="`${d.date}: ${money(d.sold_net_value)} · ${money(d.wasted_value)}`" data-testid="mat-trend-day">
              <div class="bg-danger-text/70" :style="{ height: pct(d.wasted_value) }"></div>
              <div class="bg-brand" :style="{ height: pct(d.sold_net_value) }"></div>
            </div>
          </div>
          <p class="mt-1 text-[11px] text-muted-3">{{ t('reports.mat_trend_legend') }}</p>
        </section>
        <section class="flex flex-col gap-3 border-2 border-line bg-white px-4 py-3">
          <div data-testid="mat-top-used">
            <h3 class="mb-1 text-[13px] font-bold">{{ t('reports.mat_top_used') }}</h3>
            <p v-for="m in data.top_used" :key="m.material_id" class="flex justify-between text-[12.5px]"><span>{{ m.name }}</span><span class="tabular-nums">{{ money(m.value) }}</span></p>
            <p v-if="!data.top_used.length" class="text-[12px] text-muted-3">—</p>
          </div>
          <div data-testid="mat-top-wasted">
            <h3 class="mb-1 text-[13px] font-bold">{{ t('reports.mat_top_wasted') }}</h3>
            <p v-for="m in data.top_wasted" :key="m.material_id" class="flex justify-between text-[12.5px]"><span>{{ m.name }}</span><span class="tabular-nums">{{ money(m.value) }}</span></p>
            <p v-if="!data.top_wasted.length" class="text-[12px] text-muted-3">—</p>
          </div>
        </section>
      </div>

      <div class="overflow-x-auto border-2 border-line bg-white">
        <table class="w-full text-[13px]">
          <thead class="bg-surface-subtle text-left text-[11.5px] font-bold uppercase tracking-wide text-muted-4">
            <tr>
              <th class="px-3 py-2.5">{{ t('reports.mat_col_material') }}</th>
              <th v-if="showOutlet" class="px-3 py-2.5">{{ t('stock.col_outlet') }}</th>
              <th class="px-3 py-2.5 text-right">{{ t('reports.mat_col_sold') }}</th>
              <th class="px-3 py-2.5 text-right">{{ t('reports.mat_col_wasted') }}</th>
              <th class="px-3 py-2.5 text-right">{{ t('reports.mat_col_adjusted') }}</th>
              <th class="px-3 py-2.5 text-right">{{ t('reports.mat_col_purchased') }}</th>
              <th class="px-3 py-2.5 text-right">{{ t('reports.mat_col_transferred') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!data.data.length"><td :colspan="showOutlet ? 7 : 6" class="px-3 py-6 text-center text-muted-3">{{ t('reports.mat_usage_empty') }}</td></tr>
            <tr v-for="r in data.data" :key="`${r.material_id}:${r.outlet_id}`" class="border-t border-line" data-testid="mat-usage-row">
              <td class="px-3 py-2.5">
                <button type="button" class="font-semibold underline decoration-dotted hover:text-brand-active" @click="byMenu = r">{{ r.name }}</button>
                <span class="block font-mono text-[11.5px] text-muted-3">{{ r.code }} · {{ r.unit }}</span>
              </td>
              <td v-if="showOutlet" class="px-3 py-2.5 font-mono text-[12px]">{{ r.outlet_code }}</td>
              <td class="px-3 py-2.5 text-right tabular-nums">{{ qty(r.sold_net) }}<span class="block text-[11.5px] text-muted-3">{{ money(r.sold_net_value) }}</span></td>
              <td class="px-3 py-2.5 text-right tabular-nums" :class="Number(r.wasted) > 0 ? 'text-danger-text' : ''">{{ qty(r.wasted) }}<span class="block text-[11.5px] text-muted-3">{{ money(r.wasted_value) }}</span></td>
              <td class="px-3 py-2.5 text-right tabular-nums">+{{ qty(r.adjusted_in) }} / −{{ qty(r.adjusted_out) }}</td>
              <td class="px-3 py-2.5 text-right tabular-nums">{{ qty(r.purchased) }}</td>
              <td class="px-3 py-2.5 text-right tabular-nums">+{{ qty(r.transferred_in) }} / −{{ qty(r.transferred_out) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <MaterialUsageByMenuModal :material="byMenu" :date-from="dateFrom" :date-to="dateTo" :outlet-ids="outletIds" @close="byMenu = null" />
  </div>
</template>
