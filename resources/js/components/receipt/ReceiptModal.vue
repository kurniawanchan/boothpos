<script setup>
import { ref, watch } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import { getReceipt } from '../../api/orders';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { useToastStore } from '../../stores/toast';

const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const toast = useToastStore();
const receipt = ref(null);
const loading = ref(false);

const METHOD_LABELS = { cash: 'Tunai', bank_transfer: 'Transfer bank', qr_ewallet: 'QRIS / e-wallet' };


watch(
  () => [props.open, props.orderId],
  async ([open, orderId]) => {
    if (!open || !orderId) {
      receipt.value = null;
      return;
    }
    loading.value = true;
    try {
      receipt.value = await getReceipt(orderId);
    } catch (err) {
      toast.error(err.message || 'Gagal memuat struk.');
    } finally {
      loading.value = false;
    }
  },
  { immediate: true }
);
</script>

<template>
  <BaseModal :open="open" max-width-class="max-w-[430px]" @close="emit('close')">
    <div class="flex items-center gap-3 bg-brand px-6 py-5 text-white">
      <i class="ph-duotone ph-check-circle text-[30px]" aria-hidden="true"></i>
      <div class="flex flex-col gap-0.5">
        <span class="text-[17px] font-bold tracking-tight">Transaksi tersimpan</span>
        <span class="text-[12.5px] text-mint-100">Silakan foto struk ini</span>
      </div>
    </div>

    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">Memuat struk…</div>
    <div v-else-if="receipt" class="flex flex-col gap-[18px] px-6 py-6">
      <div class="flex flex-col items-center gap-1 text-center">
        <span class="text-[17px] font-extrabold tracking-tight">{{ receipt.store_name }}</span>
        <span class="text-[12.5px] text-muted-2">{{ receipt.event_name }}</span>
        <span class="mt-1.5 font-mono text-[13px] font-semibold">{{ receipt.order_number }}</span>
        <span class="text-[12px] text-muted-2">{{ formatDateTime(receipt.created_at) }} · Kasir {{ receipt.cashier_name }}</span>
      </div>

      <div class="flex flex-col gap-3 border-y border-dashed border-line-2 py-4">
        <div v-for="(item, idx) in receipt.items" :key="idx" class="flex items-start gap-2.5">
          <span class="min-w-[26px] text-[15px] font-bold text-brand-active">{{ item.qty }}×</span>
          <div class="flex flex-1 flex-col gap-0.5">
            <span class="text-[14.5px] font-semibold leading-snug">{{ item.name }}</span>
            <span class="text-[12px] text-muted-3">{{ formatIDR(item.price) }}</span>
          </div>
          <span class="text-[14.5px] font-bold">{{ formatIDR(item.line_total) }}</span>
        </div>
      </div>

      <div class="flex flex-col gap-2">
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">Subtotal</span><span class="font-semibold">{{ formatIDR(receipt.subtotal) }}</span></div>
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">Diskon</span><span class="font-semibold text-danger-text">{{ formatIDR(receipt.discount_amount) }}</span></div>
        <div class="flex items-baseline justify-between border-t border-line-3 pt-2.5">
          <span class="text-[16px] font-bold">Total</span>
          <span class="text-[30px] font-extrabold tracking-tight">{{ formatIDR(receipt.total_amount) }}</span>
        </div>
        <div v-for="(p, idx) in receipt.payment_summary" :key="idx" class="flex justify-between pt-1.5 text-[13.5px]">
          <span class="text-muted">{{ METHOD_LABELS[p.method] ?? p.method }}</span><span class="font-semibold">{{ formatIDR(p.amount) }}</span>
        </div>
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">Kembalian</span><span class="font-semibold">{{ formatIDR(receipt.change_amount) }}</span></div>
      </div>
    </div>

    <template #footer>
      <BaseButton variant="primary" class="w-full" @click="emit('close')">Transaksi berikutnya</BaseButton>
    </template>
  </BaseModal>
</template>
