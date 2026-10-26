<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import { uuid } from '../../utils/uuid';
import { formatIDRExact } from '../../utils/money';

// 076 (US3) — catat satu pembayaran settlement. `client_ref` dibuat SEKALI
// per pembukaan dialog dan dipakai ulang bila pengguna menekan simpan lagi
// (kirim ulang aman: server memutar ulang hasil lama, tanpa baris ganda).
// Batas jumlah (sisa terutang) ditegakkan server; di sini hanya petunjuk.
const props = defineProps({
  open: { type: Boolean, default: false },
  settlement: { type: Object, default: null },
  saving: { type: Boolean, default: false },
  errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['save', 'close']);

const { t } = useI18n();

const form = reactive({ amount: '', paidAt: '', method: 'bank_transfer', reference: '', notes: '' });
const proof = ref(null);
const clientRef = ref('');

function today() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    clientRef.value = uuid();
    proof.value = null;
    Object.assign(form, {
      amount: props.settlement?.balance ?? '',
      paidAt: today(),
      method: 'bank_transfer',
      reference: '',
      notes: '',
    });
  },
  { immediate: true },
);

const methodOptions = computed(() =>
  ['cash', 'bank_transfer', 'ewallet', 'other'].map((m) => ({ value: m, label: t(`settlements.pay_method_${m}`) })),
);
const directionLabel = computed(() =>
  t(props.settlement?.direction === 'seller_pays_store' ? 'settlements.pay_direction_in' : 'settlements.pay_direction_out'),
);
const maxHint = computed(() => t('settlements.pay_amount_hint', { max: formatIDRExact(props.settlement?.balance ?? 0) }));

function onFile(event) {
  proof.value = event.target.files?.[0] ?? null;
}

function submit() {
  emit('save', {
    payload: {
      client_ref: clientRef.value,
      amount: String(form.amount),
      paid_at: form.paidAt,
      method: form.method,
      reference: form.reference.trim() || null,
      notes: form.notes.trim() || null,
    },
    proof: proof.value,
  });
}
</script>

<template>
  <BaseModal :open="open" :title="t('settlements.pay_add')" max-width-class="max-w-[560px]" @close="emit('close')">
    <form class="flex flex-col gap-4 px-6 py-5" @submit.prevent="submit">
      <p class="text-[12.5px] font-semibold text-muted-4">{{ directionLabel }}</p>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <BaseInput
          v-model="form.amount"
          type="number"
          step="0.01"
          min="0.01"
          :label="t('settlements.pay_amount')"
          :hint="maxHint"
          required
          :error="errors.amount"
          data-autofocus
        />
        <BaseInput v-model="form.paidAt" type="date" :max="today()" :label="t('settlements.pay_date')" required :error="errors.paid_at" />
      </div>
      <BaseSelect v-model="form.method" :label="t('settlements.pay_method')" :options="methodOptions" :error="errors.method" />
      <BaseInput v-model="form.reference" :label="t('settlements.pay_reference')" maxlength="120" :error="errors.reference" />
      <BaseTextarea v-model="form.notes" :label="t('settlements.pay_notes')" :rows="2" :error="errors.notes" />
      <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-muted-4">
        {{ t('settlements.pay_proof') }}
        <input type="file" accept="image/png,image/jpeg" class="text-[13px] font-normal" data-testid="payment-proof" @change="onFile" />
        <span class="text-[11.5px] font-normal text-muted-3">{{ t('settlements.pay_proof_hint') }}</span>
        <span v-if="errors.proof" role="alert" class="text-[12px] font-medium text-danger-text">{{ errors.proof }}</span>
      </label>
      <p v-if="errors.client_ref" role="alert" class="text-[12px] font-medium text-danger-text">{{ errors.client_ref }}</p>
    </form>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="saving" @click="submit">{{ t('settlements.pay_save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
