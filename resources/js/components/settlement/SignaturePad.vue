<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

// 076 (US3) — kanvas tanda tangan tanpa dependensi: pointer events (jari,
// stylus, mouse satu jalur), hasilnya PNG. Warna goresan dibaca dari warna
// teks kanvas (kelas `text-ink`) supaya mengikuti token desain, bukan hex.
// Bukan tanda tangan elektronik tersertifikasi — hanya gambar yang disimpan
// bersama nama dan waktu pengakuan.
const emit = defineEmits(['change']);
const { t } = useI18n();

const canvas = ref(null);
const hasInk = ref(false);
let ctx = null;
let drawing = false;

function setup() {
  const el = canvas.value;
  if (!el) return;
  const ratio = window.devicePixelRatio || 1;
  const rect = el.getBoundingClientRect();
  el.width = Math.max(1, Math.round((rect.width || 480) * ratio));
  el.height = Math.max(1, Math.round((rect.height || 160) * ratio));
  ctx = el.getContext?.('2d') ?? null; // jsdom tidak punya canvas: aman jadi no-op
  if (ctx) {
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = getComputedStyle(el).color;
  }
}

onMounted(setup);
onBeforeUnmount(() => {
  drawing = false;
});

function point(event) {
  const rect = canvas.value.getBoundingClientRect();
  return { x: event.clientX - rect.left, y: event.clientY - rect.top };
}

function onDown(event) {
  drawing = true;
  canvas.value.setPointerCapture?.(event.pointerId);
  const { x, y } = point(event);
  ctx?.beginPath();
  ctx?.moveTo(x, y);
  // Titik tunggal (ketukan) juga dihitung sebagai goresan.
  ctx?.lineTo(x + 0.01, y + 0.01);
  ctx?.stroke();
  markInk();
}

function onMove(event) {
  if (!drawing) return;
  const { x, y } = point(event);
  ctx?.lineTo(x, y);
  ctx?.stroke();
  markInk();
}

function onUp() {
  drawing = false;
}

function markInk() {
  if (!hasInk.value) {
    hasInk.value = true;
    emit('change', true);
  }
}

function clear() {
  const el = canvas.value;
  if (ctx && el) {
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, el.width, el.height);
    ctx.restore();
  }
  ctx?.beginPath();
  if (hasInk.value) {
    hasInk.value = false;
    emit('change', false);
  }
}

/** PNG dari kanvas; null bila kosong atau peramban tidak bisa membuatnya. */
function toBlob() {
  if (!hasInk.value || !canvas.value?.toBlob) return Promise.resolve(null);
  return new Promise((resolve) => canvas.value.toBlob((blob) => resolve(blob), 'image/png'));
}

defineExpose({ clear, toBlob, hasInk });
</script>

<template>
  <div class="flex flex-col gap-2">
    <div class="relative overflow-hidden rounded-lg border border-line bg-white">
      <canvas
        ref="canvas"
        class="block h-[160px] w-full touch-none text-ink"
        role="img"
        :aria-label="t('settlements.ack_sign_here')"
        data-testid="signature-canvas"
        @pointerdown.prevent="onDown"
        @pointermove.prevent="onMove"
        @pointerup="onUp"
        @pointerleave="onUp"
        @pointercancel="onUp"
      ></canvas>
      <span v-if="!hasInk" class="pointer-events-none absolute inset-0 flex items-center justify-center text-[12.5px] text-muted-3">
        {{ t('settlements.ack_sign_here') }}
      </span>
    </div>
    <button type="button" class="self-start text-[12.5px] font-semibold text-muted-4 underline hover:text-brand-active" @click="clear">
      {{ t('settlements.ack_clear') }}
    </button>
  </div>
</template>
