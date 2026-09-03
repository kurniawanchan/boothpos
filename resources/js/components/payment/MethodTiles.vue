<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({
  modelValue: { type: String, required: true }, // cash | bank_transfer | qr_ewallet
  allowCash: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue']);

const methods = computed(() => [
  { value: 'cash', icon: 'ph-money', label: t('pos.method_cash'), hint: t('pos.method_cash_hint') },
  { value: 'bank_transfer', icon: 'ph-bank', label: t('pos.method_transfer'), hint: t('pos.method_transfer_hint') },
  { value: 'qr_ewallet', icon: 'ph-qr-code', label: t('pos.method_qris'), hint: t('pos.method_qris_hint') },
]);
</script>

<template>
  <div role="radiogroup" :aria-label="t('pos.payment_method')" class="grid grid-cols-3 gap-2.5">
    <button
      v-for="m in methods"
      :key="m.value"
      type="button"
      role="radio"
      :aria-checked="modelValue === m.value"
      :disabled="m.value === 'cash' && !allowCash"
      class="flex flex-col items-center gap-1.5 rounded-lg border px-3 py-4 text-center transition-colors disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-mint-100"
      :class="
        modelValue === m.value
          ? 'border-brand bg-mint-50 text-brand-active'
          : 'border-line bg-white text-muted-5 hover:border-brand'
      "
      @click="emit('update:modelValue', m.value)"
    >
      <i class="ph-duotone text-[26px]" :class="m.icon" aria-hidden="true"></i>
      <span class="text-[13px] font-bold">{{ m.label }}</span>
      <span class="text-[11px] text-muted-3">{{ m.hint }}</span>
    </button>
  </div>
</template>
