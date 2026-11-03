<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import SelfSheet from './SelfSheet.vue';
import { formatIDR } from '../../utils/money';

// 090 — keranjang di lembar bawah: ubah jumlah, hapus, pilih Dine-In/Take Away, ringkasan total. TANPA diskon dalam bentuk
// apa pun (spec FR-006); pajak hanya tampil bila server memberi tahu ada pajak berlaku.
const props = defineProps({
  open: { type: Boolean, default: false },
  lines: { type: Array, required: true },
  totals: { type: Object, required: true },
  serviceType: { type: String, default: 'dine_in' },
  tax: { type: Object, default: () => ({ label: '', rate: 0, show: false }) },
  unavailable: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'qty', 'remove', 'service-type', 'continue']);
const { t } = useI18n();

const unavailableIds = computed(() => new Set(props.unavailable.map((u) => u.variant_id)));
const showTax = computed(() => props.tax.show && props.totals.tax > 0);
const types = [
  { value: 'dine_in', label: 'self_service.dine_in' },
  { value: 'takeaway', label: 'self_service.takeaway' },
];
</script>

<template>
  <SelfSheet :open="open" :title="t('self_service.cart_title')" @close="emit('close')">
    <p v-if="!lines.length" class="py-10 text-center text-[13px] text-muted">{{ t('self_service.cart_empty') }}</p>

    <div v-else class="flex flex-col gap-5">
      <p v-if="unavailable.length" class="bg-danger-bg px-3 py-2 text-[12.5px] font-semibold text-danger-text" role="alert">
        {{ t('self_service.unavailable_items') }}
      </p>

      <ul class="flex flex-col divide-y divide-line">
        <li v-for="l in lines" :key="l.key" class="flex flex-col gap-2 py-3" :class="unavailableIds.has(l.variant_id) ? 'bg-danger-bg/40' : ''">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-[14px] font-bold text-ink">{{ l.name }}</p>
              <p v-if="l.modifiers.length" class="text-[12px] text-muted">{{ l.modifiers.join(', ') }}</p>
              <p v-if="l.note" class="text-[12px] italic text-muted">“{{ l.note }}”</p>
              <p v-if="unavailableIds.has(l.variant_id)" class="text-[12px] font-bold text-danger-text">{{ t('self_service.unavailable_row', { name: l.name }) }}</p>
            </div>
            <p class="shrink-0 text-[14px] font-extrabold text-ink">{{ formatIDR(l.unit_price * l.qty) }}</p>
          </div>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-1" role="group" :aria-label="t('self_service.quantity')">
              <button type="button" class="h-10 w-10 border border-line text-[16px] font-bold" :aria-label="t('self_service.decrease')" @click="emit('qty', l.key, l.qty - 1)">−</button>
              <span class="w-8 text-center text-[14px] font-bold" aria-live="polite">{{ l.qty }}</span>
              <button type="button" class="h-10 w-10 border border-line text-[16px] font-bold disabled:opacity-40" :disabled="l.qty >= 99" :aria-label="t('self_service.increase')" @click="emit('qty', l.key, l.qty + 1)">+</button>
            </div>
            <button type="button" class="h-10 px-3 text-[12.5px] font-bold text-danger-text" @click="emit('remove', l.key)">{{ t('self_service.remove') }}</button>
          </div>
        </li>
      </ul>

      <fieldset class="flex flex-col gap-2">
        <legend class="mb-1 text-[13px] font-extrabold text-ink">{{ t('self_service.service_type') }}</legend>
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="s in types"
            :key="s.value"
            type="button"
            class="h-12 border text-[14px] font-bold"
            :class="serviceType === s.value ? 'border-brand bg-brand text-white' : 'border-line bg-surface-soft text-muted-5'"
            :aria-pressed="serviceType === s.value"
            @click="emit('service-type', s.value)"
          >
            {{ t(s.label) }}
          </button>
        </div>
      </fieldset>

      <dl class="flex flex-col gap-1.5 border-t border-line pt-3 text-[13.5px]">
        <div class="flex justify-between"><dt class="text-muted">{{ t('self_service.subtotal') }}</dt><dd class="font-bold">{{ formatIDR(totals.subtotal) }}</dd></div>
        <div v-if="showTax" class="flex justify-between"><dt class="text-muted">{{ tax.label }}</dt><dd class="font-bold">{{ formatIDR(totals.tax) }}</dd></div>
        <div class="flex justify-between text-[16px]"><dt class="font-extrabold">{{ t('self_service.total') }}</dt><dd class="font-extrabold">{{ formatIDR(totals.total) }}</dd></div>
      </dl>
    </div>

    <template #footer>
      <BaseButton class="w-full" size="lg" :disabled="!lines.length" @click="emit('continue')">{{ t('self_service.continue_pay') }}</BaseButton>
    </template>
  </SelfSheet>
</template>
