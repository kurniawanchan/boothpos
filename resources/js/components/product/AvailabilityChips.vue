<script setup>
import { useI18n } from 'vue-i18n';
import StatusPill from '../ui/StatusPill.vue';

/**
 * 043-products-page-redesign (T019) — ketersediaan item sebagai chip, satu per entri `availability`
 * ({ outlet_id, outlet_code, state: 'available'|'sold_out', reason }). Tahap A: satu entri untuk seluruh instalasi
 * (outlet_code null) -> chip "Ada" / "86" saja; tahap B: satu chip per outlet berawalan kodenya ("WJM2 86").
 * Status TIDAK pernah hanya warna: teks ("Ada"/"86") plus ikon yang berbeda bentuk (centang vs. larangan).
 * Alasan 86 (bila server mengirim) menjadi tooltip native (`title`) — pelengkap, bukan satu-satunya informasi.
 */
defineProps({
  availability: { type: Array, default: () => [] },
});
const { t } = useI18n();
</script>

<template>
  <span v-if="!availability?.length" class="text-[12.5px] text-muted-3" data-testid="availability-none">—<span class="sr-only">{{ t('page.unavailable') }}</span></span>
  <span v-else class="flex flex-wrap items-center gap-1.5" data-testid="availability-chips">
    <StatusPill
      v-for="(a, i) in availability"
      :key="a.outlet_id ?? `all-${i}`"
      :variant="a.state === 'sold_out' ? 'danger' : 'success'"
      :title="a.state === 'sold_out' && a.reason ? a.reason : undefined"
      data-testid="availability-chip"
    >
      <i class="ph-duotone text-[12px]" :class="a.state === 'sold_out' ? 'ph-prohibit' : 'ph-check-circle'" aria-hidden="true"></i>
      <span v-if="a.outlet_code" class="font-mono">{{ a.outlet_code }}</span>
      {{ a.state === 'sold_out' ? t('products.chip_sold_out') : t('products.chip_available') }}
    </StatusPill>
  </span>
</template>
