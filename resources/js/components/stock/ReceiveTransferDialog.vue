<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { receiveTransfer } from '../../api/stockTransfers';
import { useToastStore } from '../../stores/toast';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';

/**
 * 045 Stage 6 (T093) — menerima transfer: satu baris per item dengan jumlah diterima (bawaan = dikirim). Kurang dari
 * yang dikirim → alasan wajib (selisih tercatat di transfer); lebih dari yang dikirim tidak bisa diketik. Server tetap
 * memeriksa semuanya (422/409) dan galatnya ditampilkan di barisnya.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  transfer: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);
const { t } = useI18n();
const toast = useToastStore();

const rows = reactive({});
const errors = reactive({});
const saving = ref(false);

watch(
  () => [props.open, props.transfer?.id],
  () => {
    Object.keys(rows).forEach((k) => delete rows[k]);
    Object.keys(errors).forEach((k) => delete errors[k]);
    (props.transfer?.lines ?? []).forEach((l) => { rows[l.id] = { qty: String(l.qty_sent), reason: '' }; });
  },
  { immediate: true }
);

const short = (l) => Number(rows[l.id]?.qty) < Number(l.qty_sent);
const valid = computed(() => (props.transfer?.lines ?? []).every((l) => {
  const qty = Number(rows[l.id]?.qty);
  return Number.isFinite(qty) && qty >= 0 && qty <= Number(l.qty_sent) && (!short(l) || (rows[l.id].reason ?? '').trim() !== '');
}));

async function submit() {
  if (!valid.value || saving.value) return;
  saving.value = true;
  Object.keys(errors).forEach((k) => delete errors[k]);
  try {
    const res = await receiveTransfer(props.transfer.id, {
      lines: props.transfer.lines.map((l) => ({ line_id: l.id, qty_received: Number(rows[l.id].qty), ...(short(l) ? { reason: rows[l.id].reason.trim() } : {}) })),
    });
    toast.success(t('transfers.received_done', { number: res.number }));
    emit('done', res);
  } catch (err) {
    if (err?.isValidation) Object.assign(errors, Object.fromEntries(Object.entries(err.errors ?? {}).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open && !!transfer" :title="t('transfers.receive_title', { number: transfer?.number ?? '' })" max-width-class="max-w-[620px]" @close="emit('close')">
    <div class="flex flex-col gap-3 px-6 py-5">
      <p class="text-[13px] text-muted-4">{{ t('transfers.receive_hint') }}</p>
      <div v-for="l in transfer?.lines ?? []" :key="l.id" class="flex flex-col gap-2 border-2 border-line p-3" data-testid="receive-line">
        <div class="flex items-baseline justify-between gap-3">
          <span class="text-[13.5px] font-semibold">{{ l.item?.name }}</span>
          <span class="font-mono text-[12px] text-muted-3">{{ t('transfers.sent_n', { n: l.qty_sent }) }}</span>
        </div>
        <BaseInput v-model="rows[l.id].qty" type="number" step="any" min="0" :label="t('transfers.qty_received')" :error="errors[`lines.${l.id}.qty_received`]" />
        <BaseInput v-if="short(l)" v-model="rows[l.id].reason" :label="t('transfers.shortfall_reason')" maxlength="255" :error="errors[`lines.${l.id}.reason`]" data-testid="short-reason" />
      </div>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :disabled="!valid" :loading="saving" @click="submit">{{ t('transfers.receive') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
