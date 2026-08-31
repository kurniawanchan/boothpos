<script setup>
import { ref } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import PaymentPanel from './PaymentPanel.vue';
import { formatIDR } from '../../utils/money';

const props = defineProps({
  open: { type: Boolean, default: false },
  lines: { type: Array, required: true }, // [{ key, name, qty, lineTotal }]
  subtotal: { type: String, required: true },
  discountAmount: { type: String, default: '0.00' },
  total: { type: String, required: true },
  submitting: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'submit']);

const panelRef = ref(null);
defineExpose({ reset: () => panelRef.value?.reset() });
</script>

<template>
  <BaseModal :open="open" title="Pembayaran" max-width-class="max-w-[940px]" @close="emit('close')">
    <div class="grid grid-cols-1 md:grid-cols-[1.25fr_1fr]">
      <div class="flex flex-col gap-5 px-[26px] py-6">
        <PaymentPanel
          ref="panelRef"
          mode="checkout"
          :due-amount="total"
          :submitting="submitting"
          submit-label="Konfirmasi & simpan transaksi"
          @submit="(payload) => emit('submit', payload)"
        />
      </div>
      <div class="flex flex-col gap-4 border-t border-line-3 bg-surface-subtle px-[26px] py-6 md:border-l md:border-t-0">
        <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">Ringkasan</span>
        <div class="flex flex-1 flex-col gap-2.5 overflow-auto">
          <div v-for="line in lines" :key="line.key" class="flex items-baseline gap-2.5">
            <span class="min-w-[24px] text-[12.5px] font-bold text-brand-active">{{ line.qty }}×</span>
            <span class="flex-1 text-[13px] leading-snug">{{ line.name }}</span>
            <span class="text-[13px] font-semibold">{{ formatIDR(line.lineTotal) }}</span>
          </div>
        </div>
        <div class="flex flex-col gap-2 border-t border-dashed border-line-2 pt-3.5">
          <div class="flex justify-between text-[12.5px]"><span class="text-muted">Subtotal</span><span class="font-semibold">{{ formatIDR(subtotal) }}</span></div>
          <div class="flex justify-between text-[12.5px]"><span class="text-muted">Diskon</span><span class="font-semibold text-danger-text">{{ formatIDR(discountAmount) }}</span></div>
          <div class="flex items-baseline justify-between border-t border-line-3 pt-2.5">
            <span class="text-[13.5px] font-bold">Total</span>
            <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(total) }}</span>
          </div>
        </div>
      </div>
    </div>
  </BaseModal>
</template>
