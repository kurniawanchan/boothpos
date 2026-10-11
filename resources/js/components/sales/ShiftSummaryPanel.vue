<script setup>
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';

// Dipecah dari SalesView.vue (041, US2) tanpa mengubah perilaku: ringkasan kas SATU shift, hanya dirender
// pemanggil saat tepat satu shift dipilih. Angka dihitung di composables/useSalesFilters.js (shiftSummary);
// komponen ini murni tampilan. Struktur label→nilai dipertahankan karena tes membaca pasangan itu.
defineProps({
  summary: { type: Object, required: true },
});
const { t } = useI18n();
</script>

<template>
  <div data-testid="shift-summary" class="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-t-2 border-line bg-surface-subtle px-5 py-3 text-[12.5px]">
    <span class="font-bold">{{ t('reports.shift_summary_title') }}</span>
    <span><span class="text-muted-3">{{ t('reports.shift_opening_cash') }}</span> <strong>{{ formatIDR(summary.opening) }}</strong></span>
    <span><span class="text-muted-3">{{ t('reports.shift_cash_sales') }}</span> <strong>{{ formatIDR(summary.cashSales) }}</strong></span>
    <span><span class="text-muted-3">{{ t('reports.shift_expected_cash') }}</span> <strong>{{ formatIDR(summary.expected) }}</strong></span>
    <template v-if="summary.counted !== null">
      <span><span class="text-muted-3">{{ t('reports.shift_counted_cash') }}</span> <strong>{{ formatIDR(summary.counted) }}</strong></span>
      <span><span class="text-muted-3">{{ t('reports.shift_difference') }}</span> <strong :class="summary.difference === 0 ? '' : 'text-danger-text'">{{ formatIDR(summary.difference) }}</strong></span>
    </template>
  </div>
</template>
