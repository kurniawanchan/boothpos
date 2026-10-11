<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR, formatCount } from '../../utils/formatters';
import ChangeChip from '../page/ChangeChip.vue';
import TrendLine from '../page/TrendLine.vue';
import EmptyState from '../ui/EmptyState.vue';
import ErrorState from '../ui/ErrorState.vue';
import LoadingState from '../ui/LoadingState.vue';

/**
 * 042-dashboard-revamp (US1) — empat angka utama Dashboard: label, catatan satuan, nilai, chip perubahan dan garis
 * tren. Bingkainya SAMA dengan KpiStrip (satu panel bergaris, sel dipisah garis 1px, 2×2 → 1 baris), tetapi selnya
 * dibuat di sini: KpiCard milik kit 041 hanya punya label/nilai/catatan dan tidak menyediakan tempat untuk chip dan
 * garis tren, dan kit tidak boleh diubah dari fitur ini.
 *
 * Aturan tampilan (spec US1 skenario 4, edge cases):
 *  - NOL adalah nilai: 0 / Rp 0 tampil apa adanya, tidak pernah "—".
 *  - Angka yang sengaja DIHILANGKAN server (peran tak boleh melihatnya — "omitted, never zero") atau yang belum
 *    ada tampil "tidak tersedia", bukan 0. Bedanya: kunci ada dengan `value: null` (rata-rata order tanpa order
 *    selesai) tetap dibaca sebagai nol.
 *  - Tiap wilayah punya keadaan sendiri (memuat / galat + Coba lagi / kosong) sehingga kegagalan ringkasan tidak
 *    mengosongkan bagian halaman lain; komponen hanya memancarkan `retry`.
 *
 * @prop {object|null} summary   isi GET /dashboard/summary
 * @prop {'loading'|'error'|'ready'} state
 * @prop {boolean} outletSelected  true bila satu outlet dipilih (Stage B) -> catatan "seluruh organisasi" pada stok menipis
 */
const props = defineProps({
  summary: { type: Object, default: null },
  state: { type: String, default: 'loading' },
  outletSelected: { type: Boolean, default: false },
});
defineEmits(['retry']);
const { t } = useI18n();

const money = (v) => formatIDR(v);
const count = (v) => formatCount(v);

// Definisi sel: bentuk nilai, jenis perubahan, dan apakah kenaikan itu baik. Stok menipis: naik = buruk (merah).
const DEFS = [
  { key: 'net_sales', tone: 'brand', fmt: money, change: 'change_pct', kind: 'percent', goodWhen: 'up' },
  { key: 'orders', tone: 'brand', fmt: count, change: 'change_pct', kind: 'percent', goodWhen: 'up' },
  { key: 'avg_order_value', tone: 'brand', fmt: money, change: 'change_pct', kind: 'percent', goodWhen: 'up' },
  { key: 'low_stock', tone: 'danger', fmt: count, change: 'change_abs', kind: 'items', goodWhen: 'down' },
];
const LABELS = {
  net_sales: ['dashboard.kpi_net_sales', 'dashboard.kpi_net_sales_note'],
  orders: ['dashboard.kpi_orders', 'dashboard.kpi_orders_note'],
  avg_order_value: ['dashboard.kpi_aov', 'dashboard.kpi_aov_note'],
  low_stock: ['dashboard.kpi_low_stock', 'dashboard.kpi_low_stock_note'],
};

// "12:30" -> "12.30" (format jam desain). Tanpa up_to (periode 7/30 hari) tidak dipakai.
const compareText = computed(() => {
  const c = props.summary?.comparison;
  if (c?.basis === 'same_time_yesterday') {
    return c.up_to ? t('dashboard.vs_yesterday_until', { time: String(c.up_to).replace(':', '.') }) : t('dashboard.vs_yesterday');
  }
  return t('dashboard.vs_previous_period');
});

const cells = computed(() => {
  const kpis = props.summary?.kpis ?? {};
  return DEFS.map((def) => {
    const kpi = kpis[def.key];
    const present = kpi !== undefined && kpi !== null && kpi.value !== undefined;
    const raw = present ? kpi.value : null;
    const [labelKey, noteKey] = LABELS[def.key];
    const change = kpi?.[def.change];
    return {
      key: def.key,
      label: t(labelKey),
      note: t(noteKey),
      present,
      // value null pada kunci yang ada = "belum ada pembagi" -> nol, bukan hilang.
      shown: present ? def.fmt(raw ?? 0) : '',
      change: typeof change === 'number' ? change : null,
      kind: def.kind,
      goodWhen: def.goodWhen,
      tone: def.tone,
      series: kpi?.series ?? [],
      comparison: kpi?.comparison_series ?? [],
      format: def.fmt,
      orgScope: def.key === 'low_stock' && kpi?.scope === 'organisation' && props.outletSelected,
    };
  });
});

const empty = computed(() => props.state === 'ready' && !props.summary?.kpis);
</script>

<template>
  <section class="mx-[26px] mb-5 border-2 border-line bg-white" :aria-label="t('dashboard.kpi_region_label')" data-testid="dashboard-kpis">
    <LoadingState v-if="state === 'loading'" :rows="4" />
    <ErrorState v-else-if="state === 'error'" :message="t('dashboard.kpi_load_failed')" @retry="$emit('retry')" />
    <EmptyState v-else-if="empty" icon="ph-chart-line-up" :message="t('dashboard.kpi_empty')" />
    <div v-else class="grid grid-cols-1 gap-px bg-line sm:grid-cols-2 xl:grid-cols-4">
      <div
        v-for="cell in cells"
        :key="cell.key"
        :data-testid="`kpi-${cell.key}`"
        class="flex min-w-0 flex-col gap-2 bg-white px-[20px] py-[18px]"
      >
        <div class="flex items-baseline justify-between gap-2">
          <span class="text-[13px] font-semibold text-muted-5">{{ cell.label }}</span>
          <span class="text-right text-[11.5px] text-muted">{{ cell.note }}</span>
        </div>
        <!-- flex-wrap: bila nilai uang panjang (mis. Rp 18.420.500) tidak muat di samping garis tren, garis turun ke baris berikutnya — nilai TIDAK boleh dipotong (…), itu angka utama halaman. -->
        <div class="flex flex-wrap items-end justify-between gap-x-3 gap-y-2">
          <div class="flex min-w-0 flex-col gap-2">
            <span v-if="!cell.present" class="text-[26px] font-bold leading-tight text-muted-3">—<span class="sr-only">{{ t('page.unavailable') }}</span></span>
            <span v-else class="whitespace-nowrap text-[26px] font-extrabold leading-tight tracking-tight tabular-nums" data-testid="kpi-value">{{ cell.shown }}</span>
            <div v-if="cell.present" class="flex flex-wrap items-center gap-x-2 gap-y-1">
              <ChangeChip :value="cell.change" :kind="cell.kind" :good-when="cell.goodWhen" />
              <span v-if="cell.change !== null" class="text-[12px] text-muted">{{ compareText }}</span>
            </div>
            <span v-if="cell.orgScope" class="text-[11.5px] font-semibold text-muted-4" data-testid="kpi-org-scope">{{ t('dashboard.kpi_org_scope') }}</span>
          </div>
          <TrendLine
            v-if="cell.present"
            :series="cell.series"
            :comparison="cell.comparison"
            :tone="cell.tone"
            :label="cell.label"
            :format="cell.format"
          />
        </div>
      </div>
    </div>
  </section>
</template>
