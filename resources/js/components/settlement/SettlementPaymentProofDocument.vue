<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { resolveAppName } from '../../utils/appName';
import { formatIDRExact } from '../../utils/money';
import { formatDate } from '../../utils/date';

/**
 * 076 (US3) — bukti SATU pembayaran settlement. Menyebut nomor settlement yang
 * sama dengan bukti settlement dan saldo SESUDAH pembayaran ini (dihitung
 * server, urut tanggal bayar lalu id). Murni presentasi.
 */
const props = defineProps({
  doc: { type: Object, required: true },
});

const { t } = useI18n();

const appName = computed(() => resolveAppName(props.doc.app_name));
const store = computed(() => props.doc.store_identity ?? {});
const header = computed(() => props.doc.settlement);
const payment = computed(() => props.doc.payment);
const directionLabel = computed(() =>
  t(header.value.direction === 'seller_pays_store' ? 'settlements.pay_direction_in' : 'settlements.pay_direction_out'),
);
</script>

<template>
  <div class="flex flex-col gap-[18px] bg-white px-8 py-7 text-[13px]" data-testid="settlement-payment-proof">
    <div class="flex flex-col items-center gap-1 border-b border-dashed border-line-2 pb-4 text-center">
      <img v-if="store.logo_url" :src="store.logo_url" :alt="store.name" class="mb-1 h-20 w-auto max-w-[240px] object-contain" />
      <span class="text-[18px] font-extrabold tracking-tight">{{ store.name }}</span>
      <span v-if="store.address" class="max-w-[420px] text-[11.5px] leading-snug text-muted-3">{{ store.address }}</span>
    </div>

    <div class="flex flex-col items-center gap-0.5 text-center">
      <span class="text-[15px] font-bold">{{ t('settlements.doc_title_payment') }}</span>
      <span class="font-mono text-[15px] font-bold" data-testid="doc-number">{{ header.number }}</span>
    </div>

    <dl class="grid grid-cols-2 gap-x-6 gap-y-1.5 rounded-lg border border-line-3 px-4 py-3">
      <div><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_seller') }}</dt><dd class="font-bold">{{ header.seller.name }}</dd></div>
      <div><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_event') }}</dt><dd class="font-bold">{{ header.event.name }}</dd></div>
      <div><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-3">{{ t('settlements.pay_date') }}</dt><dd>{{ formatDate(payment.paid_at) }}</dd></div>
      <div><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_method') }}</dt><dd>{{ t(`settlements.pay_method_${payment.method}`) }}</dd></div>
      <div v-if="payment.reference"><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_reference') }}</dt><dd>{{ payment.reference }}</dd></div>
      <div v-if="payment.recorded_by_name"><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_recorded_by') }}</dt><dd>{{ payment.recorded_by_name }}</dd></div>
    </dl>

    <section class="flex flex-col gap-2">
      <div class="flex flex-col items-center gap-0.5 rounded-lg border border-line-3 py-3 text-center">
        <span class="text-[11.5px] font-semibold uppercase tracking-wide text-muted-3">{{ directionLabel }}</span>
        <span class="text-[11.5px] text-muted-3">{{ t('settlements.doc_amount_paid') }}</span>
        <span class="text-[22px] font-extrabold tracking-tight tabular-nums" data-testid="doc-amount">{{ formatIDRExact(payment.amount) }}</span>
      </div>
      <div class="flex justify-between"><span class="text-muted-4">{{ t('settlements.doc_total_line') }}</span><span class="tabular-nums">{{ formatIDRExact(header.net_amount) }}</span></div>
      <div class="flex justify-between"><span class="text-muted-4">{{ t('settlements.doc_paid_after') }}</span><span class="tabular-nums">{{ formatIDRExact(doc.total_paid_after) }}</span></div>
      <div class="flex justify-between border-t border-dashed border-line-2 pt-1.5 font-bold"><span>{{ t('settlements.doc_balance_after') }}</span><span class="tabular-nums" data-testid="doc-balance-after">{{ formatIDRExact(doc.balance_after) }}</span></div>
      <p v-if="payment.notes" class="text-[12px] text-muted-4">{{ t('settlements.doc_notes') }}: {{ payment.notes }}</p>
    </section>

    <div class="border-t border-dashed border-line-2 pt-3 text-center text-[10.5px] text-muted-3">
      {{ t('common.powered_by', { name: appName }) }}
    </div>
  </div>
</template>
