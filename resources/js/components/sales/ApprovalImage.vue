<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import ImageLightbox from '../ui/ImageLightbox.vue';
import { getApprovalImageBlobUrl } from '../../api/orderApprovals';

/**
 * 059 — thumbnail tanda tangan/gambar persetujuan (pola PaymentProofThumbnail): disimpan privat dan hanya
 * disajikan lewat endpoint berotorisasi, jadi diunduh dengan token menjadi blob URL, dibebaskan saat
 * `approvalId` berganti / komponen dilepas, jawaban usang dibuang, dan gagal memuat = placeholder netral.
 * Izin TIDAK diputuskan di sini: pemanggil hanya merendernya bila server menyatakan `can_view_image`.
 */
const props = defineProps({
  orderId: { type: [Number, String], required: true },
  approvalId: { type: [Number, String], required: true },
  alt: { type: String, default: '' },
  size: { type: Number, default: 72 },
});
const { t } = useI18n();

const url = ref(null);
const failed = ref(false);
const open = ref(false);
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
    const blobUrl = await getApprovalImageBlobUrl(props.orderId, props.approvalId);
    if (mine !== generation) {
      if (typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(blobUrl);
      return;
    }
    url.value = blobUrl;
  } catch {
    if (mine === generation) failed.value = true;
  }
}

watch(() => [props.orderId, props.approvalId], load, { immediate: true });
onBeforeUnmount(() => {
  generation += 1;
  revoke();
});
</script>

<template>
  <div>
    <button
      type="button"
      data-testid="approval-image"
      class="relative shrink-0 overflow-hidden rounded-lg border border-line-2 bg-white"
      :class="url ? 'cursor-zoom-in' : 'cursor-default'"
      :style="{ width: `${size * 1.6}px`, height: `${size}px` }"
      :aria-label="alt || t('approvals.image_open')"
      :disabled="!url"
      @click="open = true"
    >
      <img v-if="url" :src="url" alt="" class="h-full w-full object-contain" />
      <span v-else-if="failed" class="flex h-full w-full flex-col items-center justify-center gap-1 px-1 text-center text-[10px] leading-tight text-muted-3" data-testid="approval-image-unavailable">
        <i class="ph-duotone ph-image-broken text-[18px]" aria-hidden="true"></i>
        {{ t('approvals.image_unavailable') }}
      </span>
      <span v-else class="flex h-full w-full animate-pulse items-center justify-center text-muted-3" aria-hidden="true">
        <i class="ph-duotone ph-image text-[20px]"></i>
      </span>
    </button>
    <ImageLightbox :open="open" :src="url" :alt="alt" @close="open = false" />
  </div>
</template>
