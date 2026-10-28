<script setup>
import { computed, getCurrentInstance, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import { getCatalog, downloadCatalogExcel } from '../../api/catalog';
import { useToastStore } from '../../stores/toast';

/**
 * 049 — dialog "Katalog" di layar Produk: memuat katalog (varian aktif per penjual) dengan filter layar
 * Produk SAAT dialog dibuka, menampilkan ruang lingkupnya, lalu mengunduh PDF. Filter dibaca sekali saat
 * dibuka (bukan terus-menerus) supaya yang diunduh persis sama dengan yang diringkas di dialog.
 *
 * Pembuat PDF (jspdf + html2canvas) di-import dinamis saat tombol ditekan, jadi tidak membebani muatan
 * awal aplikasi. Penutupan dialog menghentikan pembuatan di antara halaman.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  filters: { type: Object, default: () => ({}) }, // { search, artist_id[], category_id[] } dari layar Produk
  scopeLabels: { type: Array, default: () => [] }, // nama penjual/kategori/kata kunci yang aktif, hanya untuk ditampilkan
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const toast = useToastStore();
const appContext = getCurrentInstance()?.appContext;

const catalog = ref(null);
const loading = ref(false);
const error = ref(null);
const scope = ref({}); // salinan filter saat dibuka
const scopeNames = ref([]); // salinan label saat dibuka (ikut disalin supaya ringkasan tidak berubah di tengah jalan)
const generating = ref(false);
const downloadingExcel = ref(false);
const progress = ref({ current: 0, total: 0 });
let abortController = null;

const isEmpty = computed(() => !!catalog.value && catalog.value.totals.variants === 0);
const hasFilters = computed(() => Object.values(scope.value).some((v) => (Array.isArray(v) ? v.length : v)));
const canDownload = computed(() => !!catalog.value && !isEmpty.value && !loading.value && !generating.value && !downloadingExcel.value);

async function load() {
  loading.value = true;
  error.value = null;
  catalog.value = null;
  try {
    catalog.value = await getCatalog(scope.value);
  } catch (err) {
    error.value = err;
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.open,
  (open) => {
    if (!open) {
      abortController?.abort();
      return;
    }
    scope.value = { ...props.filters };
    scopeNames.value = [...props.scopeLabels];
    load();
  },
  { immediate: true },
);

async function downloadPdf() {
  if (!canDownload.value) return;
  generating.value = true;
  progress.value = { current: 0, total: 0 };
  abortController = new AbortController();
  try {
    const { generateCatalogPdf } = await import('../../utils/catalogPdf');
    await generateCatalogPdf(catalog.value, {
      appContext,
      signal: abortController.signal,
      onProgress: (current, total) => { progress.value = { current, total }; },
    });
  } catch (err) {
    if (err?.name !== 'AbortError') toast.error(t('catalog.pdf_failed'));
  } finally {
    generating.value = false;
    abortController = null;
  }
}

async function downloadExcel() {
  if (!canDownload.value) return;
  downloadingExcel.value = true;
  try {
    await downloadCatalogExcel(scope.value);
  } catch {
    toast.error(t('catalog.excel_failed'));
  } finally {
    downloadingExcel.value = false;
  }
}

function close() {
  abortController?.abort();
  emit('close');
}
</script>

<template>
  <BaseModal :open="open" :title="t('catalog.title')" max-width-class="max-w-[520px]" @close="close">
    <div class="flex flex-col gap-4 px-6 py-5">
      <p class="text-[13px] text-muted-4">{{ t('catalog.subtitle') }}</p>

      <div v-if="loading" class="rounded-lg border border-line-2 bg-surface-subtle px-4 py-6 text-center text-[13px] text-muted-4" role="status">…</div>

      <div v-else-if="error" role="alert" class="flex flex-col items-center gap-3 rounded-lg border border-dashed border-danger-border px-4 py-7 text-center">
        <span class="text-[13px] font-semibold text-danger-text">{{ error.message || t('catalog.load_failed') }}</span>
        <BaseButton variant="secondary" size="sm" @click="load">{{ t('common.retry') }}</BaseButton>
      </div>

      <div v-else-if="isEmpty" class="rounded-lg border border-dashed border-line-2 px-4 py-7 text-center text-[13px] text-muted-4" data-testid="catalog-empty">
        {{ t('catalog.nothing') }}
      </div>

      <div v-else-if="catalog" class="flex flex-col gap-1 rounded-lg border border-mint-border bg-mint-50 px-4 py-3.5" data-testid="catalog-summary">
        <span class="text-[15px] font-bold text-brand-active">{{ t('catalog.summary', { sellers: catalog.totals.sellers, variants: catalog.totals.variants }) }}</span>
        <span class="text-[12.5px] text-muted-4">{{ hasFilters ? t('catalog.scope_filtered') : t('catalog.scope_all') }}</span>
        <span v-if="hasFilters && scopeNames.length" class="text-[12.5px] font-semibold text-ink" data-testid="catalog-scope-names">{{ scopeNames.join(' · ') }}</span>
      </div>

      <p v-if="generating" class="text-[13px] font-semibold text-brand-active" role="status" data-testid="catalog-progress">
        {{ t('catalog.generating', { current: progress.current, total: progress.total }) }}
      </p>
    </div>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2.5">
        <BaseButton variant="secondary" @click="close">{{ t('common.close') }}</BaseButton>
        <BaseButton variant="secondary" :disabled="!canDownload" :loading="downloadingExcel" @click="downloadExcel">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          {{ t('catalog.download_excel') }}
        </BaseButton>
        <BaseButton :disabled="!canDownload" :loading="generating" @click="downloadPdf">
          <i class="ph-duotone ph-file-pdf text-[16px]" aria-hidden="true"></i>
          {{ t('catalog.download_pdf') }}
        </BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
