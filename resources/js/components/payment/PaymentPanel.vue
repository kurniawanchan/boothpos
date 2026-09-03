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
const change = computed(() =>
  props.mode === 'checkout' && method.value === 'cash' ? Math.max(amount.value - dueNum.value, 0) : 0
);

const cashPresets = computed(() => {
  const base = dueNum.value;
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

const amountToSend = computed(() => {
  if (props.mode === 'checkout' && method.value !== 'cash') return dueNum.value;
  return amount.value;
});

const canSubmit = computed(() => {
  if (uploading.value) return false;
  if (method.value === 'cash') {
    if (props.mode === 'checkout') return amount.value >= dueNum.value && dueNum.value > 0;
    return amount.value > 0;
  }
  return channelId.value !== null && proofToken.value !== null && amountToSend.value > 0;
});

function submit() {
  if (!canSubmit.value) return;
  emit('submit', {
    method: method.value,
    channel_id: method.value === 'cash' ? null : channelId.value,
    amount: toMoneyString(amountToSend.value),
    proof_token: method.value === 'cash' ? null : proofToken.value,
    notes: notes.value || null,
  });
}

function reset() {
  method.value = 'cash';
  channelId.value = null;
  proofToken.value = null;
  notes.value = '';
  amount.value = dueNum.value;
}

defineExpose({ reset });
</script>

<template>
  <div class="flex flex-col gap-5">
    <div class="flex items-baseline justify-between rounded-lg border border-mint-border bg-mint-50 px-4 py-3.5">
      <span class="text-[12.5px] font-semibold text-brand-active">{{ mode === 'checkout' ? t('pos.total_due') : t('pos.amount_due') }}</span>
      <span class="text-[24px] font-extrabold tracking-tight text-ink">{{ formatIDR(dueAmount) }}</span>
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
      <ChannelPicker :channels="filteredChannels" :model-value="channelId" @update:model-value="(v) => (channelId = v)" />
      <ProofCapture @captured="handleCaptured" @cleared="handleCleared" />
    </div>

    <BaseTextarea v-if="mode === 'record'" :model-value="notes" :label="t('pos.notes_optional')" :rows="2" @update:model-value="(v) => (notes = v)" />

    <div class="flex flex-col gap-2">
      <BaseButton variant="primary" size="lg" class="w-full" :disabled="!canSubmit" :loading="submitting || uploading" @click="submit">
        {{ submitLabel }}
      </BaseButton>
      <p class="text-center text-[11px] leading-relaxed text-muted-3">
        <template v-if="method === 'cash'">{{ t('pos.cash_no_proof_needed') }}</template>
        <template v-else>{{ t('pos.proof_required_before_confirm') }}</template>
      </p>
    </div>
  </div>
</template>
