<script setup>
import { defineAsyncComponent, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ApprovalStamp from './ApprovalStamp.vue';
import { useAuthStore } from '../../stores/auth';

// Kanvas hanya dimuat saat dialog yang memakainya dibuka (bukan di bundel Sales) — Constitution V.
import SignaturePadFallback from '../settlement/SignaturePadFallback.vue';
const SignaturePad = defineAsyncComponent({
  loader: () => import('../settlement/SignaturePad.vue'),
  errorComponent: SignaturePadFallback,
  timeout: 15000,
});

/**
 * 064 — pemilih metode persetujuan (gambar / unggah / stempel) yang DIPAKAI BERSAMA oleh dialog persetujuan satu
 * seller (059) dan persetujuan massal: satu implementasi aturan berkas (PNG/JPG, 5 MB) dan satu penanganan kanvas.
 *
 * ATURAN YANG HARUS DIJAGA PEMANGGIL: sembunyikan pemilih ini dengan `v-show` (JANGAN `v-if`) di langkah
 * konfirmasi. Melepas kanvas membuang goresannya, dan `getImage()` lalu tak bisa membaca tanda tangannya
 * (bug yang ditemukan di 059: `toBlob()` mengembalikan kosong).
 *
 * Pemanggil memakai: `validate()` (sebelum ke langkah konfirmasi), `getImage()` (saat mengirim), `reset()` (saat dialog
 * dibuka), dan menampilkan pesan dari event `error`.
 */
const props = defineProps({
  method: { type: String, default: 'draw' },
  /** Teks nama seller pada pratinjau stempel (dialog massal memakai label umum, bukan satu seller). */
  sellerLabel: { type: String, default: '' },
});
const emit = defineEmits(['update:method', 'error']);

const { t } = useI18n();
const auth = useAuthStore();

const METHODS = ['draw', 'upload', 'stamp'];
const MAX_BYTES = 5 * 1024 * 1024;
const ALLOWED = ['image/png', 'image/jpeg'];

const hasInk = ref(false);
const pad = ref(null);
const file = ref(null);
const today = new Date().toLocaleDateString();

function setError(message) {
  emit('error', message);
}

function choose(m) {
  emit('update:method', m);
}

watch(
  () => props.method,
  () => {
    setError('');
    hasInk.value = false; // berpindah tab melepas kanvas (goresan hilang), jadi penanda tinta ikut direset
  },
);

function onFile(event) {
  setError('');
  const picked = event.target.files?.[0] ?? null;
  event.target.value = ''; // memilih berkas yang sama dua kali tetap memicu change
  if (!picked) return;
  if (!ALLOWED.includes(picked.type)) {
    file.value = null;
    setError(t('approvals.file_type_error'));
  } else if (picked.size > MAX_BYTES) {
    file.value = null;
    setError(t('approvals.file_size_error'));
  } else {
    file.value = picked;
  }
}

/** true bila masukan metode terpilih lengkap; bila tidak, memancarkan pesannya dan mengembalikan false. */
function validate() {
  setError('');
  if (props.method === 'draw' && !hasInk.value) {
    setError(t('approvals.signature_required'));
    return false;
  }
  if (props.method === 'upload' && !file.value) {
    setError(t('approvals.image_required'));
    return false;
  }
  return true;
}

/**
 * Gambar untuk dikirim: File (draw → PNG dari kanvas, upload → berkas pilihan), `null` untuk stempel, `undefined` bila
 * kanvas tak menghasilkan apa-apa (pemanggil kembali ke langkah memilih).
 */
async function getImage() {
  if (props.method === 'draw') {
    const blob = await pad.value?.toBlob();
    return blob ? new File([blob], 'signature.png', { type: 'image/png' }) : undefined;
  }
  if (props.method === 'upload') return file.value;
  return null;
}

function reset() {
  hasInk.value = false;
  file.value = null;
  setError('');
}

defineExpose({ validate, getImage, reset, setError });
</script>

<template>
  <div class="flex flex-col gap-4">
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
        @click="choose(m)"
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
      <ApprovalStamp :seller="sellerLabel" :approver="auth.user?.name || ''" :date="today" />
    </div>
  </div>
</template>
