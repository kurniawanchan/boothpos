<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { updatePreorderDispatchStatus } from '../../api/preorders';
import { useToastStore } from '../../stores/toast';
import { formatDateTime } from '../../utils/date';
import { usePreorderLabels } from './preorderMeta';

/**
 * Penanda manual invoice-terkirim / pengiriman-berjalan. Sengaja
 * kartu terpisah dari stepper: ini bukan bagian state
 * machine stok/pembayaran, dan bisa diubah maju-mundur bebas.
 * Memiliki sendiri keadaan simpan + panggilan API; hasilnya dilaporkan lewat `updated` (nilai baru untuk
 * detail) dan `reload` (kolom & filter daftar ikut segar). Dipecah dari PreordersView.vue (041).
 */
const props = defineProps({
  detail: { type: Object, required: true },
});
const emit = defineEmits(['updated', 'reload']);
const toast = useToastStore();
const { t } = useI18n();
const { DISPATCH_LABEL } = usePreorderLabels();

// "Shipping in progress" hanya berlaku untuk Mail Order (fulfillment=courier)
// — pesanan pickup diambil di booth. Backend menegakkan ini (409); ini cermin
// UX supaya opsinya tidak ditawarkan sama sekali. Filter list tetap memuat
// semua opsi.
const dispatchOptions = computed(() =>
  Object.entries(DISPATCH_LABEL.value)
    .filter(([value]) => value !== 'shipping' || props.detail?.fulfillment === 'courier')
    .map(([value, label]) => ({ value, label }))
);

const savingDispatch = ref(false);
async function setDispatchStatus(next) {
  if (!props.detail || savingDispatch.value || props.detail.dispatch_status === next) return;
  savingDispatch.value = true;
  try {
    const updated = await updatePreorderDispatchStatus(props.detail.id, next);
    emit('updated', { dispatch_status: updated.dispatch_status });
    toast.success(t('preorders.dispatch_status_updated'));
    emit('reload', { summary: false }); // kolom & filter di list ikut segar
  } catch (err) {
    toast.error(err.message || t('preorders.dispatch_status_update_failed'));
  } finally {
    savingDispatch.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
    <span class="text-[14.5px] font-bold">{{ t('preorders.dispatch_status_title') }}</span>
    <div role="group" :aria-label="t('preorders.dispatch_status_title')" class="flex flex-wrap gap-2">
      <button
        v-for="opt in dispatchOptions"
        :key="opt.value"
        type="button"
        :disabled="savingDispatch || detail.status === 'cancelled'"
        :aria-pressed="detail.dispatch_status === opt.value"
        class="h-9 rounded-lg border px-3.5 text-[12.5px] font-bold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
        :class="detail.dispatch_status === opt.value
          ? 'border-transparent bg-brand text-white'
          : 'border-line bg-white text-muted-5 hover:border-brand hover:text-brand-active'"
        @click="setDispatchStatus(opt.value)"
      >{{ opt.label }}</button>
    </div>
    <div v-if="detail.invoice_sent_at || detail.shipping_at" class="flex flex-col gap-0.5 text-[12px] text-muted-4">
      <span v-if="detail.invoice_sent_at">{{ t('preorders.dispatch_invoice_sent_on', { date: formatDateTime(detail.invoice_sent_at) }) }}</span>
      <span v-if="detail.shipping_at">{{ t('preorders.dispatch_shipping_on', { date: formatDateTime(detail.shipping_at) }) }}</span>
    </div>
    <p class="text-[11.5px] leading-relaxed text-muted-3">{{ t('preorders.dispatch_status_note') }}</p>
  </div>
</template>
