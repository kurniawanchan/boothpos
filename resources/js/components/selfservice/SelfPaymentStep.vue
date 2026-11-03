<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import SelfCustomerStep from './SelfCustomerStep.vue';
import SelfProofPicker from './SelfProofPicker.vue';
import { formatIDR } from '../../utils/money';

// 090 — langkah pembayaran: HANYA QRIS (spec FR-008). Menampilkan gambar QRIS yang dapat diperbesar, nominal total, dan
// pemilih bukti bayar. Tombol kirim terkunci sampai ada foto bukti.
const props = defineProps({
  payment: { type: Object, default: null }, // { qr_image_url }
  totals: { type: Object, required: true },
  proof: { type: Object, required: true },
  proofBusy: { type: Boolean, default: false },
  proofError: { type: String, default: '' },
  submitting: { type: Boolean, default: false },
  submitError: { type: String, default: '' },
  unavailable: { type: Array, default: () => [] },
  customer: { type: Object, required: true },
  customerErrors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['back', 'pick-proof', 'submit', 'update:customer']);
const { t } = useI18n();

const zoom = ref(false);
const hasProof = computed(() => Boolean(props.proof.file || props.proof.token));
</script>

<template>
  <section class="flex flex-col gap-5" aria-labelledby="ss-pay-title">
    <div class="flex items-center gap-2">
      <button type="button" class="flex h-10 w-10 items-center justify-center border border-line" :aria-label="t('self_service.back')" @click="emit('back')">
        <i class="ph-duotone ph-arrow-left text-[18px]" aria-hidden="true"></i>
      </button>
      <h2 id="ss-pay-title" class="text-[17px] font-extrabold text-ink">{{ t('self_service.pay_title') }}</h2>
    </div>

    <div class="border border-line bg-surface-soft p-4 text-center">
      <p class="text-[12.5px] font-semibold text-muted">{{ t('self_service.pay_amount') }}</p>
      <p class="text-[26px] font-extrabold text-ink" data-testid="pay-amount">{{ formatIDR(totals.total) }}</p>
    </div>

    <div class="flex flex-col items-center gap-3 border border-line bg-white p-4">
      <p class="text-center text-[13px] text-muted">{{ t('self_service.pay_scan') }}</p>
      <button v-if="payment?.qr_image_url" type="button" class="block" :aria-label="t('self_service.zoom_qr')" @click="zoom = true">
        <img :src="payment.qr_image_url" :alt="t('self_service.qr_alt')" class="mx-auto h-64 w-64 max-w-full object-contain" data-testid="qris-image" />
      </button>
      <BaseButton v-if="payment?.qr_image_url" variant="plain" size="sm" @click="zoom = true">
        <i class="ph-duotone ph-magnifying-glass-plus text-[16px]" aria-hidden="true"></i>
        {{ t('self_service.zoom_qr') }}
      </BaseButton>
    </div>

    <SelfCustomerStep :model-value="customer" :errors="customerErrors" @update:model-value="emit('update:customer', $event)" />

    <div class="flex flex-col gap-2">
      <h3 class="text-[14px] font-extrabold text-ink">{{ t('self_service.proof_title') }}</h3>
      <p class="text-[12.5px] text-muted">{{ t('self_service.proof_hint') }}</p>
      <SelfProofPicker :preview-url="proof.previewUrl" :busy="proofBusy" :error="proofError" @pick="emit('pick-proof', $event)" />
    </div>

    <p v-if="unavailable.length" class="bg-danger-bg px-3 py-2 text-[12.5px] font-semibold text-danger-text" role="alert">
      {{ t('self_service.unavailable_items') }}
    </p>
    <p v-if="submitError" class="bg-danger-bg px-3 py-2 text-[12.5px] font-semibold text-danger-text" role="alert" data-testid="submit-error">{{ submitError }}</p>

    <BaseButton size="lg" class="w-full" :disabled="!hasProof || proofBusy" :loading="submitting" data-testid="submit-order" @click="emit('submit')">
      {{ submitting ? t('self_service.submitting') : t('self_service.submit') }}
    </BaseButton>
    <p v-if="!hasProof" class="-mt-3 text-center text-[12px] text-muted">{{ t('self_service.proof_required') }}</p>

    <ImageLightbox :open="zoom" :src="payment?.qr_image_url" :alt="t('self_service.qr_alt')" @close="zoom = false" />
  </section>
</template>
