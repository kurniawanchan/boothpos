<script setup>
import { useI18n } from 'vue-i18n';
import BaseButton from '../../components/ui/BaseButton.vue';
import PaymentSummaryCard from '../../components/payment/PaymentSummaryCard.vue';
import PaymentHistoryList from '../../components/payment/PaymentHistoryList.vue';

/**
 * Ringkasan + riwayat pembayaran di panel detail (028-partial-split-payment US3): riwayat bersama (juga dipakai
 * di Sales), tiap entri berdiri sendiri, tanpa aksi ubah; Hapus hanya owner/admin dan diaudit server.
 * Presentasional — semua tindakan dilaporkan ke PreorderDetailDrawer. Dipecah dari PreordersView.vue (041).
 */
defineProps({
  detail: { type: Object, required: true },
  canDeletePayments: { type: Boolean, default: false },
  canAddPayment: { type: Boolean, default: false },
});
const emit = defineEmits(['view-proof', 'edit-confirmation', 'verify', 'print', 'delete', 'add']);
const { t } = useI18n();
</script>

<template>
  <div class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5">
    <span class="text-[14.5px] font-bold">{{ t('preorders.payment') }}</span>
    <PaymentSummaryCard v-if="detail.payment_summary" :summary="detail.payment_summary" />
    <p class="text-[12px] leading-relaxed text-muted-3">
      {{ t('preorders.payment_history_note') }}
    </p>

    <!-- 028-partial-split-payment (US3) — riwayat pembayaran bersama (juga dipakai
         di Sales): tiap entri berdiri sendiri, tanpa aksi ubah; Hapus hanya
         owner/admin dan diaudit server. -->
    <PaymentHistoryList
      :payments="detail.payments ?? []"
      :can-delete="canDeletePayments"
      @view-proof="(p) => emit('view-proof', p)"
      @edit-confirmation="(p) => emit('edit-confirmation', p)"
      @verify="(p) => emit('verify', p)"
      @print="(p) => emit('print', p)"
      @delete="(p) => emit('delete', p)"
    />

    <BaseButton v-if="canAddPayment" data-testid="add-payment" @click="emit('add')">
      <i class="ph-duotone ph-plus-circle text-[17px]" aria-hidden="true"></i>
      {{ t('payment_ledger.add_payment') }}
    </BaseButton>
  </div>
</template>
