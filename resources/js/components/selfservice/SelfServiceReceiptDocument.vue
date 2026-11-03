<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatDateTime } from '../../utils/date';
import { formatIDR } from '../../utils/money';

// 090 / US3 — SATU tata letak struk pelanggan pesan mandiri. Dipakai halaman struk dan diunduh sebagai gambar/PDF lewat
// utils/pdfCapture.js (gambar logo ditukar menurut `src`-nya sendiri, bukan indeks — aturan 029). Tidak boleh ada tata letak
// kedua: unduhan dan layar harus selalu sama persis.
const props = defineProps({
  receipt: { type: Object, required: true },
});
const { t } = useI18n();

const serviceLabel = computed(() => (props.receipt.service_type === 'takeaway' ? t('self_service.takeaway') : t('self_service.dine_in')));
const modifierText = (l) => (l.modifiers ?? []).map((m) => (m.qty > 1 ? `${m.option} ×${m.qty}` : m.option)).join(', ');
const showTax = computed(() => Number(props.receipt.tax_amount) > 0 && Boolean(props.receipt.tax_label));
const tone = computed(() => ({
  awaiting_verification: 'bg-warn-bg text-warn-text',
  verified: 'bg-sky-bg text-sky-text',
  dispatched: 'bg-success-bg text-success-text',
  rejected: 'bg-danger-bg text-danger-text',
}[props.receipt.status] ?? 'bg-line-7 text-muted-4'));
</script>

<template>
  <article class="flex w-full flex-col gap-4 bg-white p-5 text-ink" data-testid="receipt-document">
    <header class="flex flex-col items-center gap-1 text-center">
      <img v-if="receipt.store?.logo_url" :src="receipt.store.logo_url" :alt="t('self_service.receipt_logo_alt')" class="mb-1 h-14 w-14 object-contain" />
      <p class="text-[16px] font-extrabold" data-testid="receipt-store">{{ receipt.store?.name }}</p>
      <p v-if="receipt.store?.address" class="text-[12px] text-muted">{{ receipt.store.address }}</p>
      <p v-if="receipt.store?.phone" class="text-[12px] text-muted">{{ receipt.store.phone }}</p>
      <p class="mt-2 text-[13px] font-extrabold uppercase tracking-wide">{{ t('self_service.receipt_title') }}</p>
    </header>

    <div class="px-3 py-2 text-center text-[13px] font-bold" :class="tone" role="status" data-testid="receipt-status">
      {{ t(`self_service.receipt_status_${receipt.status}`) }}
      <span v-if="receipt.status === 'rejected' && receipt.reject_reason" class="mt-0.5 block text-[12px] font-semibold" data-testid="receipt-reason">
        {{ t('self_service.receipt_reason', { reason: receipt.reject_reason }) }}
      </span>
    </div>

    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[12.5px]">
      <dt class="text-muted">{{ t('self_service.receipt_ref') }}</dt><dd class="text-right font-mono font-bold" data-testid="receipt-ref">{{ receipt.reference_no }}</dd>
      <template v-if="receipt.pos_order_number">
        <dt class="text-muted">{{ t('self_service.receipt_pos_order') }}</dt><dd class="text-right font-mono font-bold" data-testid="receipt-pos-order">{{ receipt.pos_order_number }}</dd>
      </template>
      <dt class="text-muted">{{ t('self_service.receipt_table') }}</dt><dd class="text-right font-bold">{{ receipt.table_name }}</dd>
      <dt class="text-muted">{{ t('self_service.receipt_service') }}</dt><dd class="text-right font-bold">{{ serviceLabel }}</dd>
      <dt class="text-muted">{{ t('self_service.receipt_time') }}</dt><dd class="text-right">{{ formatDateTime(receipt.created_at) }}</dd>
      <template v-if="receipt.customer_name">
        <dt class="text-muted">{{ t('self_service.receipt_customer') }}</dt><dd class="text-right font-bold" data-testid="receipt-customer">{{ receipt.customer_name }}</dd>
      </template>
    </dl>

    <section class="flex flex-col gap-1.5 border-t border-dashed border-line pt-3">
      <h2 class="text-[12px] font-extrabold uppercase text-muted-4">{{ t('self_service.receipt_items') }}</h2>
      <ul class="flex flex-col gap-2">
        <li v-for="(l, i) in receipt.lines" :key="i" class="flex items-start justify-between gap-3 text-[13px]">
          <div class="min-w-0">
            <p class="font-bold">{{ l.qty }}× {{ l.name }}</p>
            <p v-if="modifierText(l)" class="text-[11.5px] text-muted">{{ modifierText(l) }}</p>
            <p v-if="l.note" class="text-[11.5px] italic text-muted">“{{ l.note }}”</p>
          </div>
          <p class="shrink-0 font-bold">{{ formatIDR(l.line_total) }}</p>
        </li>
      </ul>
    </section>

    <dl class="flex flex-col gap-1 border-t border-dashed border-line pt-3 text-[13px]">
      <div class="flex justify-between"><dt class="text-muted">{{ t('self_service.subtotal') }}</dt><dd class="font-bold">{{ formatIDR(receipt.subtotal) }}</dd></div>
      <div v-if="showTax" class="flex justify-between"><dt class="text-muted">{{ receipt.tax_label }}</dt><dd class="font-bold">{{ formatIDR(receipt.tax_amount) }}</dd></div>
      <div class="flex justify-between text-[16px]"><dt class="font-extrabold">{{ t('self_service.total') }}</dt><dd class="font-extrabold" data-testid="receipt-total">{{ formatIDR(receipt.total_amount) }}</dd></div>
    </dl>

    <div class="flex items-center justify-between border-t border-dashed border-line pt-3 text-[12.5px]">
      <span class="text-muted">{{ t('self_service.receipt_payment') }} · {{ t('self_service.receipt_method') }}</span>
      <span class="font-bold" data-testid="receipt-payment-status">{{ t(`self_service.receipt_pay_${receipt.payment.status}`) }}</span>
    </div>

    <p class="text-center text-[12px] text-muted">{{ t('self_service.receipt_thanks') }}</p>
  </article>
</template>
