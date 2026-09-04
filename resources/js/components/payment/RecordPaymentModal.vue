<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import PaymentPanel from './PaymentPanel.vue';
import { createPreorderPayment } from '../../api/preorders';
import { formatIDR } from '../../utils/money';
import { useToastStore } from '../../stores/toast';

const { t } = useI18n();
const toast = useToastStore();

const props = defineProps({
  open: { type: Boolean, default: false },
  preorderId: { type: [Number, String], required: true },
  dueAmount: { type: String, required: true },
  purpose: { type: String, default: 'down_payment' }, // down_payment | settlement
  title: { type: String, default: '' },
});
const emit = defineEmits(['close', 'saved']);

/**
 * 010-split-payment-preorder-reports (US2/T007-T008) — PaymentPanel emits
 * the FULL entries array once it covers the due amount (same mechanism as
 * POS checkout, research.md R2). Unlike a POS order (created atomically in
 * one request), a preorder's payment history is a running ledger, so each
 * entry is submitted as its own SEQUENTIAL, AWAITED call to the existing
 * `POST /preorders/{id}/payments` endpoint — never a new batch endpoint —
 * so status-transition guard logic (DP→arrived, 409s) runs once per entry,
 * in order, exactly as it would for separately-recorded payments.
 *
 * Per-entry state (`pending`|`submitting`|`submitted`|`failed`) is tracked
 * so that if entry 2 of 3 fails (network error or a 409 guard), entry 1
 * (already persisted) is NEVER resubmitted — only the failed entry offers
 * a retry, which resumes the sequence from that entry onward.
 */
const rows = ref([]); // [{ ...entryPayload, status }]
const running = ref(false);

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) rows.value = [];
  }
);

const STATUS_LABELS = {
  pending: 'preorders.split_entry_pending',
  submitting: 'preorders.split_entry_submitting',
  submitted: 'preorders.split_entry_submitted',
  failed: 'preorders.split_entry_failed',
};
const METHOD_LABELS = { cash: 'pos.method_cash', bank_transfer: 'pos.method_transfer', qr_ewallet: 'pos.method_qris' };

function toApiPayload(row) {
  const { method, channel_id, amount, proof_token, notes } = row;
  return { method, channel_id, amount, proof_token, notes, purpose: props.purpose };
}

async function runFrom(startIndex) {
  running.value = true;
  try {
    for (let i = startIndex; i < rows.value.length; i++) {
      if (rows.value[i].status === 'submitted') continue;
      rows.value[i].status = 'submitting';
      try {
        // eslint-disable-next-line no-await-in-loop -- sequential by design, see file docblock
        await createPreorderPayment(props.preorderId, toApiPayload(rows.value[i]));
        rows.value[i].status = 'submitted';
      } catch (err) {
        rows.value[i].status = 'failed';
        if (err.isValidation) toast.error(Object.values(err.errors)[0]?.[0] ?? err.message);
        return;
      }
    }
    toast.success(t('preorders.payment_saved'));
    emit('saved');
    emit('close');
  } finally {
    running.value = false;
  }
}

function handleSubmit(entries) {
  rows.value = entries.map((e) => ({ ...e, status: 'pending' }));
  runFrom(0);
}

function retryEntry(index) {
  runFrom(index);
}
</script>

<template>
  <BaseModal :open="open" :title="title || t('preorders.record_payment')" max-width-class="max-w-[480px]" @close="emit('close')">
    <div class="px-[26px] py-6">
      <div v-if="rows.length" class="mb-4 flex flex-col gap-2 rounded-lg border border-line-3 bg-surface-subtle p-3">
        <p v-if="rows.some((r) => r.status === 'failed')" class="text-[12px] font-semibold text-danger-text">
          {{ t('preorders.split_payment_partial_failure') }}
        </p>
        <div v-for="(row, idx) in rows" :key="idx" class="flex items-center justify-between gap-2 text-[12.5px]">
          <span>{{ t(METHOD_LABELS[row.method] ?? row.method) }} · {{ formatIDR(row.amount) }}</span>
          <div class="flex items-center gap-2">
            <span
              class="text-[11px] font-bold uppercase tracking-wider"
              :class="{
                'text-muted-3': row.status === 'pending',
                'text-warn-text': row.status === 'submitting',
                'text-brand-active': row.status === 'submitted',
                'text-danger-text': row.status === 'failed',
              }"
            >
              <i
                class="ph-duotone mr-1 text-[13px]"
                :class="{
                  'ph-circle-dashed': row.status === 'pending',
                  'ph-spinner-gap animate-spin': row.status === 'submitting',
                  'ph-check-circle': row.status === 'submitted',
                  'ph-warning-circle': row.status === 'failed',
                }"
                aria-hidden="true"
              ></i>
              {{ t(STATUS_LABELS[row.status]) }}
            </span>
            <BaseButton v-if="row.status === 'failed'" variant="secondary" size="sm" @click="retryEntry(idx)">
              {{ t('preorders.split_entry_retry') }}
            </BaseButton>
          </div>
        </div>
      </div>

      <PaymentPanel mode="record" :due-amount="dueAmount" :submitting="running" :submit-label="t('pos.save_payment')" @submit="handleSubmit" />
    </div>
  </BaseModal>
</template>
