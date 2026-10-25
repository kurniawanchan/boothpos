<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatCount, formatDateLong } from '../../utils/formatters';
import KpiCard from '../page/KpiCard.vue';
import EmptyState from '../ui/EmptyState.vue';
import ErrorState from '../ui/ErrorState.vue';
import LoadingState from '../ui/LoadingState.vue';

/**
 * 043 (T033) — strip angka kepala halaman Produk: Menu item aktif · 86 hari ini · Belum dirilis · Kualitas data.
 * Bingkainya sama dengan KpiStrip/DashboardKpis (satu panel bergaris, sel dipisah garis), tetapi dirakit di sini
 * karena KpiStrip tetap 4 kolom: saat rilis menu mati (`summary.unreleased === null`) selnya TIGA dan strip harus
 * membagi lebar tiga, bukan menyisakan kolom kosong.
 *
 * Aturan:
 *  - Nol adalah nilai (0 tampil "0", tidak pernah "—"); angka yang tidak ada dari server tampil "tidak tersedia".
 *  - "86 hari ini" dan "Kualitas data" adalah tombol yang memancarkan `filter` ({ kind: 'sold_out' } /
 *    { kind: 'quality', quality }) — halaman yang mengubah daftar. Sel dengan nilai 0 tidak dibuat tombol: tidak ada
 *    yang bisa difilter.
 *  - Memuat / galat (+ Coba lagi) / kosong adalah keadaan wilayah ini saja; kegagalan ringkasan tidak mengosongkan daftar.
 *
 * @prop {object|null} summary  isi GET /products/summary
 * @prop {'loading'|'error'|'ready'} state
 * @prop {boolean} busy  070: sedang memuat ulang karena filter berubah — angka lama tetap tampil tetapi redup dan
 *   `aria-busy`, sama dengan daftar di bawahnya; tidak ada angka lama yang tampil tanpa tanda.
 */
const props = defineProps({
  summary: { type: Object, default: null },
  state: { type: String, default: 'loading' },
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(['retry', 'filter']);
const { t } = useI18n();

const QUALITY_LABELS = {
  no_station: 'products.quality_no_station',
  no_category: 'products.quality_no_category',
  no_price: 'products.quality_no_price',
  no_cost: 'products.quality_no_cost',
};

const hasData = computed(() => props.state === 'ready' && !!props.summary);

const cells = computed(() => {
  const s = props.summary ?? {};
  const list = [];

  const a = s.active;
  list.push({
    key: 'active',
    label: t('products.fig_active'),
    tone: 'success',
    value: a ? formatCount(a.count) : null,
    note: a ? t('products.fig_active_note', { total: formatCount(a.total), draft: formatCount(a.draft), discontinued: formatCount(a.discontinued) }) : '',
  });

  const so = s.sold_out_today;
  const soExamples = (so?.examples ?? []).map((e) => (e.outlet ? `${e.product} · ${e.outlet}` : e.product));
  list.push({
    key: 'sold_out',
    label: t('products.fig_sold_out'),
    tone: 'danger',
    value: so ? formatCount(so.count) : null,
    note: !so ? '' : so.count === 0 ? t('products.fig_sold_out_none') : soExamples.join(', '),
    clickable: !!so && so.count > 0,
  });

  // Rilis mati -> `unreleased` null: sel disembunyikan (strip tiga sel), bukan ditampilkan sebagai 0.
  if (s.unreleased !== null && s.unreleased !== undefined) {
    const u = s.unreleased;
    list.push({
      key: 'unreleased',
      label: t('products.fig_unreleased'),
      tone: 'warning',
      value: formatCount(u.count),
      note: u.active_version
        ? t('products.fig_unreleased_note', { version: u.active_version, date: u.active_since ? formatDateLong(u.active_since) : '—' })
        : t('products.fig_unreleased_no_release'),
    });
  }

  const q = s.quality;
  const qLabel = q?.kind && QUALITY_LABELS[q.kind] ? t(QUALITY_LABELS[q.kind]) : '';
  list.push({
    key: 'quality',
    label: t('products.fig_quality'),
    tone: 'info',
    value: q ? formatCount(q.count) : null,
    note: !q ? '' : q.count === 0 ? t('products.fig_quality_ok') : qLabel ? `${qLabel}: ${(q.examples ?? []).join(', ')}` : (q.examples ?? []).join(', '),
    clickable: !!q && q.count > 0 && !!q.kind,
  });
  return list;
});

// Tiga sel membagi lebar tiga dari lebar `sm`; empat sel 2x2 dulu lalu satu baris di layar lebar. Sel ganjil
// di grid 2 kolom akan menyisakan kotak abu-abu (warna garis pemisah) — itu sebabnya tiga sel tidak memakai 2 kolom.
const gridCols = computed(() => (cells.value.length === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2 xl:grid-cols-4'));

function onSelect(key) {
  if (key === 'sold_out') emit('filter', { kind: 'sold_out' });
  else if (key === 'quality') emit('filter', { kind: 'quality', quality: props.summary?.quality?.kind });
}
</script>

<template>
  <section class="mx-[26px] mb-5 border-2 border-line bg-white" :class="busy ? 'opacity-60' : ''" :aria-label="t('products.fig_region')" :aria-busy="busy ? 'true' : 'false'" data-testid="product-figures">
    <LoadingState v-if="state === 'loading'" :rows="3" />
    <ErrorState v-else-if="state === 'error'" :message="t('products.fig_load_failed')" @retry="emit('retry')" />
    <EmptyState v-else-if="!hasData" icon="ph-chart-bar" :message="t('products.fig_load_failed')" />
    <div v-else class="grid grid-cols-1 gap-px bg-line" :class="gridCols">
      <KpiCard
        v-for="cell in cells"
        :key="cell.key"
        :data-testid="`figure-${cell.key}`"
        :label="cell.label"
        :value="cell.value"
        :note="cell.note"
        :tone="cell.tone"
        :clickable="cell.clickable"
        @select="onSelect(cell.key)"
      />
    </div>
  </section>
</template>
