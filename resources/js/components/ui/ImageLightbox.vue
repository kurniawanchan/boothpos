<script setup>
import { computed, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * 022-preorder-invoice-crud-overhaul (US3, FR-011, research.md Decision 8)
 * — satu komponen kecil dan dipakai bersama di mana pun sebuah gambar
 * (QR pembayaran) perlu bisa diklik untuk tampilan ukuran penuh, supaya
 * mekanisme popup ini tidak diduplikasi per layar (Constitution I).
 *
 * 055 — mode GALERI opsional untuk gambar referensi transaksi: `images`
 * (`[{ src, alt }]`) + `v-model:index`. Dengan ≥ 2 gambar muncul tombol
 * sebelumnya/berikutnya, penghitung "n / total", dan panah ←/→. Navigasi
 * BERHENTI di ujung (tidak memutar) supaya pengguna tahu gambar sudah habis.
 * Pemanggil lama yang hanya mengirim `src` tidak berubah sama sekali.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  src: { type: String, default: null },
  alt: { type: String, default: '' },
  images: { type: Array, default: null },
  index: { type: Number, default: 0 },
});
const emit = defineEmits(['close', 'update:index']);
const { t } = useI18n();

const gallery = computed(() => Array.isArray(props.images) && props.images.length > 0);
const current = computed(() => {
  if (!gallery.value) return { src: props.src, alt: props.alt };
  const i = Math.min(Math.max(props.index, 0), props.images.length - 1);
  return props.images[i];
});
const navigable = computed(() => gallery.value && props.images.length > 1);
const atStart = computed(() => props.index <= 0);
const atEnd = computed(() => gallery.value && props.index >= props.images.length - 1);

function step(delta) {
  if (!navigable.value) return;
  const next = props.index + delta;
  if (next < 0 || next > props.images.length - 1) return;
  emit('update:index', next);
}

function onKey(e) {
  if (e.key === 'ArrowRight') step(1);
  else if (e.key === 'ArrowLeft') step(-1);
}

// Panah hanya didengar selama galeri terbuka; tanpa galeri tak ada pendengar sama sekali.
watch(
  () => props.open && navigable.value,
  (listen) => {
    if (listen) window.addEventListener('keydown', onKey);
    else window.removeEventListener('keydown', onKey);
  },
  { immediate: true },
);
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && current?.src"
      class="fixed inset-0 z-[120] flex items-center justify-center bg-ink/75 p-6"
      @click.self="emit('close')"
    >
      <div class="relative">
        <button
          type="button"
          class="absolute -top-3 -right-3 z-10 flex h-8 w-8 items-center justify-center rounded-full bg-white text-ink shadow-lg"
          :aria-label="t('common.close')"
          @click="emit('close')"
        >
          <i class="ph-duotone ph-x text-[16px]" aria-hidden="true"></i>
        </button>
        <img :src="current.src" :alt="current.alt ?? ''" class="max-h-[85vh] max-w-[85vw] rounded-lg bg-white p-4 object-contain shadow-2xl" />

        <template v-if="navigable">
          <button
            type="button"
            class="absolute left-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white text-ink shadow-lg disabled:opacity-40"
            :aria-label="t('common.previous')"
            :disabled="atStart"
            @click="step(-1)"
          >
            <i class="ph-duotone ph-caret-left text-[18px]" aria-hidden="true"></i>
          </button>
          <button
            type="button"
            class="absolute right-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white text-ink shadow-lg disabled:opacity-40"
            :aria-label="t('common.next')"
            :disabled="atEnd"
            @click="step(1)"
          >
            <i class="ph-duotone ph-caret-right text-[18px]" aria-hidden="true"></i>
          </button>
          <span class="absolute bottom-6 left-1/2 -translate-x-1/2 rounded-full bg-ink/70 px-3 py-1 text-[12px] font-semibold text-white" aria-live="polite">
            {{ index + 1 }} / {{ images.length }}
          </span>
        </template>
      </div>
    </div>
  </Teleport>
</template>
