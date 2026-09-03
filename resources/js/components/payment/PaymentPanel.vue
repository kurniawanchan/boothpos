<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import MethodTiles from './MethodTiles.vue';
import ChannelPicker from './ChannelPicker.vue';
import ProofCapture from './ProofCapture.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import { listPaymentChannels, uploadPaymentProof } from '../../api/payments';
import { formatIDR, parseMoney, toMoneyString } from '../../utils/money';
import { useToastStore } from '../../stores/toast';

/**
 * The reusable core of BoothPOS's payment flow — method picker, channel
 * picker, proof capture, cash/change math. Used inside PosPaymentModal
 * (mode="checkout") and RecordPaymentModal (mode="record", preorder
 * DP/settlement) so the non-trivial bits (proof capture, channel
 * selection) are genuinely built once. See project brief item #15.
 *
 * 006-purchase-order-and-ops (US2/US3) — checkout mode now supports
 * SPLIT PAYMENT: `entries` accumulates committed payment lines while a
 * remaining balance is still owed; the main submit button both "adds the
 * current entry" and, once the running total covers dueAmount, emits the
 * full array in one `submit` event. The backend (OrderService::create())
 * already accepted a `payments[]` array before this change — see
 * research.md R2 — so this is purely a frontend capability, no new wire
 * shape. `notes` was already supported for mode="record"; it's now also
 * collected per-entry in checkout mode (research.md R3).
 */
const props = defineProps({
  mode: { type: String, default: 'checkout' }, // checkout | record
  dueAmount: { type: String, default: '0.00' }, // Money string
  allowCash: { type: Boolean, default: true },
  submitting: { type: Boolean, default: false },
  submitLabel: { type: String, default: 'Konfirmasi pembayaran' },
});
const emit = defineEmits(['submit']);

const toast = useToastStore();
const { t } = useI18n();
const method = ref('cash');
const channels = ref([]);
const channelId = ref(null);
const amount = ref(parseMoney(props.dueAmount));
const notes = ref('');
const proofToken = ref(null);
const uploading = ref(false);

// Split payment (checkout mode only) — committed entries so far.
const entries = ref([]);

onMounted(loadChannels);

async function loadChannels() {
  try {
    const res = await listPaymentChannels();
    channels.value = res.data;
  } catch {
    // The shared axios interceptor already toasts network/server failures;
    // cashier can still complete a cash sale without this list.
  }
}

const dueNum = computed(() => parseMoney(props.dueAmount));
const filteredChannels = computed(() => channels.value.filter((c) => c.type === method.value));

const entriesTotal = computed(() => entries.value.reduce((sum, e) => sum + parseMoney(e.amount), 0));
// Remaining balance still owed BEFORE the current (not-yet-committed) entry
// is counted — this is what the current entry's inputs are validated
// against, and what's shown as the running balance banner.
const remainingBeforeCurrent = computed(() => Math.max(dueNum.value - entriesTotal.value, 0));
const isSplitting = computed(() => props.mode === 'checkout' && entries.value.length > 0);

const change = computed(() =>
  props.mode === 'checkout' && method.value === 'cash' ? Math.max(amount.value - remainingBeforeCurrent.value, 0) : 0
);

const cashPresets = computed(() => {
  const base = remainingBeforeCurrent.value;
  if (base <= 0) return [];
  const rounded = Math.ceil(base / 5000) * 5000;
  return [...new Set([base, rounded, rounded + 5000, rounded + 20000, rounded + 50000, rounded + 100000])].slice(0, 6);
});

function setAmount(value) {
  const n = Number(value);
  amount.value = Number.isFinite(n) ? n : 0;
}

function pickMethod(m) {
  method.value = m;
  channelId.value = null;
  proofToken.value = null;
}

async function handleCaptured(file, capturedVia) {
  uploading.value = true;
  try {
    const res = await uploadPaymentProof(file, capturedVia);
    proofToken.value = res.proof_token;
  } catch (err) {
    toast.error(err.message || t('pos.upload_proof_failed'));
  } finally {
    uploading.value = false;
  }
}

function handleCleared() {
  proofToken.value = null;
}

// Non-cash checkout entries default to covering the whole remaining
// balance (the common, non-split case needs no extra typing) but ARE
// editable — see the amount input rendered for mode="checkout" &&
// method!=='cash' below — so a split across e.g. two QRIS entries or
// cash+QRIS both work. Only cash may ever exceed the remaining balance
// (for change); a non-cash entry is capped at it.
const amountToSend = computed(() => amount.value);

const canSubmitCurrent = computed(() => {
  if (uploading.value) return false;
  if (remainingBeforeCurrent.value <= 0 && entries.value.length) return false;
  if (method.value === 'cash') {
    return amount.value > 0;
  }
  return channelId.value !== null && proofToken.value !== null
    && amountToSend.value > 0 && amountToSend.value <= remainingBeforeCurrent.value;
});

function currentEntryPayload() {
  return {
    method: method.value,
    channel_id: method.value === 'cash' ? null : channelId.value,
    amount: toMoneyString(amountToSend.value),
    proof_token: method.value === 'cash' ? null : proofToken.value,
    notes: notes.value || null,
  };
}

function resetCurrentEntryFields() {
  method.value = 'cash';
  channelId.value = null;
  proofToken.value = null;
  notes.value = '';
  amount.value = remainingBeforeCurrent.value;
}

/**
 * Checkout mode's single button does double duty: if the current entry
 * doesn't yet cover the whole remaining balance, it's committed to
 * `entries` and the form resets for the next entry (split continues). If
 * it does cover it (or this is the only/last entry), the full entries
 * array is emitted as one `submit` — the cashier never has to press a
 * separate "add" button for the common single-method case.
 */
function submit() {
  if (props.mode !== 'checkout') {
    if (!canSubmitCurrent.value) return;
    emit('submit', currentEntryPayload());
    return;
  }

  if (!canSubmitCurrent.value) return;

  const newEntry = currentEntryPayload();
  const willRemain = remainingBeforeCurrent.value - parseMoney(newEntry.amount);

  // Cash can overpay (change) even on the final entry — anything else
  // must land exactly on or under the remaining balance to be "complete".
  const coversBalance = method.value === 'cash' ? amount.value >= remainingBeforeCurrent.value : willRemain <= 0;

  if (coversBalance) {
    emit('submit', [...entries.value, newEntry]);
    return;
  }

  entries.value = [...entries.value, newEntry];
  resetCurrentEntryFields();
}

function removeEntry(index) {
  entries.value = entries.value.filter((_, i) => i !== index);
  amount.value = remainingBeforeCurrent.value;
}

function reset() {
  entries.value = [];
  method.value = 'cash';
  channelId.value = null;
  proofToken.value = null;
  notes.value = '';
  amount.value = dueNum.value;
}

defineExpose({ reset });

const METHOD_LABELS = { cash: 'pos.method_cash', bank_transfer: 'pos.method_transfer', qr_ewallet: 'pos.method_qris' };
</script>

<template>
  <div class="flex flex-col gap-5">
    <div class="flex items-baseline justify-between rounded-lg border border-mint-border bg-mint-50 px-4 py-3.5">
      <span class="text-[12.5px] font-semibold text-brand-active">
        {{ isSplitting ? t('pos.remaining_due') : (mode === 'checkout' ? t('pos.total_due') : t('pos.amount_due')) }}
      </span>
      <span class="text-[24px] font-extrabold tracking-tight text-ink">{{ formatIDR(isSplitting ? remainingBeforeCurrent : dueAmount) }}</span>
    </div>

    <!-- Split payment (US2) — entries already committed for this checkout. -->
    <div v-if="entries.length" class="flex flex-col gap-1.5 rounded-lg border border-line-3 bg-surface-subtle p-3">
      <span class="text-[11px] font-bold uppercase tracking-wider text-muted-3">{{ t('pos.payments_so_far') }}</span>
      <div v-for="(e, idx) in entries" :key="idx" class="flex items-center justify-between gap-2 text-[12.5px]">
        <span>{{ t(METHOD_LABELS[e.method] ?? e.method) }}</span>
        <div class="flex items-center gap-2">
          <span class="font-semibold">{{ formatIDR(e.amount) }}</span>
          <button type="button" class="text-danger-text hover:underline" :aria-label="t('common.delete')" @click="removeEntry(idx)">
            <i class="ph-duotone ph-x text-[13px]" aria-hidden="true"></i>
          </button>
        </div>
      </div>
    </div>

    <div class="flex flex-col gap-2">
      <span class="text-[11.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('pos.method') }}</span>
      <MethodTiles :model-value="method" :allow-cash="allowCash" @update:model-value="pickMethod" />
    </div>

    <div v-if="method === 'cash'" class="flex flex-col gap-2.5">
      <BaseInput
        :label="mode === 'checkout' ? t('pos.cash_received') : t('pos.amount_paid')"
        type="number"
        min="0"
        :model-value="amount"
        @update:model-value="setAmount"
      />
      <div v-if="mode === 'checkout' && cashPresets.length" class="grid grid-cols-3 gap-2">
        <button
          v-for="preset in cashPresets"
          :key="preset"
          type="button"
          class="h-11 rounded-lg border border-line bg-white text-[13px] font-bold text-muted-5 transition-colors hover:border-brand hover:text-brand-active"
          @click="amount = preset"
        >
          {{ formatIDR(preset) }}
        </button>
      </div>
      <div v-if="mode === 'checkout'" class="flex items-center justify-between rounded-lg border border-warn-border bg-warn-bg px-3.5 py-3">
        <span class="text-[12.5px] font-bold text-warn-text">{{ t('pos.change') }}</span>
        <span class="text-[18px] font-extrabold text-warn-text">{{ formatIDR(change) }}</span>
      </div>
    </div>

    <div v-else class="flex flex-col gap-3.5">
      <BaseInput v-if="mode === 'record'" :label="t('pos.amount_paid')" type="number" min="0" :model-value="amount" @update:model-value="setAmount" />
      <BaseInput v-else :label="t('purchase_orders.amount')" type="number" min="0" :model-value="amount" @update:model-value="setAmount" :hint="t('pos.non_cash_split_hint')" />
      <ChannelPicker :channels="filteredChannels" :model-value="channelId" @update:model-value="(v) => (channelId = v)" />
      <ProofCapture @captured="handleCaptured" @cleared="handleCleared" />
    </div>

    <BaseTextarea v-if="mode === 'record' || mode === 'checkout'" :model-value="notes" :label="t('pos.notes_optional')" :rows="2" @update:model-value="(v) => (notes = v)" />

    <div class="flex flex-col gap-2">
      <BaseButton variant="primary" size="lg" class="w-full" :disabled="!canSubmitCurrent" :loading="submitting || uploading" @click="submit">
        {{ submitLabel }}
      </BaseButton>
      <p class="text-center text-[11px] leading-relaxed text-muted-3">
        <template v-if="method === 'cash'">{{ t('pos.cash_no_proof_needed') }}</template>
        <template v-else>{{ t('pos.proof_required_before_confirm') }}</template>
      </p>
    </div>
  </div>
</template>
