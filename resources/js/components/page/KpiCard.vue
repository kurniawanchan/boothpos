<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * 041-back-office-ui-redesign (US2) — satu angka ringkasan: penanda berwarna, label, nilai besar, catatan satu baris.
 * Nilai yang TIDAK bisa dihitung ditampilkan "—" (bukan 0): angka nol yang salah lebih menyesatkan daripada
 * angka yang hilang (spec: "a figure that cannot be computed shows an explicit unavailable, never zero").
 * Nilai sudah diformat pemanggil (Rp, titik ribuan) — komponen tidak menebak format.
 */
const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], default: null },
  note: { type: String, default: '' },
  tone: { type: String, default: 'neutral' }, // neutral | info | accent | success | warning | danger
  unavailable: { type: Boolean, default: false },
  clickable: { type: Boolean, default: false },
});
defineEmits(['select']);
const { t } = useI18n();

const MARKERS = {
  neutral: 'bg-marker-neutral',
  info: 'bg-marker-info',
  accent: 'bg-marker-accent',
  success: 'bg-marker-success',
  warning: 'bg-marker-warning',
  danger: 'bg-marker-danger',
};
const marker = computed(() => MARKERS[props.tone] ?? MARKERS.neutral);
const missing = computed(() => props.unavailable || props.value === null || props.value === undefined || props.value === '');
</script>

<template>
  <component
    :is="clickable ? 'button' : 'div'"
    :type="clickable ? 'button' : undefined"
    class="flex flex-col gap-1.5 bg-white px-[20px] py-[18px] text-left"
    :class="clickable ? 'cursor-pointer transition-colors hover:bg-mint-50 focus-visible:bg-mint-50 focus-visible:outline-none' : ''"
    @click="clickable && $emit('select')"
  >
    <span class="flex items-center gap-2 text-[12px] font-semibold text-muted-4">
      <span class="h-2 w-2 flex-none" :class="marker" aria-hidden="true"></span>
      {{ label }}
    </span>
    <span v-if="missing" class="text-[24px] font-bold leading-tight text-muted-3" :title="t('page.unavailable')">—<span class="sr-only">{{ t('page.unavailable') }}</span></span>
    <span v-else class="text-[24px] font-bold leading-tight tabular-nums">{{ value }}</span>
    <span v-if="note" class="text-[12px] text-muted">{{ note }}</span>
  </component>
</template>
