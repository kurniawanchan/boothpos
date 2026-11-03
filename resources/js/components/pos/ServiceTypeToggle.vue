<script setup>
import { useI18n } from 'vue-i18n';

// 090 / US5 — pilihan Dine-In / Take Away untuk transaksi POS web. Dua segmen berdampingan; bawaan Dine-In (diatur pemanggil).
// Pola radiogroup: satu pilihan aktif, teks selalu tampil. Nilai sama dengan server (`dine_in` | `takeaway`).
defineProps({ modelValue: { type: String, default: 'dine_in' } });
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const options = [
  { value: 'dine_in', label: 'pos.service_dine_in', icon: 'ph-fork-knife' },
  { value: 'takeaway', label: 'pos.service_takeaway', icon: 'ph-bag' },
];
</script>

<template>
  <div role="radiogroup" :aria-label="t('pos.service_type')" class="grid grid-cols-2 border border-line bg-white" data-testid="service-type-toggle">
    <button
      v-for="o in options"
      :key="o.value"
      type="button"
      role="radio"
      :aria-checked="modelValue === o.value"
      :data-testid="`service-${o.value}`"
      class="flex items-center justify-center gap-1.5 px-3 py-2 text-[12.5px] font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand"
      :class="modelValue === o.value ? 'bg-brand text-white' : 'text-muted-5 hover:bg-line-7'"
      @click="emit('update:modelValue', o.value)"
    >
      <i class="ph-duotone text-[14px]" :class="o.icon" aria-hidden="true"></i>
      {{ t(o.label) }}
    </button>
  </div>
</template>
