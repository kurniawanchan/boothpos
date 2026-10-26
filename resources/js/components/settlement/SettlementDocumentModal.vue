<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  getSettlementDocument, getSettlementPaymentDocument, getAcknowledgementSignature,
} from '../../api/settlements';
import { downloadElementAsPdf, downloadElementAsPng } from '../../utils/pdfCapture';
import { printElement } from '../../utils/printElement';
import { useToastStore } from '../../stores/toast';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import SettlementProofDocument from './SettlementProofDocument.vue';
import SettlementPaymentProofDocument from './SettlementPaymentProofDocument.vue';

/**
 * 076 (US3) — satu dialog untuk kedua dokumen (bukti settlement / bukti
 * pembayaran): memuat bahan dokumen dari server, lalu mencetak atau mengunduh
 * (PDF/PNG) di browser lewat utils/pdfCapture — seperti dokumen lain di
 * aplikasi, tanpa PDF server. Tanda tangan diambil lewat endpoint yang
 * memeriksa hak akses dan diubah menjadi data URL, supaya pdfCapture
 * menukar gambar berdasarkan `src`-nya sendiri.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  kind: { type: String, default: 'settlement' }, // settlement | payment
  settlementId: { type: Number, required: true },
  paymentId: { type: Number, default: null },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const toast = useToastStore();

const doc = ref(null);
const signatureSrc = ref(null);
const loading = ref(false);
const loadError = ref(false);
const includeCost = ref(false);
const working = ref(false);
const paper = ref(null);

function blobToDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onloadend = () => resolve(reader.result);
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}

async function load() {
  loading.value = true;
  loadError.value = false;
  signatureSrc.value = null;
  try {
    if (props.kind === 'payment') {
      doc.value = (await getSettlementPaymentDocument(props.settlementId, props.paymentId)).data;
    } else {
      doc.value = (await getSettlementDocument(props.settlementId, includeCost.value)).data;
      const ack = doc.value.acknowledgement;
      if (ack?.has_signature) {
        signatureSrc.value = await blobToDataUrl(await getAcknowledgementSignature(props.settlementId, ack.id));
      }
    }
  } catch {
    // Gagal memuat = status gagal + Coba lagi, bukan dokumen kosong.
    loadError.value = true;
    doc.value = null;
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    includeCost.value = false;
    load();
  },
  { immediate: true },
);

const title = computed(() => t(props.kind === 'payment' ? 'settlements.doc_title_payment' : 'settlements.doc_title_settlement'));
const fileStem = computed(() => `${props.kind === 'payment' ? 'bukti-bayar' : 'bukti-settlement'}-${doc.value?.settlement?.number ?? props.settlementId}`);

async function download(kindOfFile) {
  if (!paper.value) return;
  working.value = true;
  try {
    if (kindOfFile === 'pdf') await downloadElementAsPdf(paper.value, `${fileStem.value}.pdf`);
    else await downloadElementAsPng(paper.value, `${fileStem.value}.png`);
  } catch {
    toast.error(t('settlements.doc_download_failed'));
  } finally {
    working.value = false;
  }
}

function print() {
  printElement(paper.value, fileStem.value);
}
</script>

<template>
  <BaseModal :open="open" :title="title" max-width-class="max-w-[820px]" @close="emit('close')">
    <div class="flex flex-col gap-3 bg-surface-subtle p-4">
      <label v-if="kind === 'settlement'" class="flex items-start gap-2 text-[12.5px] font-semibold text-muted-4">
        <input v-model="includeCost" type="checkbox" class="mt-0.5" data-testid="include-cost" @change="load" />
        <span>
          {{ t('settlements.doc_include_cost') }}
          <span class="block text-[11.5px] font-normal text-muted-3">{{ t('settlements.doc_include_cost_hint') }}</span>
        </span>
      </label>

      <p v-if="loading" class="text-[13px] text-muted-3">{{ t('settlements.doc_loading') }}</p>

      <p v-else-if="loadError" role="alert" class="rounded-lg bg-danger-bg px-3.5 py-2.5 text-[13px] font-medium text-danger-text">
        {{ t('settlements.doc_failed') }}
        <button type="button" class="ml-2 font-bold underline" @click="load">{{ t('common.retry') }}</button>
      </p>

      <div v-else-if="doc" ref="paper" class="mx-auto w-full max-w-[720px] overflow-hidden rounded-lg border border-line-2 bg-white shadow-sm">
        <SettlementProofDocument v-if="kind === 'settlement'" :doc="doc" :signature-src="signatureSrc" />
        <SettlementPaymentProofDocument v-else :doc="doc" />
      </div>
    </div>
    <template #footer>
      <div class="flex flex-wrap justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.close') }}</BaseButton>
        <BaseButton variant="secondary" :disabled="!doc || working" @click="print">
          <i class="ph-duotone ph-printer text-[15px]" aria-hidden="true"></i>
          {{ t('settlements.doc_print') }}
        </BaseButton>
        <BaseButton variant="secondary" :disabled="!doc || working" :loading="working" @click="download('png')">{{ t('settlements.doc_download_png') }}</BaseButton>
        <BaseButton :disabled="!doc || working" :loading="working" @click="download('pdf')">{{ t('settlements.doc_download_pdf') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
