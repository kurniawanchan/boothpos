<script setup>
import { reactive, ref, computed, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import ImageLightbox from '../ui/ImageLightbox.vue';
import { getReferenceImageUrl } from '../../api/orders';

/**
 * 055 (US1) — gambar referensi transaksi sebagai thumbnail, dipakai detail penjualan
 * (TransactionItemsModal, hanya lihat) dan dialog referensi (OrderReferenceModal, dengan tombol hapus).
 *
 * Gambar tak bisa dimuat lewat <img src> biasa (endpoint mengotorisasi tiap permintaan), jadi tiap gambar
 * diunduh dengan token menjadi blob URL. Paling banyak MAX_IN_FLIGHT permintaan sekaligus supaya 10 gambar
 * tidak menahan bagian lain detail; gambar yang gagal menampilkan placeholder tanpa menghalangi yang lain.
 * Blob URL dibebaskan saat gambarnya hilang dari daftar, saat penjualan berganti, dan saat komponen dilepas.
 * Thumbnail = gambar asli yang ditampilkan kecil (tak ada berkas thumbnail; aplikasi berjalan di localhost).
 */
const props = defineProps({
  orderId: { type: [Number, String], required: true },
  images: { type: Array, default: () => [] },
  removable: { type: Boolean, default: false },
  // id gambar yang ditandai untuk dihapus (hanya dipakai bila `removable`)
  marked: { type: Array, default: () => [] },
});
const emit = defineEmits(['toggle-remove']);
const { t } = useI18n();

const MAX_IN_FLIGHT = 3;

// id → { url: string|null, failed: boolean }
const entries = reactive(new Map());
const queue = [];
let inFlight = 0;
let generation = 0; // naik setiap penjualan berganti, supaya jawaban lama dibuang

const lightboxOpen = ref(false);
const lightboxIndex = ref(0);

function revoke(url) {
  if (url && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url);
}

function pump() {
  while (inFlight < MAX_IN_FLIGHT && queue.length > 0) {
    const id = queue.shift();
    if (!entries.has(id)) continue; // dihapus dari daftar sebelum sempat diambil
    inFlight += 1;
    const gen = generation;
    getReferenceImageUrl(props.orderId, id)
      .then((url) => {
        if (gen !== generation || !entries.has(id)) return revoke(url);
        entries.set(id, { url, failed: false });
      })
      .catch(() => {
        if (gen === generation && entries.has(id)) entries.set(id, { url: null, failed: true });
      })
      .finally(() => {
        if (gen === generation) inFlight -= 1;
        pump();
      });
  }
}

function reset() {
  generation += 1;
  inFlight = 0;
  queue.length = 0;
  entries.forEach((e) => revoke(e.url));
  entries.clear();
}

watch(
  () => props.orderId,
  () => reset(),
);

watch(
  () => props.images.map((i) => i.id),
  (ids) => {
    const wanted = new Set(ids);
    for (const [id, entry] of [...entries]) {
      if (!wanted.has(id)) {
        revoke(entry.url);
        entries.delete(id);
      }
    }
    for (const id of ids) {
      if (!entries.has(id)) {
        entries.set(id, { url: null, failed: false });
        queue.push(id);
      }
    }
    pump();
  },
  { immediate: true },
);

onBeforeUnmount(() => reset());

const markedSet = computed(() => new Set(props.marked));
const loadedImages = computed(() =>
  props.images
    .map((img, i) => ({ id: img.id, src: entries.get(img.id)?.url, alt: t('sales_reference.image_number', { n: i + 1, total: props.images.length }) }))
    .filter((img) => img.src),
);

function openAt(id) {
  const i = loadedImages.value.findIndex((img) => img.id === id);
  if (i < 0) return;
  lightboxIndex.value = i;
  lightboxOpen.value = true;
}
</script>

<template>
  <ul v-if="images.length" class="flex flex-wrap gap-2" :aria-label="t('sales_reference.images_title')">
    <li
      v-for="(image, i) in images"
      :key="image.id"
      data-testid="reference-thumbnail"
      class="flex w-[88px] flex-col gap-1"
    >
      <button
        type="button"
        class="relative h-[88px] w-[88px] overflow-hidden rounded-lg border border-line-2 bg-surface-subtle"
        :class="[entries.get(image.id)?.url ? 'cursor-zoom-in' : 'cursor-default', markedSet.has(image.id) ? 'opacity-40' : '']"
        :aria-label="t('sales_reference.image_number', { n: i + 1, total: images.length })"
        :disabled="!entries.get(image.id)?.url"
        @click="openAt(image.id)"
      >
        <img
          v-if="entries.get(image.id)?.url"
          :src="entries.get(image.id).url"
          :alt="t('sales_reference.image_number', { n: i + 1, total: images.length })"
          class="h-full w-full object-cover"
        />
        <span v-else-if="entries.get(image.id)?.failed" class="flex h-full w-full flex-col items-center justify-center gap-1 px-1 text-center text-[10.5px] leading-tight text-muted-3">
          <i class="ph-duotone ph-image-broken text-[20px]" aria-hidden="true"></i>
          {{ t('sales_reference.image_unavailable') }}
        </span>
        <span v-else class="flex h-full w-full items-center justify-center text-muted-3" aria-hidden="true">
          <i class="ph-duotone ph-spinner-gap animate-spin text-[18px]"></i>
        </span>
      </button>

      <template v-if="removable">
        <button
          v-if="markedSet.has(image.id)"
          type="button"
          class="text-left text-[11px] font-semibold text-brand-active hover:underline"
          @click="emit('toggle-remove', image.id)"
        >
          {{ t('sales_reference.undo_remove') }}
        </button>
        <button
          v-else
          type="button"
          class="text-left text-[11px] font-semibold text-danger-text hover:underline"
          @click="emit('toggle-remove', image.id)"
        >
          {{ t('sales_reference.remove_image') }}
        </button>
        <span v-if="markedSet.has(image.id)" class="text-[10.5px] leading-tight text-muted-3">{{ t('sales_reference.marked_for_removal') }}</span>
      </template>
    </li>
  </ul>

  <ImageLightbox
    :open="lightboxOpen"
    :images="loadedImages"
    :index="lightboxIndex"
    @update:index="lightboxIndex = $event"
    @close="lightboxOpen = false"
  />
</template>
