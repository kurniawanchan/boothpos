<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import KpiCard from '../../components/page/KpiCard.vue';

// Tab "Modal & Untung" (owner/admin saja) untuk satu PERIODE tanggal bisnis. Tiga angka dari penjualan
// selesai saja (046: tanpa pre-order, tanpa biaya event / laba bersih).
const props = defineProps({
  profit: { type: Object, default: null },
});
const { t } = useI18n();

const cards = computed(() => {
  const p = props.profit;
  if (!p) return [];
  return [
    { key: 'revenue', tone: 'info', label: t('reports.revenue'), value: formatIDR(p.revenue) },
    { key: 'cogs', tone: 'neutral', label: t('reports.cost_of_goods'), value: formatIDR(p.cost_of_goods) },
    { key: 'gross', tone: 'success', label: t('reports.gross_profit'), value: formatIDR(p.gross_profit) },
  ];
});
</script>

<template>
  <div v-if="profit" class="mx-5 grid grid-cols-1 gap-px border-2 border-line bg-line sm:grid-cols-3">
    <KpiCard v-for="c in cards" :key="c.key" :label="c.label" :value="c.value" :tone="c.tone" />
  </div>
  <p class="px-5 pb-5 pt-4 text-[11.5px] leading-relaxed text-muted-3">{{ t('reports.profit_snapshot_note') }}</p>
</template>
