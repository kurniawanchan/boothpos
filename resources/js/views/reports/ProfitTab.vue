<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import EmptyState from '../../components/ui/EmptyState.vue';
import KpiCard from '../../components/page/KpiCard.vue';

// Tab "Modal & Untung" (owner/admin saja). Lima angka — bukan empat — jadi memakai KpiCard dalam grid
// sendiri, bukan KpiStrip (yang selalu empat). Rincian POS · Pre-order tampil sebagai catatan kartu hanya
// bila respons membawanya (respons lama tanpa rincian tidak menampilkan sub-baris); biaya event dan laba
// bersih milik seluruh event, tidak dipisah.
const props = defineProps({
  profit: { type: Object, default: null },
  eventId: { type: [String, Number], default: '' },
});
const { t } = useI18n();

function split(pos, preorder) {
  return pos != null ? t('reports.split_line', { pos: formatIDR(pos), preorder: formatIDR(preorder) }) : '';
}

const cards = computed(() => {
  const p = props.profit;
  if (!p) return [];
  return [
    { key: 'revenue', tone: 'info', label: t('reports.revenue'), value: formatIDR(p.revenue), note: split(p.revenue_pos, p.revenue_preorder) },
    { key: 'cogs', tone: 'neutral', label: t('reports.cost_of_goods'), value: formatIDR(p.cost_of_goods), note: split(p.cost_of_goods_pos, p.cost_of_goods_preorder) },
    { key: 'gross', tone: 'success', label: t('reports.gross_profit'), value: formatIDR(p.gross_profit), note: split(p.gross_profit_pos, p.gross_profit_preorder) },
    { key: 'event_cost', tone: 'warning', label: t('reports.event_cost'), value: formatIDR(p.event_cost), note: '' },
    { key: 'net', tone: 'accent', label: t('reports.net_profit'), value: formatIDR(p.net_profit), note: '' },
  ];
});
</script>

<template>
  <EmptyState v-if="!eventId" icon="ph-calendar-dots" :message="t('reports.pick_event_for_profit')" />
  <div v-else-if="profit" class="mx-5 grid grid-cols-1 gap-px border-2 border-line bg-line sm:grid-cols-2 xl:grid-cols-5">
    <KpiCard v-for="c in cards" :key="c.key" :label="c.label" :value="c.value" :note="c.note" :tone="c.tone" />
  </div>
  <p class="px-5 pb-5 pt-4 text-[11.5px] leading-relaxed text-muted-3">{{ t('reports.profit_snapshot_note') }}</p>
</template>
