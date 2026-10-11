<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listReasonCodes } from '../../api/reasons';
import { useToastStore } from '../../stores/toast';
import ReasonDialog from '../page/ReasonDialog.vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import LoadingState from '../ui/LoadingState.vue';
import ErrorState from '../ui/ErrorState.vue';

/**
 * 043 (US3/US4) — dasar bersama SoldOutDialog dan DiscontinueDialog: memuat kode alasan sebuah kategori lalu
 * membuka ReasonDialog dalam mode BERKODE. Mode kode alasan WAJIB (FR-007), sedangkan ReasonDialog diam-diam jatuh ke
 * teks bebas bila daftarnya kosong — jadi dialog alasan TIDAK dirender sebelum daftar berhasil dimuat dan tidak kosong;
 * selama memuat/gagal/kosong yang tampil adalah keadaan sendiri (memuat, galat + Coba lagi, atau pesan "belum ada
 * kode alasan") dengan satu jalan keluar. Alasan teks bebas tidak pernah terkirim dari sini.
 *
 * `submit(payload)` dipasok pemanggil (panggilan API-nya). Hasil galat:
 *  - 409 (sudah 86 / sudah diskontinu: tampilan halaman basi) -> dialog menutup dan memancarkan `conflict` supaya
 *    halaman memuat ulang kebenarannya; toast pesannya sudah dikeluarkan handler galat pusat (tidak digandakan di sini);
 *  - 422 -> toast pesan pertama, dialog tetap terbuka agar alasan bisa diperbaiki;
 *  - lainnya -> toast pesan galat, dialog tetap terbuka.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  category: { type: String, required: true },
  title: { type: String, default: '' },
  message: { type: String, default: '' },
  confirmLabel: { type: String, default: '' },
  danger: { type: Boolean, default: true },
  submit: { type: Function, required: true },
});
const emit = defineEmits(['close', 'done', 'conflict']);
const { t } = useI18n();
const toast = useToastStore();

const reasons = ref([]);
const loadState = ref('loading'); // loading | ready | error | empty
const submitting = ref(false);
let seq = 0;

async function loadReasons() {
  const mine = ++seq;
  loadState.value = 'loading';
  try {
    const res = await listReasonCodes({ category: props.category });
    if (mine !== seq) return;
    reasons.value = (res.data ?? []).map((r) => ({ id: r.id, label: r.label }));
    loadState.value = reasons.value.length ? 'ready' : 'empty';
  } catch {
    if (mine === seq) loadState.value = 'error';
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) loadReasons();
  },
  { immediate: true }
);

async function onConfirm(payload) {
  if (submitting.value) return;
  submitting.value = true;
  try {
    const result = await props.submit(payload);
    emit('done', result);
  } catch (err) {
    if (err?.status === 409) emit('conflict', err);
    else toast.error(err?.errors ? Object.values(err.errors)[0]?.[0] ?? err.message : err?.message ?? t('page.load_failed'));
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <ReasonDialog
    v-if="loadState === 'ready'"
    :open="open"
    :title="title"
    :message="message"
    :confirm-label="confirmLabel"
    :reasons="reasons"
    :danger="danger"
    :submitting="submitting"
    @close="emit('close')"
    @confirm="onConfirm"
  />
  <BaseModal v-else :open="open" :title="title" max-width-class="max-w-[460px]" @close="emit('close')">
    <LoadingState v-if="loadState === 'loading'" :rows="3" />
    <ErrorState v-else-if="loadState === 'error'" @retry="loadReasons" />
    <p v-else class="px-6 py-8 text-center text-[13px] text-muted-4" data-testid="no-reasons">{{ t('products.reasons_empty') }}</p>
    <template #footer>
      <div class="flex justify-end"><BaseButton variant="secondary" @click="emit('close')">{{ t('page.cancel') }}</BaseButton></div>
    </template>
  </BaseModal>
</template>
