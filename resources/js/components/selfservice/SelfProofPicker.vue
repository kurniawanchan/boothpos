<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';

// 090 — pilih foto bukti bayar: kamera (capture) atau galeri. `capture` pada input berkas membuka kamera ponsel dan tidak
// butuh HTTPS (tidak memakai getUserMedia), jadi tetap jalan saat QR membuka http://<IP-LAN>.
defineProps({
  previewUrl: { type: String, default: null },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
const emit = defineEmits(['pick']);
const { t } = useI18n();

const camera = ref(null);
const gallery = ref(null);

function onChange(event, via) {
  const file = event.target.files?.[0];
  event.target.value = ''; // memilih berkas yang sama dua kali tetap memicu change
  if (file) emit('pick', { file, via });
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div v-if="previewUrl" class="border border-line bg-line-7 p-2">
      <img :src="previewUrl" :alt="t('self_service.proof_preview_alt')" class="mx-auto max-h-64 w-auto object-contain" />
    </div>

    <div class="grid grid-cols-2 gap-2">
      <BaseButton variant="secondary" size="lg" :disabled="busy" @click="camera?.click()">
        <i class="ph-duotone ph-camera text-[18px]" aria-hidden="true"></i>
        {{ previewUrl ? t('self_service.proof_replace') : t('self_service.proof_camera') }}
      </BaseButton>
      <BaseButton variant="secondary" size="lg" :disabled="busy" @click="gallery?.click()">
        <i class="ph-duotone ph-image text-[18px]" aria-hidden="true"></i>
        {{ t('self_service.proof_upload') }}
      </BaseButton>
    </div>

    <input ref="camera" type="file" accept="image/*" capture="environment" class="hidden" data-testid="proof-camera" @change="onChange($event, 'webcam')" />
    <input ref="gallery" type="file" accept="image/*" class="hidden" data-testid="proof-gallery" @change="onChange($event, 'upload')" />

    <p v-if="busy" class="text-[12.5px] text-muted" role="status">{{ t('self_service.proof_processing') }}</p>
    <p v-if="error" class="text-[12.5px] font-semibold text-danger-text" role="alert">{{ error }}</p>
  </div>
</template>
