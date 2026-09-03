<script setup>
import { useI18n } from 'vue-i18n';
import BaseModal from './BaseModal.vue';
import BaseButton from './BaseButton.vue';

const { t } = useI18n();
defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  message: { type: String, required: true },
  confirmLabel: { type: String, default: '' },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'close']);
</script>

<template>
  <BaseModal :open="open" :title="title || t('common.confirm_title')" max-width-class="max-w-[420px]" @close="emit('close')">
    <p class="px-6 py-5 text-[13.5px] leading-relaxed text-muted-4">{{ message }}</p>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton variant="danger" :loading="loading" @click="emit('confirm')">{{ confirmLabel || t('common.continue') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
