<script setup>
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import BaseModal from '../../components/ui/BaseModal.vue';

/**
 * Requested: clicking the missing-shipping-info flag shows shipping cost + customer address + notes right
 * away, instead of a trip into the full detail drawer. Presentasional; dipecah dari PreordersView.vue (041).
 */
defineProps({
  open: { type: Boolean, default: false },
  row: { type: Object, default: null },
});
defineEmits(['close']);
const { t } = useI18n();
</script>

<template>
  <BaseModal :open="open" :title="t('preorders.missing_shipping_info_flag')" max-width-class="max-w-[420px]" @close="$emit('close')">
    <div v-if="row" class="flex flex-col gap-3 px-6 py-5 text-[13px]">
      <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('preorders.shipping_cost') }}</span><span class="font-semibold">{{ formatIDR(row.shipping_cost) }}</span></div>
      <div class="flex flex-col gap-1 border-t border-dashed border-line-2 pt-2.5">
        <span class="text-muted-3">{{ t('preorders.customer_address') }}</span>
        <span class="font-medium" data-testid="flag-customer-address">{{ row.customer_address || '—' }}</span>
      </div>
      <div class="flex flex-col gap-1 border-t border-dashed border-line-2 pt-2.5">
        <span class="text-muted-3">{{ t('preorders.notes') }}</span>
        <span class="font-medium">{{ row.notes || '—' }}</span>
      </div>
    </div>
  </BaseModal>
</template>
