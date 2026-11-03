<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';
import TableQrCard from './TableQrCard.vue';
import { qrFilename, withQrImages } from '../../utils/qrImage';
import { downloadQrSheetPdf, PER_PAGE, printQrSheet } from '../../utils/qrSheetPdf';
import { useToastStore } from '../../stores/toast';

// 090 / US2 — QR BANYAK meja dalam satu lembar siap cetak (PDF berhalaman A4 atau jendela cetak). `items` dari
// POST /dining-tables/qr-batch; gambar QR dirender di sini (pustaka dimuat dinamis).
const props = defineProps({
  open: { type: Boolean, default: false },
  items: { type: Array, default: () => [] },
  skipped: { type: Number, default: 0 },
});
const emit = defineEmits(['close']);
const { t } = useI18n();
const toast = useToastStore();

const rendered = ref([]);
const generating = ref(false);
const failed = ref(false);
const saving = ref(false);

watch(
  () => [props.open, props.items],
  async ([open]) => {
    rendered.value = [];
    failed.value = false;
    if (!open || !props.items.length) return;
    generating.value = true;
    try {
      rendered.value = await withQrImages(props.items);
    } catch {
      failed.value = true;
    } finally {
      generating.value = false;
    }
  },
  { immediate: true },
);

const pages = computed(() => Math.ceil(props.items.length / PER_PAGE));
const preview = computed(() => rendered.value.slice(0, 6)); // pratinjau ringan; berkas tetap memuat SEMUA

async function pdf() {
  saving.value = true;
  try {
    await downloadQrSheetPdf(rendered.value, qrFilename(['qr-meja', rendered.value.length], 'pdf'), t('dining_setup.qr_caption'));
  } catch {
    toast.error(t('dining_setup.qr_download_failed'));
  } finally {
    saving.value = false;
  }
}

function print() {
  if (!printQrSheet(rendered.value, t('dining_setup.qr_caption'), t('dining_setup.qr_bulk_title'))) {
    toast.error(t('dining_setup.qr_print_blocked'));
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('dining_setup.qr_bulk_title')" max-width-class="max-w-[640px]" @close="emit('close')">
    <div class="flex flex-col gap-3 px-6 py-5">
      <p class="text-[13px] font-semibold text-ink" data-testid="qr-bulk-count">{{ t('dining_setup.qr_bulk_summary', { count: items.length, pages }) }}</p>
      <p v-if="skipped" class="bg-warn-bg px-3 py-2 text-[12.5px] font-semibold text-warn-text" data-testid="qr-skipped">{{ t('dining_setup.qr_skipped_inactive', { count: skipped }) }}</p>
      <p v-if="generating" class="text-[12.5px] text-muted" role="status">{{ t('dining_setup.qr_generating') }}</p>
      <p v-if="failed" class="text-[13px] font-semibold text-danger-text" role="alert">{{ t('dining_setup.qr_failed') }}</p>
      <div v-if="preview.length" class="grid grid-cols-2 gap-3 justify-items-center">
        <TableQrCard v-for="it in preview" :key="it.table_id" :item="it" />
      </div>
      <p v-if="rendered.length > preview.length" class="text-center text-[12px] text-muted-3">{{ t('dining_setup.qr_bulk_more', { count: rendered.length - preview.length }) }}</p>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.close') }}</BaseButton>
        <BaseButton variant="secondary" :disabled="!rendered.length" @click="print">{{ t('dining_setup.qr_print') }}</BaseButton>
        <BaseButton :disabled="!rendered.length" :loading="saving" @click="pdf">{{ t('dining_setup.qr_download_pdf') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
