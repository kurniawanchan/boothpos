<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { usePaginatedList } from '../composables/usePaginatedList';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import { listPreorders, getPreorder, deletePreorder, getPreorderSummary, duplicatePreorders } from '../api/preorders';
import { listOutlets } from '../api/outlets';
import { useToastStore } from '../stores/toast';
import { useAuthStore } from '../stores/auth';
import PageHeader from '../components/page/PageHeader.vue';
import FilterBar from '../components/page/FilterBar.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseMultiSelect from '../components/ui/BaseMultiSelect.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import PreorderInvoiceModal from '../components/preorder/PreorderInvoiceModal.vue';
import PreorderPaymentReceiptModal from '../components/preorder/PreorderPaymentReceiptModal.vue';
import PreorderDuplicateResultModal from '../components/preorder/PreorderDuplicateResultModal.vue';
import PreorderSplitModal from '../components/preorder/PreorderSplitModal.vue';
import PreorderSummaryStrip from './preorders/PreorderSummaryStrip.vue';
import PreorderListTable from './preorders/PreorderListTable.vue';
import PreorderBulkBar from './preorders/PreorderBulkBar.vue';
import PreorderFormModal from './preorders/PreorderFormModal.vue';
import PreorderDetailDrawer from './preorders/PreorderDetailDrawer.vue';
import PreorderCustomerInfoModal from './preorders/PreorderCustomerInfoModal.vue';
import PreorderShippingFlagModal from './preorders/PreorderShippingFlagModal.vue';
import { usePreorderLabels } from './preorders/preorderMeta';
import { usePreorderBulk } from './preorders/usePreorderBulk';
import { usePreorderTransfer } from './preorders/usePreorderTransfer';

/**
 * Daftar pre-order. 041-back-office-ui-redesign: PageHeader (judul + ekspor/impor/baru), strip ringkasan,
 * FilterBar dan tabel; file ini dulu 1.983 baris dan kini hanya merangkai subkomponen di views/preorders/
 * (form, panel detail, tabel, bilah massal, dialog kecil) serta dua composable (aksi massal, ekspor/impor).
 * Perilaku, endpoint, dan izin tidak berubah.
 */
const toast = useToastStore();
const auth = useAuthStore();
const { t } = useI18n();
// 007-preorder-import-export-notify (FR-015) — export/import/resend
// dibatasi owner/admin saja, meski menu_key 'preorders' sendiri masih
// dipakai bersama kasir/inventory untuk CRUD dasar (server menegakkan
// isOwnerOrAdmin() secara terpisah; ini hanya cermin kosmetik).
const isOwnerOrAdmin = computed(() => ['owner', 'admin'].includes((auth.role || '').toLowerCase()));
const { STATUS_LABEL, DISPATCH_LABEL, FULFILLMENT_LABEL } = usePreorderLabels();

const { items, meta, loading, error, load, setPage, setFilter, params } = usePaginatedList(listPreorders);
const route = useRoute();
const router = useRouter();

const drawer = ref(null);
const form = ref(null);
const customerInfo = ref(null);

// 013-preorder-list-filters-receipt (US5, T026) — summary aggregate always
// refetched alongside the list, using the exact same filter params
// (`params` from usePaginatedList is the single source of truth for
// "what's currently filtered", see api-deltas.md), so the two never
// disagree.
const summary = ref(null);

async function loadSummary() {
  summary.value = await getPreorderSummary({ ...params });
}

function applyFilter(patch) {
  setFilter(patch);
  loadSummary();
}

// 013-preorder-list-filters-receipt (US1, T008) — filter outlet, pola
// sama seperti filter outlet di ReportsView.vue.
const outlets = ref([]);

onMounted(load);
onMounted(loadSummary);
onMounted(async () => {
  outlets.value = (await listOutlets({ per_page: 100, is_active: true })).data;
});

// 009-ui-ux-refinements US5 (T043) — CustomerTransactionsModal.vue
// navigates here with ?preorder_id=<id> to open this existing detail
// drawer rather than duplicating it in the Customers screen. The query
// param is stripped once consumed so a refresh doesn't reopen it.
onMounted(async () => {
  const id = route.query.preorder_id;
  if (!id) return;
  await drawer.value.openById(id);
  router.replace({ query: { ...route.query, preorder_id: undefined } });
});

// 007-preorder-import-export-notify (US1) — pencarian nama pelanggan,
// debounced sama seperti pola pencarian ProductsView.vue.
const customerSearch = ref('');
const debouncedCustomerSearch = useDebouncedFn(() => applyFilter({ search: customerSearch.value || undefined }), 300);
function onSearch(value) {
  customerSearch.value = value;
  debouncedCustomerSearch();
}

// BUG YANG DITEMUKAN & DIPERBAIKI — ketiga filter status/fulfillment/
// outlet sebelumnya memakai BaseSelect TANPA `v-model`/`:model-value`
// (hanya mendengarkan @update:model-value), jadi label yang tertampil di
// tombolnya SELALU menampilkan placeholder ("All statuses"/dst.) meski
// filter sudah benar-benar aktif di baliknya — ditemukan lewat verifikasi
// browser sungguhan. Diganti ke BaseMultiSelect (pola yang sama seperti
// home_outlet_id[]/category_id[] di ProductsView, 005-ux-enhancements-dashboard):
// array kosong berarti "Semua", dan ketiga filter ini + pencarian tetap
// DI-AND-kan satu sama lain di backend (PreorderController::applyFilters()),
// sementara beberapa nilai dalam satu filter yang sama di-OR-kan (whereIn).
const statusFilter = ref([]);
const fulfillmentFilter = ref([]);
const outletFilter = ref([]);
const dispatchFilter = ref([]);
const statusOptions = computed(() => Object.entries(STATUS_LABEL.value).map(([value, label]) => ({ value, label })));
const dispatchOptions = computed(() => Object.entries(DISPATCH_LABEL.value).map(([value, label]) => ({ value, label })));
const fulfillmentOptions = computed(() => Object.entries(FULFILLMENT_LABEL.value).map(([value, label]) => ({ value, label })));
const outletOptions = computed(() => outlets.value.map((a) => ({ value: a.id, label: a.name })));

function applyPreorderFilters() {
  applyFilter({
    status: statusFilter.value.length ? statusFilter.value : undefined,
    fulfillment: fulfillmentFilter.value.length ? fulfillmentFilter.value : undefined,
    home_outlet_id: outletFilter.value.length ? outletFilter.value : undefined,
    dispatch_status: dispatchFilter.value.length ? dispatchFilter.value : undefined,
  });
}

const filtersActive = computed(() => !!customerSearch.value
  || statusFilter.value.length > 0 || fulfillmentFilter.value.length > 0
  || outletFilter.value.length > 0 || dispatchFilter.value.length > 0);

// 041 — "Atur ulang filter" mengosongkan pencarian dan keempat filter sekaligus dalam SATU pemuatan
// (urutan kolom tidak termasuk filter, jadi tidak disentuh).
function resetFilters() {
  customerSearch.value = '';
  statusFilter.value = [];
  fulfillmentFilter.value = [];
  outletFilter.value = [];
  dispatchFilter.value = [];
  applyFilter({ search: undefined, status: undefined, fulfillment: undefined, home_outlet_id: undefined, dispatch_status: undefined });
}

// Column sort state — mirrored into the same `sort_by`/`sort_dir` query
// params the backend's applySort() whitelist reads (PreorderController).
const sortBy = ref(null);
const sortDir = ref('asc');
function handleSort({ key, dir }) {
  sortBy.value = key;
  sortDir.value = dir;
  applyFilter({ sort_by: key, sort_dir: dir });
}

// --- Dialog kecil -------------------------------------------------------------
// Requested: clicking the flag shows the shipping cost + notes right
// there, instead of forcing a trip into the full detail drawer.
const showShippingFlagInfo = ref(false);
const shippingFlagInfoRow = ref(null);
function openShippingFlagInfo(row) {
  shippingFlagInfoRow.value = row;
  showShippingFlagInfo.value = true;
}

// 022-preorder-invoice-crud-overhaul (US1, FR-003/FR-004) — hanya
// dipanggil untuk baris berstatus "ordered"/"cancelled" (tombol Hapus sendiri sudah
// disembunyikan untuk status lain), tapi 409 dari server tetap ditangani
// (mis. race condition status berubah di tab lain).
const showDeleteConfirm = ref(false);
const deleteTarget = ref(null);
const deletingPreorder = ref(false);

function confirmDeletePreorder(row) {
  deleteTarget.value = row;
  showDeleteConfirm.value = true;
}

async function performDeletePreorder() {
  deletingPreorder.value = true;
  try {
    await deletePreorder(deleteTarget.value.id);
    toast.success(t('preorders.preorder_deleted'));
    showDeleteConfirm.value = false;
    await load();
  } catch {
    // 409 (status bukan "ordered" / sudah ada pembayaran) sudah ditoast
    // oleh interceptor global — dialog tetap terbuka supaya pesan itu
    // terlihat di belakangnya.
  } finally {
    deletingPreorder.value = false;
  }
}

// --- Form baru / ubah ---------------------------------------------------------
function openCreate() {
  form.value.openCreate();
}

async function onFormSaved(edited) {
  // Bila pre-order yang diubah sedang dipegang panel detail, segarkan panelnya.
  if (edited && drawer.value.hasDetailFor(edited.id)) await drawer.value.open(edited);
  await load();
}

// --- Duplikat (027-preorder-duplicate-split) -------------------------------
// Satu endpoint untuk satu maupun banyak pre-order; server SELALU membalas 200
// dengan laporan per-pre-order, jadi kegagalan satu baris dibaca dari `status`,
// bukan dari kode HTTP. Harga salinan diambil dari harga varian SAAT INI.
const duplicating = ref(false);
const duplicateResults = ref([]);
const showDuplicateResults = ref(false);

async function doDuplicate(ids) {
  if (!ids.length || duplicating.value) return;
  duplicating.value = true;
  try {
    const { data: results } = await duplicatePreorders(ids);

    if (results.length === 1) {
      const [only] = results;
      if (only.status === 'created') {
        toast.success(t('preorders.duplicate_success', { number: only.preorder.preorder_number }));
        // Langsung buka salinannya supaya bisa ditinjau/diedit (spec US1 #3).
        await drawer.value.openById(only.preorder.id);
      } else {
        toast.error(only.error || t('preorders.duplicate_failed'));
      }
    } else {
      // Banyak sekaligus: ringkasan per-pre-order (yang gagal tetap terlihat).
      duplicateResults.value = results;
      showDuplicateResults.value = true;
    }

    await load();
    loadSummary(); // kartu ringkasan harus ikut menghitung salinan baru (FR-024)
    return results;
  } catch (err) {
    toast.error(err.message || t('preorders.duplicate_failed'));
  } finally {
    duplicating.value = false;
  }
}

async function openDuplicateResult(id) {
  showDuplicateResults.value = false;
  await drawer.value.openById(id);
}

// --- Pisah (027-preorder-duplicate-split US3) ------------------------------
// Baris list tidak membawa item, jadi dialog selalu dibuka dari payload detail.
const showSplit = ref(false);
const splitTarget = ref(null);

async function openSplit(id) {
  try {
    splitTarget.value = await getPreorder(id);
    showSplit.value = true;
  } catch (err) {
    toast.error(err.message || t('preorders.load_failed'));
  }
}

async function onSplitDone(result) {
  showSplit.value = false;
  const numbers = result.created.map((c) => c.preorder_number).join(', ');
  toast.success(t('preorders.split_success', { number: numbers }));
  // Bila panel detail sedang menampilkan pesanan ini, segarkan supaya daftar
  // "Dipisah menjadi" muncul.
  if (drawer.value.hasDetailFor(result.original.id)) await drawer.value.refresh();
  await load();
  loadSummary();
}

// --- Invoice / struk pembayaran (US2) -----------------------------------------
const showInvoiceModal = ref(false);
const invoicePreorderId = ref(null);

// 010-split-payment-preorder-reports (US4, T020) — struk pembayaran per
// baris riwayat, terpisah dari invoice pesanan di atas (research.md R5).
const showPaymentReceiptModal = ref(false);
const receiptPreorderId = ref(null);
const receiptPaymentId = ref(null);

function openInvoice(row) {
  invoicePreorderId.value = row.id;
  showInvoiceModal.value = true;
}

// `preorderId` wajib diberikan pemanggil (baris list memberi id-nya sendiri dengan paymentId = null →
// modal memakai pembayaran terakhir; panel detail memberi id pre-order yang sedang terbuka).
function openPaymentReceipt(paymentId, preorderId) {
  if (!preorderId) return;
  receiptPreorderId.value = preorderId;
  receiptPaymentId.value = paymentId;
  showPaymentReceiptModal.value = true;
}

function onRowAction(row, key) {
  if (key === 'invoice') openInvoice(row);
  else if (key === 'payment_invoice') openPaymentReceipt(null, row.id);
  else if (key === 'edit') form.value.openEdit(row);
  else if (key === 'delete') confirmDeletePreorder(row);
  else if (key === 'duplicate') doDuplicate([row.id]);
  else if (key === 'split') openSplit(row.id);
  else if (key === 'detail') drawer.value.open(row);
}

// Panel detail meminta daftar (dan kartu ringkasan, bila angkanya ikut berubah) dimuat ulang.
function onDrawerReload({ summary: withSummary } = {}) {
  load();
  if (withSummary) loadSummary();
}

// --- Aksi massal (022-preorder-invoice-crud-overhaul US5) ---------------------
const selectedIds = ref([]);
const { bulkDocumentType, bulkDownloading, bulkEmailing, doBulkDownload, doBulkEmail, doBulkShippingSlips } = usePreorderBulk(selectedIds);

function toggleSelected(id) {
  selectedIds.value = selectedIds.value.includes(id)
    ? selectedIds.value.filter((x) => x !== id)
    : [...selectedIds.value, id];
}

async function doDuplicateSelected() {
  const results = await doDuplicate([...selectedIds.value]);
  if (results) selectedIds.value = [];
}

// --- Ekspor/impor (US3) -----------------------------------------------------
const { exporting, importFileInput, doExportPreorders, doDownloadImportTemplate, triggerImportFile, onImportFileSelected } = usePreorderTransfer({ params, reload: load });
</script>

<template>
  <div class="pb-10">
    <PageHeader :title="t('nav.preorders')" :description="t('nav.preorders_subtitle')">
      <template #actions>
        <template v-if="isOwnerOrAdmin">
          <button type="button" class="text-[12px] font-semibold text-muted-4 underline hover:text-brand-active" @click="doDownloadImportTemplate">
            {{ t('preorders.download_template_action') }}
          </button>
          <BaseButton variant="secondary" :loading="exporting" @click="doExportPreorders">
            <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
            {{ t('preorders.export_action') }}
          </BaseButton>
          <BaseButton variant="secondary" @click="triggerImportFile">
            <i class="ph-duotone ph-upload-simple text-[16px]" aria-hidden="true"></i>
            {{ t('preorders.import_action') }}
          </BaseButton>
          <input ref="importFileInput" type="file" accept=".xlsx" class="hidden" @change="onImportFileSelected" />
        </template>
        <BaseButton @click="openCreate">
          <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
          {{ t('preorders.new_preorder') }}
        </BaseButton>
      </template>
    </PageHeader>

    <!-- 013-preorder-list-filters-receipt (US5, T026) — ringkasan dimuat ulang lewat applyFilter()/onMounted
         bersama daftar, memakai params yang sama, jadi tak pernah berbeda dari yang tampil di layar. -->
    <PreorderSummaryStrip :summary="summary" />

    <section class="mx-[26px] border-2 border-line bg-white">
      <FilterBar
        :search="customerSearch"
        :placeholder="t('preorders.search_customer_name_placeholder')"
        :search-label="t('preorders.search_customer_name')"
        :count="meta.total"
        :active="filtersActive"
        @update:search="onSearch"
        @reset="resetFilters"
      >
        <BaseMultiSelect
          v-model="statusFilter"
          class="w-48"
          :options="statusOptions"
          :all-label="t('preorders.all_status')"
          @update:model-value="applyPreorderFilters"
        />
        <BaseMultiSelect
          v-model="dispatchFilter"
          class="w-52"
          :options="dispatchOptions"
          :all-label="t('preorders.all_dispatch_status')"
          @update:model-value="applyPreorderFilters"
        />
        <BaseMultiSelect
          v-model="fulfillmentFilter"
          class="w-48"
          :options="fulfillmentOptions"
          :all-label="t('preorders.all_fulfillment')"
          @update:model-value="applyPreorderFilters"
        />
        <BaseMultiSelect
          v-model="outletFilter"
          class="w-48"
          :options="outletOptions"
          :all-label="t('preorders.all_sellers')"
          @update:model-value="applyPreorderFilters"
        />
      </FilterBar>

      <PreorderBulkBar
        v-model:document-type="bulkDocumentType"
        :count="selectedIds.length"
        :downloading="bulkDownloading"
        :emailing="bulkEmailing"
        :duplicating="duplicating"
        @download="doBulkDownload"
        @download-slips="doBulkShippingSlips"
        @email="doBulkEmail"
        @duplicate="doDuplicateSelected"
        @clear="selectedIds = []"
      />

      <PreorderListTable
        :rows="items"
        :loading="loading"
        :error="error ? (error.message || t('page.load_failed')) : ''"
        :sort-key="sortBy"
        :sort-dir="sortDir"
        :selected-ids="selectedIds"
        @sort="handleSort"
        @retry="load"
        @toggle-select="toggleSelected"
        @open-detail="(row) => drawer.open(row)"
        @open-customer="(id) => customerInfo.open(id)"
        @open-flag="openShippingFlagInfo"
        @row-action="onRowAction"
      />
      <TablePagination :meta="meta" @change="setPage" />
    </section>

    <PreorderFormModal ref="form" @saved="onFormSaved" />
    <PreorderSplitModal :open="showSplit" :preorder="splitTarget" @close="showSplit = false" @done="onSplitDone" />
    <PreorderDuplicateResultModal
      :open="showDuplicateResults"
      :results="duplicateResults"
      @close="showDuplicateResults = false"
      @open="openDuplicateResult"
    />
    <PreorderDetailDrawer
      ref="drawer"
      :duplicating="duplicating"
      @reload="onDrawerReload"
      @duplicate="(id) => doDuplicate([id])"
      @split="openSplit"
      @print-invoice="openInvoice"
      @print-payment="openPaymentReceipt"
    />

    <PreorderInvoiceModal
      :open="showInvoiceModal"
      :preorder-id="invoicePreorderId"
      @close="showInvoiceModal = false"
    />

    <PreorderPaymentReceiptModal
      :open="showPaymentReceiptModal"
      :preorder-id="receiptPreorderId"
      :payment-id="receiptPaymentId"
      @close="showPaymentReceiptModal = false"
    />

    <ConfirmDialog
      :open="showDeleteConfirm"
      :title="t('preorders.delete_preorder')"
      :message="t('preorders.delete_preorder_confirm', { number: deleteTarget?.preorder_number })"
      :confirm-label="t('common.delete')"
      :loading="deletingPreorder"
      @close="showDeleteConfirm = false"
      @confirm="performDeletePreorder"
    />

    <PreorderCustomerInfoModal ref="customerInfo" />
    <PreorderShippingFlagModal :open="showShippingFlagInfo" :row="shippingFlagInfoRow" @close="showShippingFlagInfo = false" />
  </div>
</template>
