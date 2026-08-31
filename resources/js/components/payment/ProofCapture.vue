<script setup>
import { ref, onBeforeUnmount } from 'vue';

const emit = defineEmits(['captured', 'cleared']);

const mode = ref('idle'); // idle | webcam | captured
const previewUrl = ref('');
const error = ref('');
const videoRef = ref(null);
const canvasRef = ref(null);
const fileInputRef = ref(null);
let stream = null;

const MAX_DIMENSION = 1280;
const JPEG_QUALITY = 0.82;

async function startWebcam() {
  error.value = '';
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    mode.value = 'webcam';
    await nextFrame();
    if (videoRef.value) {
      videoRef.value.srcObject = stream;
      await videoRef.value.play();
    }
  } catch {
    error.value = 'Kamera tidak dapat diakses. Gunakan opsi unggah berkas.';
  }
}

function nextFrame() {
  return new Promise((resolve) => requestAnimationFrame(resolve));
}

function stopWebcam() {
  stream?.getTracks().forEach((track) => track.stop());
  stream = null;
}

function cancelWebcam() {
  stopWebcam();
  mode.value = 'idle';
}

function drawToCompressedBlob(source, sourceWidth, sourceHeight) {
  const scale = Math.min(1, MAX_DIMENSION / Math.max(sourceWidth, sourceHeight));
  const canvas = canvasRef.value;
  canvas.width = Math.round(sourceWidth * scale);
  canvas.height = Math.round(sourceHeight * scale);
  const ctx = canvas.getContext('2d');
  ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
  return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY));
}

async function snap() {
  const video = videoRef.value;
  if (!video) return;
  const blob = await drawToCompressedBlob(video, video.videoWidth, video.videoHeight);
  stopWebcam();
  finishCapture(blob, 'webcam');
}

function triggerUpload() {
  fileInputRef.value?.click();
}

async function onFileChange(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  error.value = '';
  if (!['image/jpeg', 'image/png'].includes(file.type)) {
    error.value = 'Berkas harus berupa JPEG atau PNG.';
    return;
  }
  const bitmap = await createImageBitmap(file).catch(() => null);
  if (!bitmap) {
    error.value = 'Berkas gambar tidak dapat dibaca.';
    return;
  }
  const blob = await drawToCompressedBlob(bitmap, bitmap.width, bitmap.height);
  finishCapture(blob, 'upload');
}

function finishCapture(blob, capturedVia) {
  const file = new File([blob], `bukti-bayar-${Date.now()}.jpg`, { type: 'image/jpeg' });
  previewUrl.value = URL.createObjectURL(blob);
  mode.value = 'captured';
  emit('captured', file, capturedVia);
}

function retake() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
  previewUrl.value = '';
  mode.value = 'idle';
  emit('cleared');
}

onBeforeUnmount(() => {
  stopWebcam();
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});
</script>

<template>
  <div class="flex flex-col gap-3">
    <canvas ref="canvasRef" class="hidden" aria-hidden="true"></canvas>
    <input
      ref="fileInputRef"
      type="file"
      accept="image/jpeg,image/png"
      class="hidden"
      aria-hidden="true"
      tabindex="-1"
      @change="onFileChange"
    />

    <div v-if="mode === 'idle'" class="flex flex-col gap-2">
      <div class="flex gap-2">
        <button
          type="button"
          class="flex h-11 flex-1 items-center justify-center gap-2 rounded-lg border border-line bg-white text-[13px] font-bold text-muted-5 transition-colors hover:border-brand hover:text-brand-active"
          @click="startWebcam"
        >
          <i class="ph-duotone ph-camera text-[18px]" aria-hidden="true"></i>
          Ambil foto
        </button>
        <button
          type="button"
          class="flex h-11 flex-1 items-center justify-center gap-2 rounded-lg border border-line bg-white text-[13px] font-bold text-muted-5 transition-colors hover:border-brand hover:text-brand-active"
          @click="triggerUpload"
        >
          <i class="ph-duotone ph-upload-simple text-[18px]" aria-hidden="true"></i>
          Unggah berkas
        </button>
      </div>
      <p v-if="error" role="alert" class="text-[12px] font-medium text-danger-text">{{ error }}</p>
    </div>

    <div v-else-if="mode === 'webcam'" class="flex flex-col gap-2.5">
      <video ref="videoRef" class="w-full rounded-lg border border-line-2 bg-ink" muted playsinline></video>
      <div class="flex gap-2">
        <button
          type="button"
          class="h-11 flex-1 rounded-lg bg-brand text-[13px] font-bold text-white transition-colors hover:bg-brand-hover"
          @click="snap"
        >
          Jepret
        </button>
        <button
          type="button"
          class="h-11 flex-1 rounded-lg border border-line bg-white text-[13px] font-bold text-muted-5 hover:border-danger-border-hover hover:text-danger-text"
          @click="cancelWebcam"
        >
          Batal
        </button>
      </div>
    </div>

    <div v-else class="flex items-center gap-3 rounded-lg border border-mint-border bg-mint-50 px-3.5 py-3">
      <img :src="previewUrl" alt="Pratinjau bukti pembayaran" class="h-14 w-14 flex-none rounded-md object-cover" />
      <div class="flex flex-1 flex-col gap-0.5">
        <span class="text-[13px] font-bold text-brand-active">Bukti terlampir</span>
        <span class="text-[11.5px] text-muted-4">Siap dikirim bersama transaksi</span>
      </div>
      <button
        type="button"
        class="text-[12.5px] font-bold text-muted-4 underline decoration-dotted hover:text-danger-text"
        @click="retake"
      >
        Ambil ulang
      </button>
    </div>
  </div>
</template>
