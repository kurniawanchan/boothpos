<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 028 US6 — dialog yang MEWAJIBKAN alasan sebelum aksi yang sulit dibatalkan (membatalkan item yang sudah
 * dikirim, membatalkan tab). Dipakai bersama supaya kedua aksi berperilaku sama. Tombol konfirmasi tetap
 * nonaktif selama alasan kosong; server tetap menolak alasan kosong (422).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  message: { type: String, default: '' },
  confirmLabel: { type: String, default: '' },
  submitting: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'confirm']);
const { t } = useI18n();

const reason = ref('');
watch(() => props.open, (isOpen) => { if (isOpen) reason.value = ''; });
const trimmed = computed(() => reason.value.trim());
</script>

<template>
  <BaseModal :open="open" :title="title" max-width-class="max-w-[440px]" @close="emit('close')">
    <div class="flex flex-col gap-3.5 px-6 py-5">
      <p v-if="message" class="text-[13.5px] leading-relaxed text-muted-4">{{ message }}</p>
      <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-muted-4">
        {{ t('floor.tab.reason_label') }}
        <textarea
          v-model="reason"
          rows="3"
          maxlength="255"
          data-autofocus
          :placeholder="t('floor.tab.reason_placeholder')"
          class="rounded-lg border border-line bg-white px-3.5 py-2.5 text-[13.5px] font-normal outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
        ></textarea>
      </label>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton variant="danger" :disabled="!trimmed" :loading="submitting" @click="emit('confirm', trimmed)">{{ confirmLabel || t('common.continue') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
