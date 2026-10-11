<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatDateTime } from '../../utils/date';
import BaseButton from '../../components/ui/BaseButton.vue';
import PreorderPrintMenu from '../../components/preorder/PreorderPrintMenu.vue';
import PreorderStatusStepper from '../../components/preorder/PreorderStatusStepper.vue';

/**
 * Kartu status di panel detail pre-order: stepper, tombol Duplikat/Pisah/Cetak, tanggal, asal salinan/pisahan
 * dan tindakan transisi. Presentasional — API dan toast tetap di PreorderDetailDrawer (dipecah dari
 * PreordersView.vue, 041, tanpa perubahan perilaku).
 */
const props = defineProps({
  detail: { type: Object, required: true },
  transitioning: { type: Boolean, default: false },
  duplicating: { type: Boolean, default: false },
});
const emit = defineEmits(['duplicate', 'split', 'print-invoice', 'print-payment', 'open-detail', 'mark-arrived', 'mark-handed-over', 'cancel']);
const { t } = useI18n();

// Dropdown "Cetak" di panel detail. Invoice pembayaran menunjuk pembayaran
// TERAKHIR (modalnya sendiri juga jatuh ke pembayaran terakhir bila id tak
// ada); tiap pembayaran lama tetap punya tombol cetaknya di riwayat pembayaran.
const lastPaymentId = computed(() => {
  const payments = props.detail?.payments ?? [];
  return payments.length ? payments[payments.length - 1].id : null;
});
</script>

<template>
  <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
    <div class="flex items-center justify-between gap-3">
      <span class="text-[14.5px] font-bold">{{ t('preorders.preorder_status') }}</span>
      <div class="flex items-center gap-2">
        <BaseButton variant="secondary" size="sm" :loading="duplicating" data-testid="detail-duplicate" @click="emit('duplicate', detail.id)">
          <i class="ph-duotone ph-copy" aria-hidden="true"></i>
          {{ t('preorders.duplicate') }}
        </BaseButton>
        <BaseButton
          v-if="!['handed_over', 'cancelled'].includes(detail.status)"
          variant="secondary"
          size="sm"
          :disabled="(detail.payments ?? []).length > 0"
          :title="(detail.payments ?? []).length > 0 ? t('preorders.split_disabled_has_payment') : undefined"
          data-testid="detail-split"
          @click="emit('split', detail.id)"
        >
          <i class="ph-duotone ph-arrows-split" aria-hidden="true"></i>
          {{ t('preorders.split') }}
        </BaseButton>
        <PreorderPrintMenu
          :has-payment="lastPaymentId != null"
          @print-invoice="emit('print-invoice', detail)"
          @print-payment="emit('print-payment', lastPaymentId)"
        />
      </div>
    </div>
    <PreorderStatusStepper :status="detail.status" />
    <div class="flex flex-wrap gap-x-6 gap-y-1 text-[12px] text-muted-3">
      <span>{{ t('preorders.detail_created_label') }}: <span class="font-semibold text-muted-4">{{ formatDateTime(detail.created_at) }}</span></span>
      <span>{{ t('preorders.detail_updated_label') }}: <span class="font-semibold text-muted-4">{{ formatDateTime(detail.updated_at) }}</span></span>
      <span v-if="detail.split_children?.length" data-testid="detail-split-children">
        {{ t('preorders.split_into') }}:
        <button
          v-for="child in detail.split_children"
          :key="child.id"
          type="button"
          class="mr-1.5 font-semibold text-brand-active underline"
          @click="emit('open-detail', child.id)"
        >{{ child.preorder_number }}</button>
      </span>
      <!-- 027 — asal pre-order ini. Nomor memakai snapshot, jadi tetap
           terbaca bila pre-order sumbernya sudah dihapus (tanpa tautan). -->
      <span v-if="detail.source" data-testid="detail-source">
        {{ detail.source.type === 'split' ? t('preorders.split_from') : t('preorders.duplicated_from') }}:
        <button
          v-if="detail.source.preorder_id"
          type="button"
          class="font-semibold text-brand-active underline"
          @click="emit('open-detail', detail.source.preorder_id)"
        >{{ detail.source.preorder_number }}</button>
        <span v-else class="font-semibold text-muted-4">{{ detail.source.preorder_number }}</span>
      </span>
    </div>
    <div v-if="!['handed_over', 'cancelled'].includes(detail.status)" class="flex flex-wrap items-center gap-2.5 pt-1.5">
      <BaseButton v-if="detail.status === 'dp_paid'" size="sm" :loading="transitioning" @click="emit('mark-arrived')">{{ t('preorders.mark_arrived') }}</BaseButton>
      <BaseButton v-if="detail.status === 'settled'" size="sm" :loading="transitioning" @click="emit('mark-handed-over')">{{ t('preorders.mark_handed_over') }}</BaseButton>
      <BaseButton v-if="['ordered', 'dp_paid', 'arrived'].includes(detail.status)" variant="danger" size="sm" @click="emit('cancel')">{{ t('preorders.cancel_preorder_btn') }}</BaseButton>
      <span class="text-[11.5px] leading-relaxed text-muted-3">{{ t('preorders.handover_note') }}</span>
    </div>
  </div>
</template>
