<script setup>
import { computed, onBeforeUnmount, ref, useId } from 'vue';

/**
 * 037 — tooltip kecil yang bisa dicapai papan ketik. Atribut `title` native
 * tidak muncul saat fokus papan ketik dan tidak bisa digayakan, jadi tidak
 * cukup untuk penjelasan seperti "apa itu BOM". Muncul saat kursor di atas
 * pemicu ATAU fokus berada di dalamnya; Escape menutupnya. Gelembung tetap
 * ada di DOM (v-show) dan dikaitkan lewat aria-describedby supaya pembaca
 * layar membacanya sebagai deskripsi pemicu.
 *
 * BUG YANG DITEMUKAN & DIPERBAIKI (038): gelembung dulu `absolute` di dalam
 * pembungkusnya. Di daftar produk, pemicunya ada di dalam DataTable yang
 * dibungkus `overflow-auto`, jadi gelembung terpotong (dan baris terakhir
 * memunculkan scrollbar). Kini gelembung di-teleport ke <body> dengan posisi
 * `fixed` yang dihitung dari kotak pemicu saat dibuka — di bawah pemicu,
 * berbalik ke ATAS bila ruang bawah sempit, dan dijepit di dalam viewport
 * (teknik yang sama dengan BaseSelect). Ditutup saat halaman digulung /
 * ukuran berubah supaya gelembung fixed tidak melayang di tempat yang salah.
 * Teks kosong tidak pernah menampilkan gelembung.
 */
const props = defineProps({
  text: { type: String, default: '' },
  // 'left' = gelembung rata kiri pemicu; 'right' = rata kanan (untuk pemicu di tepi kanan panel).
  align: { type: String, default: 'left', validator: (v) => ['left', 'right'].includes(v) },
});

const WIDTH = 256; // = w-64
const MARGIN = 8;
const GAP = 6;
const MIN_BELOW = 90;

const id = useId();
const open = ref(false);
const wrapperEl = ref(null);
const style = ref({});
const visible = computed(() => open.value && props.text.trim() !== '');

function updatePosition() {
  if (!wrapperEl.value) return;
  const r = wrapperEl.value.getBoundingClientRect();
  const below = window.innerHeight - r.bottom;
  const above = r.top;
  const openUp = below < MIN_BELOW && above > below;
  const wanted = props.align === 'right' ? r.right - WIDTH : r.left;
  const left = Math.max(MARGIN, Math.min(wanted, window.innerWidth - MARGIN - WIDTH));

  style.value = {
    position: 'fixed',
    left: `${left}px`,
    ...(openUp ? { bottom: `${window.innerHeight - r.top + GAP}px` } : { top: `${r.bottom + GAP}px` }),
  };
}

function close() {
  open.value = false;
  window.removeEventListener('scroll', close, true);
  window.removeEventListener('resize', close);
}

function show() {
  if (!props.text.trim()) return;
  updatePosition();
  open.value = true;
  window.addEventListener('scroll', close, true);
  window.addEventListener('resize', close);
}

onBeforeUnmount(close);
</script>

<template>
  <span
    ref="wrapperEl"
    class="relative inline-flex"
    :aria-describedby="id"
    @mouseenter="show"
    @mouseleave="close"
    @focusin="show"
    @focusout="close"
    @keydown.esc="close"
  >
    <slot />
    <Teleport to="body">
      <span
        v-show="visible"
        :id="id"
        role="tooltip"
        :aria-hidden="!visible"
        :style="style"
        class="z-[95] w-64 rounded-lg bg-ink px-3 py-2 text-left text-[12px] font-medium leading-snug text-white shadow-lg"
      >{{ text }}</span>
    </Teleport>
  </span>
</template>
