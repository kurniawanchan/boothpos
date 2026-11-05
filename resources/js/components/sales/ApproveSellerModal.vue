<script setup>
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import ApprovalStamp from './ApprovalStamp.vue';
import { formatIDR } from '../../utils/money';
import { useAuthStore } from '../../stores/auth';

// Kanvas hanya dimuat saat dialog ini dibuka (bukan di bundel Sales) — Constitution V.
import SignaturePadFallback from '../settlement/SignaturePadFallback.vue';
const SignaturePad = defineAsyncComponent({
  loader: () => import('../settlement/SignaturePad.vue'),
  errorComponent: SignaturePadFallback,
  timeout: 15000,
});

/**
 * 059 (US1) — menyetujui item SATU seller: pilih metode (gambar / unggah / stempel), lalu langkah
 * konfirmasi yang menyebut seller, jumlah item, dan nilainya SEBELUM apa pun dikirim. Membatalkan atau
 * menutup tidak mengirim apa pun. Server menentukan segalanya (penyetuju, waktu, hak, kesegaran hash);
 * di sini hanya kemudahan agar kesalahan jelas terlihat sebelum permintaan.
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
const auth = useAuthStore();

const METHODS = ['draw', 'upload', 'stamp'];
const MAX_BYTES = 5 * 1024 * 1024;
const ALLOWED = ['image/png', 'image/jpeg'];

const method = ref('draw');
const step = ref('choose'); // choose → confirm
const hasInk = ref(false);
const pad = ref(null);
const file = ref(null);
const clientError = ref('');

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    method.value = 'draw';
    step.value = 'choose';
    hasInk.value = false;
    file.value = null;
    clientError.value = '';
  },
  { immediate: true },
);

watch(method, () => {
  clientError.value = '';
  hasInk.value = false; // berpindah tab melepas kanvas (goresan hilang), jadi penanda tinta ikut direset
});

function onFile(event) {
  clientError.value = '';
  const picked = event.target.files?.[0] ?? null;
  event.target.value = ''; // memilih berkas yang sama dua kali tetap memicu change
  if (!picked) return;
  if (!ALLOWED.includes(picked.type)) {
    file.value = null;
    clientError.value = t('approvals.file_type_error');
  } else if (picked.size > MAX_BYTES) {
    file.value = null;
    clientError.value = t('approvals.file_size_error');
  } else {
    file.value = picked;
  }
}

function goConfirm() {
  clientError.value = '';
  if (method.value === 'draw' && !hasInk.value) {
    clientError.value = t('approvals.signature_required');
    return;
  }
  if (method.value === 'upload' && !file.value) {
    clientError.value = clientError.value || t('approvals.image_required');
    return;
  }
  step.value = 'confirm';
}

async function confirm() {
  let image = null;
  if (method.value === 'draw') {
    const blob = await pad.value?.toBlob();
    if (!blob) {
      step.value = 'choose';
      clientError.value = t('approvals.signature_required');
      return;
    }
    image = new File([blob], 'signature.png', { type: 'image/png' });
  } else if (method.value === 'upload') {
    image = file.value;
  }
  emit('submit', { method: method.value, image });
}

const summary = computed(() => ({
  seller: props.seller?.artist_name ?? '',
  count: props.seller?.items?.reduce((n, i) => n + Number(i.qty ?? 0), 0) ?? 0,
  amount: formatIDR(props.seller?.subtotal ?? 0),
}));
const today = new Date().toLocaleDateString();
</script>

<template>
  <BaseModal :open="open" :title="t('approvals.approve_title', { seller: summary.seller })" max-width-class="max-w-[560px]" @close="emit('close')">
    <div class="flex flex-col gap-4 px-6 py-5" data-testid="approve-seller-modal">
      <p v-if="onBehalf" class="rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5 text-[12.5px] font-medium text-warn-text" data-testid="on-behalf-note">
        {{ t('approvals.on_behalf_note', { seller: summary.seller }) }}
      </p>

      <!-- v-show (bukan v-if): kanvas harus tetap terpasang di langkah konfirmasi supaya goresannya masih bisa dibaca. -->
      <div v-show="step === 'choose'" class="flex flex-col gap-4">
        <div role="tablist" class="flex flex-wrap gap-1.5">
          <button
            v-for="m in METHODS"
            :key="m"
            type="button"
            role="tab"
            :data-testid="`method-${m}`"
            :aria-selected="method === m"
            class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
            :class="method === m ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
            @click="method = m"
          >
            {{ t(`approvals.method_${m}`) }}
          </button>
        </div>

        <SignaturePad v-if="method === 'draw'" ref="pad" @change="(v) => (hasInk = v)" />

        <div v-else-if="method === 'upload'" class="flex flex-col gap-2">
          <label class="inline-flex w-fit cursor-pointer items-center gap-2 rounded-lg border border-line bg-white px-3.5 py-2 text-[13px] font-semibold hover:border-brand">
            <i class="ph-duotone ph-upload-simple text-[17px]" aria-hidden="true"></i>
            {{ t('approvals.upload_choose') }}
            <input type="file" class="sr-only" accept="image/png,image/jpeg" data-testid="approval-file" @change="onFile" />
          </label>
          <p class="text-[12px] text-muted-3">{{ file ? t('approvals.upload_selected', { name: file.name }) : t('approvals.upload_hint') }}</p>
        </div>

        <div v-else class="flex flex-col items-start gap-3">
          <p class="text-[13px] text-muted-4">{{ t('approvals.stamp_hint') }}</p>
          <ApprovalStamp :seller="summary.seller" :approver="auth.user?.name || ''" :date="today" />
        </div>
      </div>

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
