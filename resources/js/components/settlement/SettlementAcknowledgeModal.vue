<script setup>
import { computed, defineAsyncComponent, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';

// Kanvas dimuat hanya saat dialog ini dibuka (bukan di bundel awal).
import SignaturePadFallback from './SignaturePadFallback.vue';
const SignaturePad = defineAsyncComponent({
  loader: () => import('./SignaturePad.vue'),
  errorComponent: SignaturePadFallback,
  timeout: 15000,
});

// 076 (US3) — tiga cara mengakui: tanda tangan gambar, nama diketik, atau
// dicatat di luar sistem. Hanya mengirim apa yang diketik/digambar pengguna;
// server menentukan segalanya (siapa yang mencatat, waktu, status).
const props = defineProps({
  open: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['save', 'close']);

const { t } = useI18n();
const METHODS = ['signature', 'typed', 'external'];

const method = ref('signature');
const form = reactive({ signerName: '', note: '' });
const pad = ref(null);
const hasInk = ref(false);
const clientError = ref('');

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    method.value = 'signature';
    Object.assign(form, { signerName: '', note: '' });
    hasInk.value = false;
    clientError.value = '';
  },
  { immediate: true },
);

const needsName = computed(() => method.value !== 'external');

async function submit() {
  clientError.value = '';

  if (needsName.value && !form.signerName.trim()) {
    clientError.value = t('settlements.ack_name_required');
    return;
  }
  if (method.value === 'external' && !form.note.trim()) {
    clientError.value = t('settlements.ack_note_required');
    return;
  }

  let signature = null;
  if (method.value === 'signature') {
    signature = await pad.value?.toBlob();
    if (!signature) {
      clientError.value = t('settlements.ack_signature_required');
      return;
    }
  }

  emit('save', {
    method: method.value,
    signerName: needsName.value ? form.signerName.trim() : null,
    note: method.value === 'external' ? form.note.trim() : null,
    signature,
  });
}
</script>

<template>
  <BaseModal :open="open" :title="t('settlements.ack_title')" max-width-class="max-w-[600px]" @close="emit('close')">
    <div class="flex flex-col gap-4 px-6 py-5">
      <div role="tablist" class="flex gap-1.5">
        <button
          v-for="m in METHODS"
          :key="m"
          type="button"
          role="tab"
          :aria-selected="method === m"
          class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
          :class="method === m ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
          @click="method = m"
        >
          {{ t(`settlements.ack_method_${m}`) }}
        </button>
      </div>

      <BaseInput
        v-if="needsName"
        v-model="form.signerName"
        :label="t('settlements.ack_signer_name')"
        required
        maxlength="120"
        :error="errors.signer_name"
        data-autofocus
      />

      <SignaturePad v-if="method === 'signature'" ref="pad" @change="(v) => (hasInk = v)" />

      <BaseTextarea
        v-if="method === 'external'"
        v-model="form.note"
        :label="t('settlements.ack_note')"
        :hint="t('settlements.ack_note_hint')"
        required
        :rows="3"
        :error="errors.note"
      />

      <p v-if="clientError || errors.signature" role="alert" class="text-[12.5px] font-medium text-danger-text">
        {{ clientError || errors.signature }}
      </p>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="saving" @click="submit">{{ t('settlements.ack_save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
