<script setup>
import { useI18n } from 'vue-i18n';

/**
 * 022-preorder-invoice-crud-overhaul (US3, FR-011, research.md Decision 8)
 * — satu komponen kecil dan dipakai bersama di mana pun sebuah gambar
 * (QR pembayaran) perlu bisa diklik untuk tampilan ukuran penuh, supaya
 * mekanisme popup ini tidak diduplikasi per layar (Constitution I).
 */
defineProps({
  open: { type: Boolean, default: false },
  src: { type: String, default: null },
  alt: { type: String, default: '' },
});
const emit = defineEmits(['close']);
const { t } = useI18n();
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && src"
      class="fixed inset-0 z-[120] flex items-center justify-center bg-ink/75 p-6"
      @click.self="emit('close')"
    >
      <div class="relative">
        <button
          type="button"
          class="absolute -top-3 -right-3 flex h-8 w-8 items-center justify-center rounded-full bg-white text-ink shadow-lg"
          :aria-label="t('common.close')"
          @click="emit('close')"
        >
          <i class="ph-duotone ph-x text-[16px]" aria-hidden="true"></i>
        </button>
        <img :src="src" :alt="alt" class="max-h-[85vh] max-w-[85vw] rounded-lg bg-white p-4 object-contain shadow-2xl" />
      </div>
    </div>
  </Teleport>
</template>
