<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/formatters';
import { revenueBreakdown } from '../../utils/revenueBreakdown';

/**
 * 048 — Net, Diskon tingkat order, Pajak, dan Bruto berdampingan. Dipakai di laporan penjualan dan Dashboard.
 * Tidak memakai store: label pajak dikirim induk (induk sudah punya Pinia), supaya komponen ini bisa dirender
 * tanpa Pinia di tes.
 *
 * @prop {object|null} revenue   totals dari GET /reports/sales, atau revenue dari GET /dashboard/summary
 * @prop {string} taxLabel       label pajak dari settings (mis. "PPN"); kosong = "Pajak"
 */
const props = defineProps({
  revenue: { type: Object, required: true },
  taxLabel: { type: String, default: '' },
});
const { t } = useI18n();

const lines = computed(() => {
  const b = revenueBreakdown(props.revenue);
  return [
    { key: 'net', label: t('reports.revenue_net'), value: b.net },
    { key: 'order-discount', label: t('reports.revenue_order_discount'), value: b.orderDiscount },
    { key: 'tax', label: props.taxLabel || t('reports.revenue_tax'), value: b.tax },
    { key: 'gross', label: t('reports.revenue_gross'), value: b.gross, emphasis: true },
  ];
});
</script>

<template>
  <div class="grid grid-cols-2 gap-px border-2 border-line bg-line md:grid-cols-4" data-testid="revenue-breakdown">
    <div v-for="line in lines" :key="line.key" class="flex min-w-0 flex-col gap-1 bg-white px-[20px] py-[14px]" :data-testid="`revenue-${line.key}`">
      <span class="text-[12.5px] font-semibold text-muted-5">{{ line.label }}</span>
      <span class="whitespace-nowrap text-[20px] font-extrabold tabular-nums tracking-tight" :class="line.emphasis ? 'text-brand-active' : ''">{{ formatIDR(line.value) }}</span>
    </div>
  </div>
</template>
