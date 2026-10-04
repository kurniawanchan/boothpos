<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import PaymentPanel from './PaymentPanel.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import { formatIDR, parseMoney } from '../../utils/money';

const props = defineProps({
  open: { type: Boolean, default: false },
  lines: { type: Array, required: true }, // [{ key, name, qty, lineTotal }]
  subtotal: { type: String, required: true },
  discountAmount: { type: String, default: '0.00' },
  total: { type: String, required: true },
  submitting: { type: Boolean, default: false },
  // 028-partial-split-payment (US5) — pelanggan yang terpasang pada penjualan ini.
  // Tanpa pelanggan, penjualan tak bisa diselesaikan dengan pembayaran kurang.
  customerName: { type: String, default: '' },
});
const emit = defineEmits(['close', 'submit']);

const { t } = useI18n();
const panelRef = ref(null);
const allowPartial = computed(() => props.customerName.trim() !== '');

// Penyelesaian dengan sisa tagihan SELALU lewat konfirmasi — penjualan selesai,
// stok berkurang, dan pelanggan berutang; ini bukan hal yang boleh terjadi karena salah klik.
const pendingPartial = ref(null);
const pendingRemaining = computed(() =>
  pendingPartial.value
    ? Math.max(parseMoney(props.total) - pendingPartial.value.reduce((sum, e) => sum + parseMoney(e.amount), 0), 0)
    : 0,
);
function askPartial(entries) {
  pendingPartial.value = entries;
}
function confirmPartial() {
  const entries = pendingPartial.value;
  pendingPartial.value = null;
  emit('submit', entries);
}

defineExpose({ reset: () => { pendingPartial.value = null; panelRef.value?.reset(); } });
</script>

<template>
  <BaseModal :open="open" :title="t('pos.payment_title')" max-width-class="max-w-[940px]" @close="emit('close')">
    <div class="grid grid-cols-1 md:grid-cols-[1.25fr_1fr]">
      <div class="flex flex-col gap-5 px-[26px] py-6">
        <PaymentPanel
          ref="panelRef"
          mode="checkout"
          :due-amount="total"
          :submitting="submitting"
          :submit-label="t('pos.confirm_and_save_transaction')"
          :allow-partial="allowPartial"
          @submit="(payload) => emit('submit', payload)"
          @submit-partial="askPartial"
        />
      </div>
      <div class="flex flex-col gap-4 border-t border-line-3 bg-surface-subtle px-[26px] py-6 md:border-l md:border-t-0">
        <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">{{ t('pos.summary') }}</span>
        <div class="flex flex-1 flex-col gap-2.5 overflow-auto">
          <div v-for="line in lines" :key="line.key" class="flex items-baseline gap-2.5">
            <span class="min-w-[24px] text-[12.5px] font-bold text-brand-active">{{ line.qty }}×</span>
            <span class="flex-1 text-[13px] leading-snug">{{ line.name }}</span>
            <span class="text-[13px] font-semibold">{{ formatIDR(line.lineTotal) }}</span>
          </div>
        </div>
        <div class="flex flex-col gap-2 border-t border-dashed border-line-2 pt-3.5">
          <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('pos.subtotal') }}</span><span class="font-semibold">{{ formatIDR(subtotal) }}</span></div>
          <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('pos.discount') }}</span><span class="font-semibold text-danger-text">{{ formatIDR(discountAmount) }}</span></div>
          <div class="flex items-baseline justify-between border-t border-line-3 pt-2.5">
            <span class="text-[13.5px] font-bold">{{ t('pos.total') }}</span>
            <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(total) }}</span>
          </div>
        </div>
      </div>
    </div>
  </BaseModal>

  <ConfirmDialog
    :open="pendingPartial !== null"
    :title="t('payment_ledger.confirm_partial_title')"
    :message="t('payment_ledger.confirm_partial_message', { customer: customerName, remaining: formatIDR(pendingRemaining) })"
    :confirm-label="t('payment_ledger.confirm_partial_action')"
    :loading="submitting"
    @close="pendingPartial = null"
    @confirm="confirmPartial"
  />
</template>
