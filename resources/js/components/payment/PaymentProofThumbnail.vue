<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { getPaymentProofBlobUrl } from '../../api/payments';

/**
 * 056 — thumbnail bukti pembayaran, dipakai detail penjualan (PaymentHistoryList) DAN dialog
 * "Payment Proof" di daftar Sales (PaymentProofsModal): satu komponen, satu cara memuat.
 *
 * Bukti disimpan di disk privat dan hanya disajikan lewat endpoint berotorisasi, jadi tak bisa
 * dipakai langsung sebagai <img src>; diunduh dengan token menjadi blob URL, lalu dibebaskan saat
 * `proofId` berganti (bukti diganti → ambil ulang) dan saat komponen dilepas. Jawaban untuk
 * proofId yang sudah usang dibuang. Gagal memuat = placeholder netral, bukan gambar rusak, dan
 * tidak mengganggu bagian lain dari dialog. Thumbnail = gambar asli yang ditampilkan kecil.
 * Izin TIDAK diputuskan di sini: pemanggil hanya merender ini untuk bukti yang server nyatakan
 * boleh dilihat (`can_view_proof`).
 */
const props = defineProps({
  proofId: { type: [Number, String], required: true },
  alt: { type: String, default: '' },
  size: { type: Number, default: 64 },
});
const emit = defineEmits(['open', 'loaded']);
const { t } = useI18n();

const url = ref(null);
const failed = ref(false);
let generation = 0;

function revoke() {
  if (url.value && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url.value);
  url.value = null;
}

async function load() {
  generation += 1;
  const mine = generation;
  revoke();
  failed.value = false;
  try {
    const blobUrl = await getPaymentProofBlobUrl(props.proofId);
    if (mine !== generation) {
      if (typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(blobUrl);
      return;
    }
    url.value = blobUrl;
    emit('loaded', blobUrl);
  } catch {
    if (mine === generation) failed.value = true;
  }
}

watch(() => props.proofId, load, { immediate: true });
onBeforeUnmount(() => {
  generation += 1;
  revoke();
});
</script>

<template>
  <button
    type="button"
    data-testid="payment-proof-thumbnail"
    class="relative shrink-0 overflow-hidden rounded-lg border border-line-2 bg-surface-subtle"
    :class="url ? 'cursor-zoom-in' : 'cursor-default'"
    :style="{ width: `${size}px`, height: `${size}px` }"
    :aria-label="alt || t('payment_proof.open')"
    :disabled="!url"
    @click="emit('open')"
  >
    <!-- alt kosong: nama aksesibelnya sudah di tombol (aria-label); alt ganda hanya membuat pembaca layar mengulang. -->
    <img v-if="url" :src="url" alt="" class="h-full w-full object-cover" />
    <span v-else-if="failed" class="flex h-full w-full flex-col items-center justify-center gap-1 px-1 text-center text-[10px] leading-tight text-muted-3" data-testid="payment-proof-unavailable">
      <i class="ph-duotone ph-image-broken text-[18px]" aria-hidden="true"></i>
      {{ t('payment_proof.unavailable') }}
    </span>
    <span v-else class="flex h-full w-full animate-pulse items-center justify-center text-muted-3" data-testid="payment-proof-loading" aria-hidden="true">
      <i class="ph-duotone ph-image text-[20px]"></i>
    </span>
  </button>
</template>
