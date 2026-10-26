<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import StatusPill from '../ui/StatusPill.vue';

// 076 — satu pemetaan status tampilan → warna & teks untuk daftar dan detail.
const props = defineProps({
  status: { type: String, required: true }, // draft | finalized | acknowledged | partially_paid | settled
});

const { t } = useI18n();
const VARIANT = { draft: 'neutral', finalized: 'warn', acknowledged: 'mint', partially_paid: 'warn', settled: 'dark' };
const variant = computed(() => VARIANT[props.status] ?? 'neutral');
</script>

<template>
  <StatusPill :variant="variant">{{ t(`settlements.status_${status}`) }}</StatusPill>
</template>
