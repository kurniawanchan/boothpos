<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR, parseMoney } from '../../utils/money';
import { formatDate } from '../../utils/date';

/**
 * Item pesanan + rincian total di panel detail. Presentasional; gambar diperbesar lewat `enlarge`
 * (lightbox dimiliki PreorderDetailDrawer). Dipecah dari PreordersView.vue (041).
 */
const props = defineProps({
  detail: { type: Object, required: true },
});
const emit = defineEmits(['enlarge']);
const { t } = useI18n();

const detailLines = computed(() =>
  (props.detail?.items ?? []).map((i) => ({ ...i, line_total: (parseMoney(i.sell_price) * i.qty).toFixed(2) }))
);
</script>

<template>
  <div class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5">
    <span class="text-[14.5px] font-bold">{{ t('preorders.ordered_items') }}</span>
    <div v-for="line in detailLines" :key="line.id" class="flex items-start gap-3 border-b border-line-6 pb-3 last:border-b-0">
      <button
        v-if="line.image_url"
        type="button"
        class="h-9 w-9 flex-none cursor-zoom-in"
        :aria-label="t('master_data.enlarge_variant_image', { name: line.name_snapshot })"
        @click="emit('enlarge', { image_url: line.image_url, label: line.name_snapshot })"
      >
        <img :src="line.image_url" :alt="line.name_snapshot" class="h-9 w-9 rounded-md border border-line-2 object-cover" />
      </button>
      <div v-else class="flex h-9 w-9 flex-none items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3">
        <i class="ph-duotone ph-image text-[14px]" aria-hidden="true"></i>
      </div>
      <span class="min-w-[28px] text-[14px] font-bold text-brand-active">{{ line.qty }}×</span>
      <div class="flex flex-1 flex-col gap-0.5">
        <span class="text-[13.5px] font-semibold">{{ line.name_snapshot }}<span v-if="line.category_name" class="font-normal text-muted-3"> · {{ line.category_name }}</span></span>
        <span class="font-mono text-[11px] text-muted-3">{{ line.sku_snapshot }} · {{ formatIDR(line.sell_price) }}</span>
        <span v-if="line.artist_name" class="text-[10.5px] text-muted-3">{{ line.artist_name }}</span>
      </div>
      <span class="text-[13.5px] font-bold">{{ formatIDR(line.line_total) }}</span>
    </div>
    <div class="flex flex-col gap-2">
      <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.subtotal') }}</span><span class="font-semibold">{{ formatIDR(detail.subtotal) }}</span></div>
      <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.shipping_cost') }}</span><span class="font-semibold">{{ formatIDR(detail.shipping_cost) }}</span></div>
      <div v-if="detail.pickup_day" class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.pickup_day_label') }}</span><span class="font-semibold">{{ formatDate(detail.pickup_day) }}</span></div>
      <div v-if="detail.courier_name" class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.courier') }}</span><span class="font-semibold">{{ detail.courier_name }}</span></div>
      <div v-if="parseMoney(detail.discount) > 0" class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.discount_label') }}</span><span class="font-semibold text-danger-text">-{{ formatIDR(detail.discount) }}</span></div>
      <div class="flex items-baseline justify-between border-t border-dashed border-line-2 pt-2.5"><span class="text-[13.5px] font-bold">{{ t('preorders.total_due') }}</span><span class="text-[22px] font-extrabold tracking-tight">{{ formatIDR(detail.total_amount) }}</span></div>
      <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.already_paid') }}</span><span class="font-semibold">{{ formatIDR(detail.paid_amount) }}</span></div>
      <div v-if="parseMoney(detail.outstanding) > 0" class="flex items-center justify-between rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5"><span class="text-[12.5px] font-bold text-warn-text">{{ t('preorders.outstanding_balance') }}</span><span class="text-[17px] font-extrabold text-warn-text">{{ formatIDR(detail.outstanding) }}</span></div>
    </div>
    <span class="text-[11.5px] leading-relaxed text-muted-3">{{ t('preorders.shipping_cost_note') }}</span>
  </div>
</template>
