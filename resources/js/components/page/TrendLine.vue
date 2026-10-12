<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * 042-dashboard-revamp — garis tren kecil untuk kartu KPI. SVG sebaris, tanpa pustaka grafik (supaya tidak
 * menambah bobot bundel utama dan chart.js tetap hanya di potongan "Rincian periode").
 *
 * Kontrak:
 *  - `series` = garis NYATA (padat) dan berhenti di titik terakhir yang ada; `comparison` = garis pembanding
 *    (putus-putus) yang menjangkau seluruh lebar. Keduanya memakai SATU skala (sumbu x = indeks titik terpanjang,
 *    sumbu y = min–maks gabungan) supaya pembandingnya jujur, bukan diskalakan sendiri-sendiri. Tidak ada
 *    proyeksi yang dikarang: bagian yang belum terjadi dibiarkan kosong.
 *  - Warna hanya lewat `currentColor` dan token (tone brand | danger); tanpa hex.
 *  - Arah tidak hanya dikomunikasikan lewat warna: role="img" dengan aria-label berisi nilai pertama, terakhir,
 *    dan arah (naik/turun/datar). Seri kosong atau satu titik menggambar garis datar tanpa galat.
 *
 * @prop {{t: string, v: string|number}[]} series
 * @prop {{t: string, v: string|number}[]} comparison
 * @prop {(v: number) => string} format  pemformat nilai untuk teks alternatif (bawaan: bilangan id-ID)
 */
const props = defineProps({
  series: { type: Array, default: () => [] },
  comparison: { type: Array, default: () => [] },
  tone: { type: String, default: 'brand' }, // brand | danger
  label: { type: String, default: '' },
  format: { type: Function, default: (v) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(v) },
});
const { t } = useI18n();

const W = 120;
const H = 40;
const PAD = 3;

const num = (p) => {
  const n = Number(p?.v);
  return Number.isFinite(n) ? n : 0;
};
const actual = computed(() => props.series.map(num));
const compare = computed(() => props.comparison.map(num));

const span = computed(() => Math.max(actual.value.length, compare.value.length, 1));
const bounds = computed(() => {
  const all = [...actual.value, ...compare.value];
  if (!all.length) return { min: 0, max: 0 };
  return { min: Math.min(...all), max: Math.max(...all) };
});

function pathFor(values) {
  if (!values.length) return '';
  const { min, max } = bounds.value;
  const range = max - min;
  const stepX = span.value > 1 ? (W - PAD * 2) / (span.value - 1) : 0;
  const y = (v) => (range === 0 ? H / 2 : H - PAD - ((v - min) / range) * (H - PAD * 2));
  // Satu titik tak bisa membentuk garis: gambar segmen datar selebar bidang supaya tetap terbaca sebagai tren kosong.
  if (values.length === 1) return `M${PAD} ${y(values[0]).toFixed(1)} L${W - PAD} ${y(values[0]).toFixed(1)}`;
  return values.map((v, i) => `${i ? 'L' : 'M'}${(PAD + i * stepX).toFixed(1)} ${y(v).toFixed(1)}`).join(' ');
}

const actualPath = computed(() => pathFor(actual.value));
const comparePath = computed(() => pathFor(compare.value));
// Tidak ada data sama sekali -> satu garis datar di tengah (placeholder) alih-alih SVG kosong.
const placeholderPath = `M${PAD} ${H / 2} L${W - PAD} ${H / 2}`;
const hasLine = computed(() => actual.value.length > 1);

const direction = computed(() => {
  if (actual.value.length < 2) return 'flat';
  const delta = actual.value[actual.value.length - 1] - actual.value[0];
  if (delta > 0) return 'up';
  if (delta < 0) return 'down';
  return 'flat';
});

const ariaLabel = computed(() => {
  const base = props.label || t('dashboard.trend_default_label');
  if (actual.value.length < 2) return t('dashboard.trend_no_data', { label: base });
  return t('dashboard.trend_summary', {
    label: base,
    first: props.format(actual.value[0]),
    last: props.format(actual.value[actual.value.length - 1]),
    direction: t(`dashboard.trend_${direction.value}`),
  });
});

const toneClass = computed(() => (props.tone === 'danger' ? 'text-marker-danger' : 'text-brand'));
</script>

<template>
  <svg
    role="img"
    :aria-label="ariaLabel"
    :viewBox="`0 0 ${W} ${H}`"
    preserveAspectRatio="none"
    class="h-10 w-[120px] flex-none overflow-visible"
    :class="toneClass"
    fill="none"
  >
    <!-- vector-effect: tebal garis tetap 2px walau SVG diregangkan oleh preserveAspectRatio="none". -->
    <path
      v-if="comparePath"
      data-testid="trend-comparison"
      :d="comparePath"
      class="text-muted-3"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-dasharray="3 3"
      stroke-linecap="round"
      stroke-linejoin="round"
      vector-effect="non-scaling-stroke"
    />
    <path
      v-if="hasLine"
      data-testid="trend-actual"
      :d="actualPath"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      vector-effect="non-scaling-stroke"
    />
    <path
      v-if="!hasLine && !comparePath"
      data-testid="trend-placeholder"
      :d="placeholderPath"
      class="text-disabled"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
      vector-effect="non-scaling-stroke"
    />
  </svg>
</template>
