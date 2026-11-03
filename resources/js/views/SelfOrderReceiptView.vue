<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { getReceipt } from '../api/selfService';
import BaseButton from '../components/ui/BaseButton.vue';
import SelfServiceReceiptDocument from '../components/selfservice/SelfServiceReceiptDocument.vue';
import { usePolling } from '../composables/usePolling';
import { i18n, loadLocale } from '../i18n';
import { LAST_TABLE_KEY } from '../stores/selfService';
import { downloadElementAsPdf, downloadElementAsPng } from '../utils/pdfCapture';
import { qrFilename } from '../utils/qrImage';

// 090 / US3 — struk pelanggan setelah mengirim pesanan. Halaman PUBLIK yang dibuka lewat token struk acak (bukan id): bisa
// dibuka ulang dari perangkat yang sama, menunjukkan status terbaru, dan disimpan sebagai gambar atau PDF. Status diperbarui
// otomatis selama belum final (tidak membebani server: berhenti saat tab tersembunyi dan setelah dikirim ke dapur/ditolak).
const route = useRoute();
const { t } = useI18n();

const receipt = ref(null);
const state = ref('loading'); // loading | ready | notfound | error
const saving = ref('');
const saveError = ref(false);
const doc = ref(null);

const FINAL = ['dispatched', 'rejected'];
const isFinal = computed(() => receipt.value !== null && FINAL.includes(receipt.value.status));

async function fetchOnce() {
  try {
    receipt.value = await getReceipt(String(route.params.receiptToken));
    state.value = 'ready';
  } catch (e) {
    // Penyegaran yang gagal tidak menghapus struk yang sudah tampil; hanya muatan pertama yang menentukan status halaman.
    if (receipt.value === null) state.value = e.status === 404 ? 'notfound' : 'error';
    throw e;
  }
}

const polling = usePolling(async () => {
  await fetchOnce();
  if (isFinal.value) polling.stop();
}, { interval: 15000, immediate: false });

onMounted(async () => {
  await loadLocale('id');
  i18n.global.locale.value = 'id';
  try {
    await fetchOnce();
  } catch {
    /* status sudah ditetapkan di fetchOnce */
  }
  if (state.value === 'ready' && !isFinal.value) polling.start();
});
onBeforeUnmount(() => polling.stop());

async function save(kind) {
  saving.value = kind;
  saveError.value = false;
  const el = doc.value?.$el ?? doc.value;
  try {
    const filename = qrFilename(['struk', receipt.value.reference_no], kind === 'pdf' ? 'pdf' : 'png');
    await (kind === 'pdf' ? downloadElementAsPdf(el, filename) : downloadElementAsPng(el, filename));
  } catch {
    saveError.value = true;
  } finally {
    saving.value = '';
  }
}

const orderAgainPath = computed(() => {
  try {
    const token = localStorage.getItem(LAST_TABLE_KEY);
    return token ? `/pesan/${token}` : null;
  } catch {
    return null;
  }
});
</script>

<template>
  <div class="min-h-screen bg-canvas">
    <main class="mx-auto flex min-h-screen w-full max-w-xl flex-col gap-4 bg-surface px-4 py-5">
      <p v-if="state === 'loading'" class="py-16 text-center text-[13px] text-muted" role="status">{{ t('self_service.receipt_loading') }}</p>

      <div v-else-if="state === 'notfound'" class="flex flex-col items-center gap-3 py-16 text-center" role="alert" data-testid="receipt-notfound">
        <i class="ph-duotone ph-receipt-x text-[40px] text-warn-text" aria-hidden="true"></i>
        <p class="max-w-xs text-[14px] font-semibold text-ink">{{ t('self_service.receipt_not_found') }}</p>
      </div>

      <div v-else-if="state === 'error'" class="flex flex-col items-center gap-3 py-16 text-center" role="alert">
        <p class="max-w-xs text-[14px] font-semibold text-ink">{{ t('self_service.error_network') }}</p>
        <BaseButton variant="secondary" @click="fetchOnce().catch(() => {})">{{ t('self_service.retry') }}</BaseButton>
      </div>

      <template v-else-if="receipt">
        <div class="border border-line">
          <SelfServiceReceiptDocument ref="doc" :receipt="receipt" />
        </div>
        <p v-if="!isFinal" class="text-center text-[11.5px] text-muted">{{ t('self_service.receipt_auto_refresh') }}</p>

        <div class="grid grid-cols-2 gap-2">
          <BaseButton variant="secondary" size="lg" :loading="saving === 'png'" :disabled="saving !== ''" data-testid="save-image" @click="save('png')">
            <i class="ph-duotone ph-image text-[18px]" aria-hidden="true"></i>
            {{ t('self_service.receipt_save_image') }}
          </BaseButton>
          <BaseButton variant="secondary" size="lg" :loading="saving === 'pdf'" :disabled="saving !== ''" data-testid="save-pdf" @click="save('pdf')">
            <i class="ph-duotone ph-file-pdf text-[18px]" aria-hidden="true"></i>
            {{ t('self_service.receipt_save_pdf') }}
          </BaseButton>
        </div>
        <p v-if="saveError" class="text-center text-[12.5px] font-semibold text-danger-text" role="alert">{{ t('self_service.receipt_save_failed') }}</p>
        <a v-if="orderAgainPath" :href="orderAgainPath" class="text-center text-[13px] font-bold text-brand-active underline">{{ t('self_service.receipt_back') }}</a>
      </template>
    </main>
  </div>
</template>
