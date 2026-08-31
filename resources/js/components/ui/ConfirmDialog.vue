<script setup>
import BaseModal from './BaseModal.vue';
import BaseButton from './BaseButton.vue';

defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: 'Konfirmasi' },
  message: { type: String, required: true },
  confirmLabel: { type: String, default: 'Ya, lanjutkan' },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'close']);
</script>

<template>
  <BaseModal :open="open" :title="title" max-width-class="max-w-[420px]" @close="emit('close')">
    <p class="px-6 py-5 text-[13.5px] leading-relaxed text-muted-4">{{ message }}</p>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">Batal</BaseButton>
        <BaseButton variant="danger" :loading="loading" @click="emit('confirm')">{{ confirmLabel }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
