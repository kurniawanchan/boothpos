<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { basePriceText } from '../../utils/itemDetail';

/**
 * 043 (US2) — grid fakta item: Nama pendek, Kategori pajak, Station (dengan catatan "mengikuti kategori" bila
 * station diwarisi), Estimasi persiapan, Harga dasar, Rilis menu (hanya bila rilis aktif di instalasi ini).
 * Nilai kosong tampil "—" (bukan 0 atau kosong): artinya "belum diisi", sama dengan penanda kualitas data di daftar.
 */
const props = defineProps({
  product: { type: Object, required: true },
});
const { t } = useI18n();

const facts = computed(() => {
  const p = props.product;
  const rows = [
    { k: 'short', label: t('products.fact_short_name'), value: p.short_name || '—' },
    { k: 'tax', label: t('products.fact_tax'), value: p.tax_category ? t(`products.tax_${p.tax_category}`) : t('products.tax_unset') },
    {
      k: 'station',
      label: t('products.fact_station'),
      value: p.station?.name ?? '—',
      note: p.station?.source === 'category' ? t('products.fact_station_from_category') : '',
    },
    { k: 'prep', label: t('products.fact_prep'), value: p.prep_minutes === null || p.prep_minutes === undefined ? '—' : t('products.fact_prep_value', { n: p.prep_minutes }) },
    { k: 'price', label: t('products.fact_price'), value: basePriceText(p.variants) },
  ];
  // Rilis menu hanya bermakna saat rilis aktif; server mengirim `release: null` saat mati -> kartu tidak dirender.
  if (p.release) rows.push({ k: 'release', label: t('products.fact_release'), value: t('products.fact_release_value', { version: p.release.version }) });
  return rows;
});
</script>

<template>
  <dl class="grid grid-cols-2 gap-px border-2 border-line bg-line sm:grid-cols-3 lg:grid-cols-6" data-testid="item-facts">
    <div v-for="f in facts" :key="f.k" class="flex flex-col gap-1 bg-white px-4 py-3" :data-testid="`fact-${f.k}`">
      <dt class="text-[11px] font-bold uppercase tracking-wide text-muted">{{ f.label }}</dt>
      <dd class="text-[14px] font-semibold tabular-nums">{{ f.value }}</dd>
      <dd v-if="f.note" class="text-[11.5px] text-muted">{{ f.note }}</dd>
    </div>
  </dl>
</template>
