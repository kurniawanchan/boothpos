<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 041-back-office-ui-redesign (US2) — dialog konfirmasi yang MEWAJIBKAN alasan untuk tindakan berisiko
 * (void, tolak, buka kembali, nonaktifkan, tutup paksa, cabut perangkat). Dua mode, dipilih dari `reasons`:
 *
 *  - ADA daftar kode alasan ([{ id, label }] dari GET /reason-codes?category=…): pilihan wajib + catatan opsional;
 *    memancarkan `confirm({ reason_code_id, note })`. Ini mode tujuan (spec FR-007).
 *  - TANPA daftar: teks bebas wajib; memancarkan `confirm({ reason })`. Dipakai selama endpoint kode alasan belum
 *    ada (US3) dan oleh alur lama; server tetap menolak alasan kosong (422).
 *
 * Tombol konfirmasi nonaktif sampai alasan terisi. Nada `danger` memakai token bahaya.
 * Komponen restaurant/ReasonDialog.vue (teks bebas, event `confirm(teks)`) tetap ada untuk TabView sampai
 * layar kasir dimigrasi (US14); setelah itu dihapus.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  message: { type: String, default: '' },
  confirmLabel: { type: String, default: '' },
  reasons: { type: Array, default: () => [] },
  danger: { type: Boolean, default: true },
  submitting: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'confirm']);
const { t } = useI18n();

const choice = ref('');
const note = ref('');
const free = ref('');
const coded = computed(() => props.reasons.length > 0);
watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      choice.value = '';
      note.value = '';
      free.value = '';
    }
  }
);
const ready = computed(() => (coded.value ? choice.value !== '' : free.value.trim() !== ''));

function submit() {
  if (!ready.value) return;
  if (coded.value) {
    const id = props.reasons.find((r) => String(r.id) === String(choice.value))?.id ?? choice.value;
    emit('confirm', { reason_code_id: id, note: note.value.trim() || null });
  } else {
    emit('confirm', { reason: free.value.trim() });
  }
}
</script>

<template>
  <BaseModal :open="open" :title="title" max-width-class="max-w-[460px]" @close="emit('close')">
    <div class="flex flex-col gap-3.5 px-6 py-5">
      <p v-if="message" class="text-[13.5px] leading-relaxed text-muted-4">{{ message }}</p>

      <label v-if="coded" class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-muted-4">
        {{ t('page.reason_label') }}
        <select v-model="choice" data-autofocus class="border-2 border-line bg-white px-3 py-2.5 text-[13.5px] font-normal outline-none focus:border-brand">
          <option value="" disabled>{{ t('page.reason_placeholder') }}</option>
          <option v-for="r in reasons" :key="r.id" :value="String(r.id)">{{ r.label }}</option>
        </select>
      </label>
      <label v-if="coded" class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-muted-4">
        {{ t('page.reason_note') }}
        <textarea v-model="note" rows="2" maxlength="255" class="border-2 border-line bg-white px-3 py-2.5 text-[13.5px] font-normal outline-none focus:border-brand"></textarea>
      </label>

      <label v-else class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-muted-4">
        {{ t('page.reason_label') }}
        <textarea v-model="free" rows="3" maxlength="255" data-autofocus class="border-2 border-line bg-white px-3 py-2.5 text-[13.5px] font-normal outline-none focus:border-brand"></textarea>
      </label>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('page.cancel') }}</BaseButton>
        <BaseButton :variant="danger ? 'danger' : 'primary'" :disabled="!ready" :loading="submitting" @click="submit">{{ confirmLabel || t('page.confirm') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
