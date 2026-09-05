<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { activateCompany, resendActivation } from '../../api/companies';
import { useToastStore } from '../../stores/toast';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseButton from '../ui/BaseButton.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  company: { type: Object, default: null },
});
const emit = defineEmits(['close', 'activated']);

const { t } = useI18n();
const toast = useToastStore();

const code = ref('');
const codeError = ref('');
const activating = ref(false);
const resending = ref(false);

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    code.value = '';
    codeError.value = '';
  }
});

async function submit() {
  activating.value = true;
  codeError.value = '';
  try {
    const company = await activateCompany(props.company.id, code.value);
    toast.success(t('companies.activated_success'));
    emit('activated', company);
    emit('close');
  } catch (err) {
    codeError.value = err.isValidation ? err.errors.code?.[0] : t('companies.activation_generic_error');
  } finally {
    activating.value = false;
  }
}

async function doResend() {
  resending.value = true;
  try {
    await resendActivation(props.company.id);
    toast.success(t('companies.resend_success'));
  } catch {
    // 409 (sudah aktif) sudah ditoast oleh interceptor bersama.
  } finally {
    resending.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('companies.activate_company')" max-width-class="max-w-[400px]" @close="emit('close')">
    <div class="flex flex-col gap-3.5 px-6 py-5">
      <p class="text-[13px] text-muted-4">{{ t('companies.activation_instructions', { email: company?.contact_email }) }}</p>
      <BaseInput v-model="code" :label="t('companies.activation_code')" maxlength="6" :error="codeError" />
      <button type="button" class="self-start text-[12.5px] font-semibold text-brand-active hover:underline" :disabled="resending" @click="doResend">
        {{ resending ? t('companies.resending') : t('companies.resend_code_btn') }}
      </button>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="activating" @click="submit">{{ t('companies.activate_btn') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
