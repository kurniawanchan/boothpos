<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import ProofCapture from './ProofCapture.vue';
import { uploadPaymentProof, updatePaymentConfirmation } from '../../api/payments';
import { useToastStore } from '../../stores/toast';
import { formatIDR } from '../../utils/money';
import { paymentMethodLabel } from '../../utils/paymentMethods';

/**
 * 031-optional-payment-proof (US2/US3) — dialog "Bukti & catatan pembayaran": menambah,
 * mengubah, atau mengganti konfirmasi sebuah pembayaran non-tunai SETELAH tercatat. Dipakai
 * detail Sales (penjualan POS) dan detail Pre-order; `kind` menentukan jalurnya.
 *
 * Alurnya sama dengan checkout: foto diunggah DULU (mendapat `proof_token`), lalu satu PATCH
 * membawa HANYA yang berubah. Tombol Simpan mati selama unggahan berjalan, selama tak ada
 * yang berubah, dan bila hasilnya akan kosong sama sekali (server menolak itu juga — di sini
 * hanya agar pengguna tak perlu menebak). Siapa yang boleh membuka dialog ini ditentukan
 * SERVER lewat `payment.can_edit_confirmation`; komponen ini tidak memutuskan izin.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  payment: { type: Object, default: null }, // entri riwayat pembayaran (reference, notes, proof flags)
  kind: { type: String, default: 'orders' }, // orders | preorders
  targetId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const toast = useToastStore();

const reference = ref('');
const notes = ref('');
const proofToken = ref(null);
const uploading = ref(false);
const saving = ref(false);
const error = ref('');

const initialReference = computed(() => (props.payment?.reference ?? '').trim());
const initialNotes = computed(() => (props.payment?.notes ?? '').trim());
const hasExistingProof = computed(() => !!(props.payment?.has_proof || props.payment?.proof_id));

watch(
  () => [props.open, props.payment?.id],
  ([isOpen]) => {
    if (!isOpen) return;
    reference.value = props.payment?.reference ?? '';
    notes.value = props.payment?.notes ?? '';
    proofToken.value = null;
    uploading.value = false;
    saving.value = false;
    error.value = '';
  },
  { immediate: true },
);

const referenceChanged = computed(() => reference.value.trim() !== initialReference.value);
const notesChanged = computed(() => notes.value.trim() !== initialNotes.value);
const changed = computed(() => proofToken.value !== null || referenceChanged.value || notesChanged.value);
// Setelah disimpan, minimal satu dari bukti/referensi/catatan harus masih ada (FR-010).
const somethingLeft = computed(() => hasExistingProof.value || proofToken.value !== null || reference.value.trim() !== '' || notes.value.trim() !== '');
const canSave = computed(() => changed.value && somethingLeft.value && !uploading.value && !saving.value);

async function handleCaptured(file, capturedVia) {
  uploading.value = true;
  error.value = '';
  try {
    const res = await uploadPaymentProof(file, capturedVia);
    proofToken.value = res.proof_token;
  } catch (err) {
    proofToken.value = null;
    toast.error(err.message || t('pos.upload_proof_failed'));
  } finally {
    uploading.value = false;
  }
}

function handleCleared() {
  proofToken.value = null;
}

function buildPayload() {
  const payload = {};
  if (proofToken.value) payload.proof_token = proofToken.value;
  if (referenceChanged.value) payload.reference = reference.value.trim() || null;
  if (notesChanged.value) payload.notes = notes.value.trim() || null;
  return payload;
}

async function save() {
  if (!canSave.value) return;
  saving.value = true;
  error.value = '';
  try {
    const result = await updatePaymentConfirmation(props.kind, props.targetId, props.payment.id, buildPayload());
    toast.success(t('payment_ledger.confirmation_saved'));
    emit('saved', result);
    emit('close');
  } catch (err) {
    // Server menolak (403 bukan pihak berhak, 409 transaksi batal, 422 validasi): tampilkan
    // pesannya di dalam dialog dan pertahankan isian.
    error.value = (err?.errors && Object.values(err.errors)[0]?.[0]) || err?.message || t('payment_ledger.save_failed');
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('payment_ledger.confirmation_title')" max-width-class="max-w-[480px]" @close="emit('close')">
    <div v-if="payment" class="flex flex-col gap-4 px-[26px] py-6">
      <div class="flex items-baseline justify-between rounded-lg border border-mint-border bg-mint-50 px-4 py-3">
        <span class="text-[12.5px] font-semibold text-brand-active">
          {{ paymentMethodLabel(t, payment.method) }}<span v-if="payment.provider" class="font-normal"> · {{ payment.provider }}</span>
        </span>
        <span class="text-[16px] font-extrabold tracking-tight">{{ formatIDR(payment.amount) }}</span>
      </div>

      <ProofCapture @captured="handleCaptured" @cleared="handleCleared" />
      <p v-if="hasExistingProof" class="text-[11.5px] leading-relaxed text-muted-3" data-testid="replace-note">
        {{ t('payment_ledger.confirmation_replace_note') }}
      </p>

      <BaseInput
        v-model="reference"
        :label="t('payment_ledger.reference_optional')"
        :placeholder="t('payment_ledger.reference_placeholder')"
        maxlength="100"
        autocomplete="off"
      />
      <BaseTextarea v-model="notes" :label="t('pos.notes_optional')" :rows="2" />

      <p v-if="!somethingLeft" class="text-[11.5px] leading-relaxed text-muted-3">{{ t('payment_ledger.confirmation_needs_something') }}</p>
      <p v-if="error" role="alert" class="text-[12.5px] font-medium text-danger-text">{{ error }}</p>
    </div>

    <template #footer>
      <div class="flex gap-2.5">
        <BaseButton variant="secondary" class="flex-1" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton variant="primary" class="flex-1" :disabled="!canSave" :loading="saving" @click="save">
          {{ t('payment_ledger.confirmation_save') }}
        </BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
