<script setup>
import { ref, useId } from 'vue';

/**
 * 037 — tooltip kecil yang bisa dicapai papan ketik. Atribut `title` native
 * tidak muncul saat fokus papan ketik dan tidak bisa digayakan, jadi tidak
 * cukup untuk penjelasan seperti "apa itu BOM". Muncul saat kursor di atas
 * pemicu ATAU fokus berada di dalamnya; Escape menutupnya. Gelembung tetap
 * ada di DOM (v-show) dan dikaitkan lewat aria-describedby supaya pembaca
 * layar membacanya sebagai deskripsi pemicu.
 */
defineProps({
  text: { type: String, required: true },
  // 'left' = gelembung rata kiri pemicu; 'right' = rata kanan (untuk pemicu di tepi kanan panel).
  align: { type: String, default: 'left', validator: (v) => ['left', 'right'].includes(v) },
});

const id = useId();
const open = ref(false);
</script>

<template>
  <span
    class="relative inline-flex"
    :aria-describedby="id"
    @mouseenter="open = true"
    @mouseleave="open = false"
    @focusin="open = true"
    @focusout="open = false"
    @keydown.esc="open = false"
  >
    <slot />
    <span
      v-show="open"
      :id="id"
      role="tooltip"
      :aria-hidden="!open"
      :class="align === 'right' ? 'right-0' : 'left-0'"
      class="absolute top-full z-[95] mt-1.5 w-64 rounded-lg bg-ink px-3 py-2 text-left text-[12px] font-medium leading-snug text-white shadow-lg"
    >{{ text }}</span>
  </span>
</template>
