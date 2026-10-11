<script setup>
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import { formatDate } from '../../utils/date';
import { usePreorderLabels } from './preorderMeta';

/**
 * Pelanggan & fulfillment sekilas di panel detail, supaya tak perlu
 * membuka modal info pelanggan atau mencari hari jemput/kurir di
 * rincian total. Data pelanggan datang dari show() (CustomerResource).
 * Presentasional; dipecah dari PreordersView.vue (041).
 */
defineProps({
  detail: { type: Object, required: true },
});
const { t } = useI18n();
const { FULFILLMENT_LABEL } = usePreorderLabels();
</script>

<template>
  <div class="grid grid-cols-1 gap-4 rounded-card border border-line-2 bg-white p-5 sm:grid-cols-2" data-testid="detail-customer-fulfillment">
    <div class="flex flex-col gap-2.5" data-testid="detail-customer">
      <span class="text-[14.5px] font-bold">{{ t('preorders.customer_section') }}</span>
      <span class="text-[13.5px] font-semibold">{{ detail.customer?.name || detail.customer_name || '—' }}</span>
      <div class="flex flex-col gap-1.5 text-[12.5px]">
        <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.phone') }}</span><span class="font-medium">{{ detail.customer?.phone || '—' }}</span></div>
        <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.email') }}</span><span class="break-all text-right font-medium">{{ detail.customer?.email || '—' }}</span></div>
        <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.social_handle') }}</span><span class="font-medium">{{ detail.customer?.social_handle || '—' }}</span></div>
        <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.address') }}</span><span class="text-right font-medium">{{ detail.customer?.address || '—' }}</span></div>
      </div>
    </div>
    <div class="flex flex-col gap-2.5" data-testid="detail-fulfillment">
      <span class="text-[14.5px] font-bold">{{ t('preorders.fulfillment_section') }}</span>
      <span class="text-[13.5px] font-semibold">{{ FULFILLMENT_LABEL[detail.fulfillment] ?? '—' }}</span>
      <div class="flex flex-col gap-1.5 text-[12.5px]">
        <div v-if="detail.fulfillment === 'pickup'" class="flex justify-between gap-3"><span class="text-muted-3">{{ t('preorders.pickup_day_label') }}</span><span class="font-medium">{{ detail.pickup_day ? formatDate(detail.pickup_day) : '—' }}</span></div>
        <template v-else>
          <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('preorders.courier') }}</span><span class="font-medium">{{ detail.courier_name || '—' }}</span></div>
          <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('preorders.shipping_cost') }}</span><span class="font-medium">{{ formatIDR(detail.shipping_cost) }}</span></div>
        </template>
        <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('preorders.expected_date_label') }}</span><span class="font-medium">{{ detail.expected_date ? formatDate(detail.expected_date) : '—' }}</span></div>
        <div v-if="detail.notes" class="flex flex-col gap-1 border-t border-dashed border-line-2 pt-2"><span class="text-muted-3">{{ t('preorders.notes') }}</span><span class="font-medium">{{ detail.notes }}</span></div>
      </div>
    </div>
  </div>
</template>
