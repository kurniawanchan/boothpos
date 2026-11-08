<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import ApprovalMethodPicker from './ApprovalMethodPicker.vue';
import { formatIDR } from '../../utils/money';

/**
 * 059 (US1) — menyetujui item SATU seller: pilih metode (gambar / unggah / stempel), lalu langkah
 * konfirmasi yang menyebut seller, jumlah item, dan nilainya SEBELUM apa pun dikirim. Membatalkan atau
 * menutup tidak mengirim apa pun. Server menentukan segalanya (penyetuju, waktu, hak, kesegaran hash);
 * di sini hanya kemudahan agar kesalahan jelas terlihat sebelum permintaan.
 *
 * 064: pemilih metode dipindah ke ApprovalMethodPicker (dipakai juga dialog massal). Perilaku dan test id tak berubah.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  seller: { type: Object, default: null }, // satu elemen `sellers[]` dari GET /orders/{id}/approvals
  onBehalf: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  serverError: { type: String, default: '' },
});
const emit = defineEmits(['submit', 'close']);

const { t } = useI18n();

const method = ref('draw');
const step = ref('choose'); // choose → confirm
const picker = ref(null);
const clientError = ref('');

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    method.value = 'draw';
    step.value = 'choose';
    clientError.value = '';
    picker.value?.reset();
  },
  { immediate: true },
);

function goConfirm() {
  clientError.value = '';
  if (!picker.value?.validate()) return;
  step.value = 'confirm';
}

async function confirm() {
  const image = await picker.value?.getImage();
  if (image === undefined) {
    step.value = 'choose';
    clientError.value = t('approvals.signature_required');
    return;
  }
  emit('submit', { method: method.value, image });
}

const summary = computed(() => ({
  seller: props.seller?.artist_name ?? '',
  count: props.seller?.items?.reduce((n, i) => n + Number(i.qty ?? 0), 0) ?? 0,
  amount: formatIDR(props.seller?.subtotal ?? 0),
}));
</script>

<template>
  <BaseModal :open="open" :title="t('approvals.approve_title', { seller: summary.seller })" max-width-class="max-w-[560px]" @close="emit('close')">
    <div class="flex flex-col gap-4 px-6 py-5" data-testid="approve-seller-modal">
      <p v-if="onBehalf" class="rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5 text-[12.5px] font-medium text-warn-text" data-testid="on-behalf-note">
        {{ t('approvals.on_behalf_note', { seller: summary.seller }) }}
      </p>

      <!-- v-show (bukan v-if): kanvas harus tetap terpasang di langkah konfirmasi supaya goresannya masih bisa dibaca. -->
      <ApprovalMethodPicker
        v-show="step === 'choose'"
        ref="picker"
        v-model:method="method"
        :seller-label="summary.seller"
        @error="(m) => (clientError = m)"
      />

      <div v-if="step === 'confirm'" class="flex flex-col gap-2" data-testid="approval-confirm-step">
        <h3 class="text-[14px] font-bold">{{ t('approvals.confirm_title') }}</h3>
        <p class="text-[13.5px] leading-relaxed text-muted-4">
          {{ t('approvals.confirm_body', { count: summary.count, amount: summary.amount, seller: summary.seller }) }}
        </p>
        <p class="text-[12.5px] font-semibold text-muted-3">{{ t(`approvals.method_${method}`) }}</p>
      </div>

      <p v-if="clientError || serverError" role="alert" class="text-[12.5px] font-medium text-danger-text" data-testid="approval-error">
        {{ clientError || serverError }}
      </p>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" :disabled="saving" @click="step === 'confirm' ? (step = 'choose') : emit('close')">
          {{ step === 'confirm' ? t('approvals.back') : t('common.cancel') }}
        </BaseButton>
        <BaseButton v-if="step === 'choose'" data-testid="approval-continue" @click="goConfirm">{{ t('approvals.continue') }}</BaseButton>
        <BaseButton v-else data-testid="approval-confirm" :loading="saving" @click="confirm">{{ t('approvals.confirm_action') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
