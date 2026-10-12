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

// 045 Stage 3 — lebih dari 4 outlet diringkas jadi hitungan per keadaan (daftar lengkap ada di halaman item).
const COLLAPSE_ABOVE = 4;
const TONE = { available: 'success', sold_out: 'danger', not_carried: 'neutral' };
const ICON = { available: 'ph-check-circle', sold_out: 'ph-prohibit', not_carried: 'ph-minus-circle' };
const LABEL = { available: 'products.chip_available', sold_out: 'products.chip_sold_out', not_carried: 'products.chip_not_carried' };
const SUMMARY = { available: 'products.chips_count_available', sold_out: 'products.chips_count_sold_out', not_carried: 'products.chips_count_not_carried' };

function summarize(list) {
  return ['available', 'sold_out', 'not_carried']
    .map((state) => ({ state, items: list.filter((a) => a.state === state) }))
    .filter((g) => g.items.length > 0)
    .map((g) => ({ ...g, title: g.items.map((a) => a.outlet_code || a.outlet_name).filter(Boolean).join(', ') }));
}
</script>

<template>
  <span v-if="!availability?.length" class="text-[12.5px] text-muted-3" data-testid="availability-none">—<span class="sr-only">{{ t('page.unavailable') }}</span></span>
  <span v-else-if="availability.length > COLLAPSE_ABOVE" class="flex flex-wrap items-center gap-1.5" data-testid="availability-summary">
    <StatusPill v-for="g in summarize(availability)" :key="g.state" :variant="TONE[g.state]" :title="g.title" data-testid="availability-summary-chip">
      <i class="ph-duotone text-[12px]" :class="ICON[g.state]" aria-hidden="true"></i>
      {{ t(SUMMARY[g.state], { n: g.items.length }) }}
    </StatusPill>
  </span>
  <span v-else class="flex flex-wrap items-center gap-1.5" data-testid="availability-chips">
    <StatusPill
      v-for="(a, i) in availability"
      :key="a.outlet_id ?? `all-${i}`"
      :variant="TONE[a.state] ?? 'neutral'"
      :title="a.state === 'sold_out' && a.reason ? a.reason : undefined"
      data-testid="availability-chip"
    >
      <i class="ph-duotone text-[12px]" :class="ICON[a.state] ?? ICON.available" aria-hidden="true"></i>
      <span v-if="a.outlet_code" class="font-mono">{{ a.outlet_code }}</span>
      {{ t(LABEL[a.state] ?? LABEL.available) }}
    </StatusPill>
  </span>
</template>
