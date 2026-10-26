<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDRExact } from '../../utils/money';

// 076 — bukti keseimbangan: jumlah baris dibandingkan dengan total tersimpan.
// Semua angka datang dari server (SettlementReconciliation); kartu ini hanya
// menampilkannya, tidak menghitung apa pun.
const props = defineProps({
  reconciliation: { type: Object, required: true },
});

const { t } = useI18n();

const rows = computed(() => [
  { key: 'sources_sum', label: t('settlements.rec_sources_sum'), value: props.reconciliation.sources_sum },
  { key: 'adjustments_sum', label: t('settlements.rec_adjustments_sum'), value: props.reconciliation.adjustments_sum },
  { key: 'expected_net', label: t('settlements.rec_expected_net'), value: props.reconciliation.expected_net, strong: true },
  { key: 'net_amount', label: t('settlements.rec_net'), value: props.reconciliation.net_amount, strong: true },
]);
</script>

<template>
  <section class="rounded-card border border-line-2 bg-white p-4" :aria-label="t('settlements.reconciliation_title')" data-testid="reconciliation-card">
    <div class="mb-2.5 flex items-center justify-between gap-3">
      <h3 class="text-[12.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('settlements.reconciliation_title') }}</h3>
      <span
        class="rounded-full px-2.5 py-0.5 text-[11.5px] font-bold"
        :class="reconciliation.balanced ? 'bg-mint-200 text-brand-active' : 'bg-danger-bg text-danger-text'"
        data-testid="reconciliation-state"
      >
        {{ reconciliation.balanced ? t('settlements.rec_balanced') : t('settlements.rec_unbalanced') }}
      </span>
    </div>
    <dl class="flex flex-col gap-1.5 text-[13px]">
      <div v-for="row in rows" :key="row.key" class="flex items-center justify-between gap-3">
        <dt class="text-muted-4">{{ row.label }}</dt>
        <dd class="tabular-nums" :class="row.strong ? 'font-bold' : ''">{{ formatIDRExact(row.value) }}</dd>
      </div>
      <div class="flex items-center justify-between gap-3 border-t border-dashed border-line-2 pt-1.5">
        <dt class="font-bold">{{ t('settlements.rec_difference') }}</dt>
        <dd class="font-bold tabular-nums" :class="reconciliation.balanced ? 'text-brand-active' : 'text-danger-text'" data-testid="reconciliation-difference">
          {{ formatIDRExact(reconciliation.difference) }}
        </dd>
      </div>
    </dl>
  </section>
</template>
