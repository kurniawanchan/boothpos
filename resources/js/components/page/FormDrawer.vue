<script setup>
import { useId } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseDrawer from '../ui/BaseDrawer.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 041-back-office-ui-redesign (US2) — formulir buat/ubah di drawer, bagian berjudul lewat <FormSection>.
 * Galat 422 per kolom ditampilkan oleh kolom masing-masing lewat handler galat pusat (utils/errors.js) — drawer
 * ini tidak punya penanganan galat sendiri. Tombol kirim terkunci selama `submitting` supaya klik ganda tak
 * mengirim dua kali.
 */
defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  submitLabel: { type: String, default: '' },
  submitting: { type: Boolean, default: false },
  maxWidthClass: { type: String, default: 'max-w-[560px]' },
});
defineEmits(['close', 'submit']);
const { t } = useI18n();
// Footer drawer berada DI LUAR <form>, jadi tombol kirim memakai atribut `form` yang menunjuk id unik ini
// (id dari judul bisa berisi spasi — tidak valid).
const formId = `form-drawer-${useId()}`;
</script>

<template>
  <BaseDrawer :open="open" :title="title" :subtitle="subtitle" :max-width-class="maxWidthClass" @close="$emit('close')">
    <form :id="formId" class="flex flex-col" @submit.prevent="$emit('submit')">
      <slot />
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="$emit('close')">{{ t('page.cancel') }}</BaseButton>
      <BaseButton type="submit" :form="formId" :loading="submitting">{{ submitLabel || t('common.save') }}</BaseButton>
    </template>
  </BaseDrawer>
</template>
