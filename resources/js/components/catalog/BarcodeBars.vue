<script setup>
import { computed } from 'vue';
import { barRuns, totalModules, integerModulePx } from '../../utils/barcode';

/**
 * 049 — menggambar pola Code 128 dari server sebagai batang vektor yang tajam. SELALU gelap di atas
 * putih (token `ink`), tidak pernah warna aksen: pemindai butuh kontras itu, walau kartunya berwarna.
 * `crispEdges` + satu <path> menjaga tepi batang tegas saat dirasterisasi ke PDF. Kode terbaca
 * dicetak di bawahnya supaya bisa diketik ke POS bila pemindaian gagal; pola null (kode yang tak
 * bisa dibawa Code 128) menampilkan kode terbacanya saja.
 */
const props = defineProps({
  pattern: { type: String, default: null },
  text: { type: String, default: '' },
  width: { type: Number, default: 220 }, // lebar MAKSIMUM (piksel CSS), termasuk zona hening
  height: { type: Number, default: 40 },
  // Skala rasterisasi tempat barcode ini akan difoto (1 = layar; PDF memakai PDF_RASTER_SCALE). Menentukan lebar
  // modul bulat dalam piksel perangkat — lihat integerModulePx.
  scale: { type: Number, default: 1 },
});

const viewWidth = computed(() => (props.pattern ? totalModules(props.pattern) : 0));
const path = computed(() => (props.pattern ? barRuns(props.pattern).map((r) => `M${r.x} 0h${r.w}v1h-${r.w}z`).join('') : ''));
const modulePx = computed(() => (props.pattern ? integerModulePx(props.pattern, props.width, props.scale) : 0));
// Lebar tergambar = jumlah modul x lebar modul bulat (tidak melebihi `width`); barcode pendek dirender lebih lebar per modul.
const drawnWidth = computed(() => (viewWidth.value * modulePx.value) / props.scale);
// Dipakai tes dan perhitungan tata letak: lebar modul terkecil pada cetak A4 100% (mm).
const moduleMm = computed(() => (props.pattern ? (modulePx.value / props.scale) * (25.4 / 96) : null));
</script>

<template>
  <div class="inline-flex flex-col items-center gap-1 bg-white text-ink" data-testid="barcode" :data-module-mm="moduleMm?.toFixed(3)">
    <svg
      v-if="pattern"
      :width="drawnWidth"
      :height="height"
      :viewBox="`0 0 ${viewWidth} 1`"
      preserveAspectRatio="none"
      shape-rendering="crispEdges"
      role="img"
      :aria-label="text"
    >
      <rect :width="viewWidth" height="1" class="fill-white" />
      <path :d="path" fill="currentColor" />
    </svg>
    <span class="font-mono text-[11px] font-semibold tracking-wide">{{ text }}</span>
  </div>
</template>
