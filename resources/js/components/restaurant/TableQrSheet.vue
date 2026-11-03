<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';
import TableQrCard from './TableQrCard.vue';
import { qrDataUrl, qrFilename } from '../../utils/qrImage';
import { downloadElementAsPng } from '../../utils/pdfCapture';
import { printQrSheet } from '../../utils/qrSheetPdf';
import { useToastStore } from '../../stores/toast';

// 090 / US2 — QR SATU meja: tampil, unduh PNG berlabel, cetak, dan "Buat ulang" (mencabut QR lama). `item` =
// { table_id, outlet_name, table_name, area, url } dari POST /dining-tables/qr-batch.
const props = defineProps({
  open: { type: Boolean, default: false },
  item: { type: Object, default: null },
  rotating: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'rotate']);
const { t } = useI18n();
const toast = useToastStore();

const qr = ref(null);
const card = ref(null);
const failed = ref(false);

watch(
  () => [props.open, props.item?.url],
  async ([open, url]) => {
    qr.value = null;
    failed.value = false;
    if (!open || !url) return;
    try {
      qr.value = await qrDataUrl(url);
    } catch {
      failed.value = true;
    }
  },
  { immediate: true },
);

async function downloadPng() {
  try {
    await downloadElementAsPng(card.value?.$el ?? card.value, qrFilename([props.item.outlet_name, props.item.table_name], 'png'));
  } catch {
    toast.error(t('dining_setup.qr_download_failed'));
  }
}

function print() {
  if (!printQrSheet([{ ...props.item, qr: qr.value }], t('dining_setup.qr_caption'), t('dining_setup.qr_title'))) {
    toast.error(t('dining_setup.qr_print_blocked'));
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('dining_setup.qr_title')" max-width-class="max-w-[400px]" @close="emit('close')">
    <div class="flex flex-col items-center gap-3 px-6 py-5">
      <p v-if="failed" class="text-[13px] font-semibold text-danger-text" role="alert">{{ t('dining_setup.qr_failed') }}</p>
      <TableQrCard v-else-if="item" ref="card" :item="{ ...item, qr }" />
      <p class="text-center text-[11.5px] text-muted-3">{{ t('dining_setup.qr_remake_hint') }}</p>
    </div>
    <template #footer>
      <div class="flex flex-wrap justify-between gap-2.5">
        <BaseButton variant="danger" :loading="rotating" @click="emit('rotate')">{{ t('dining_setup.qr_remake') }}</BaseButton>
        <div class="flex gap-2.5">
          <BaseButton variant="secondary" :disabled="!qr" @click="print">{{ t('dining_setup.qr_print') }}</BaseButton>
          <BaseButton :disabled="!qr" @click="downloadPng">{{ t('dining_setup.qr_download_png') }}</BaseButton>
        </div>
      </div>
    </template>
  </BaseModal>
</template>
