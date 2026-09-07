<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listInvoices, getInvoiceSummary, exportInvoices, getInvoiceImportTemplate, importInvoices } from '../api/invoices';
import { listCompanies } from '../api/companies';
import { formatIDR } from '../utils/money';
import { formatDate } from '../utils/date';
import { useToastStore } from '../stores/toast';
import { useAuthStore } from '../stores/auth';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import InvoiceFormModal from '../components/invoices/InvoiceFormModal.vue';
import InvoiceDetailModal from '../components/invoices/InvoiceDetailModal.vue';
import BaseModal from '../components/ui/BaseModal.vue';

/**
 * 019-billing-system (T055/T062) — layar top-level baru untuk `Invoice`
 * mandiri, menggantikan `CompanyInvoicesModal.vue` yang dulu menumpang di
 * bawah Company (dihapus di komit yang sama, T058).
 *
 * `InvoiceResource` belum menyertakan nama company (hanya `company_id` —
 * lihat app/Http/Resources/InvoiceResource.php), jadi nama company di sini
 * dicari dari daftar company yang sudah dimuat terpisah (companiesById),
 * bukan dari field yang sebenarnya tidak dikembalikan API.
 */
const { t } = useI18n();
const toast = useToastStore();
const auth = useAuthStore();
// 019-billing-system (T076, Phase 6) — export/import digerbang inline
// isOwnerOrAdmin(), tier gerbang yang sama seperti /preorders/export
// (research.md R6'); menu key 'invoices' sendiri tetap dipakai bersama
// untuk CRUD dasar.
const isOwnerOrAdmin = computed(() => ['owner', 'admin'].includes((auth.role || '').toLowerCase()));

const { items, meta, loading, load, setPage, setFilter, params } = usePaginatedList(listInvoices);
const companiesById = ref({});

async function loadCompanies() {
  try {
    const res = await listCompanies({ per_page: 100 });
    companiesById.value = Object.fromEntries((res.data ?? []).map((c) => [c.id, c.name]));
  } catch {
    // Nama company sekadar dekorasi tabel — kegagalan di sini tidak boleh
    // menghalangi daftar invoice tetap tampil.
  }
}

const rowsWithCompany = computed(() =>
  items.value.map((inv) => ({ ...inv, company_name: companiesById.value[inv.company_id] ?? '—' }))
);

const summary = reactive({
  unpaid: { count: 0, total: '0.00' },
  paid: { count: 0, total: '0.00' },
  overall_count: 0,
});

async function loadSummary() {
  try {
    const res = await getInvoiceSummary();
    Object.assign(summary, res);
  } catch {
    // Statistik gagal dimuat tidak boleh menghalangi tabel tetap tampil.
  }
}

async function refreshAll() {
  await Promise.all([load(), loadSummary()]);
}

onMounted(async () => {
  await Promise.all([loadCompanies(), refreshAll()]);
});

const statusFilter = ref('');
function onStatusFilterChange(e) {
  setFilter({ status: e.target.value || undefined });
}

const columns = computed(() => [
  { key: 'invoice_number', label: t('invoices.col_invoice_number') },
  { key: 'company_name', label: t('invoices.company') },
  { key: 'license_name', label: t('invoices.license') },
  { key: 'grand_total', label: t('invoices.grand_total') },
  { key: 'due_date', label: t('invoices.due_date') },
  { key: 'status', label: t('master_data.col_status') },
]);

function statusVariant(status) {
  if (status === 'paid') return 'mint';
  if (status === 'cancelled') return 'neutral';
  return 'warn';
}

const showForm = ref(false);
const editingInvoice = ref(null);
const showDetail = ref(false);
const detailTarget = ref(null);

function openCreate() {
  editingInvoice.value = null;
  showForm.value = true;
}

function openDetail(invoice) {
  detailTarget.value = invoice;
  showDetail.value = true;
}

// 019-billing-system (T098) — Edit dari detail modal menutup detail lalu
// membuka InvoiceFormModal.vue dalam mode edit (state showForm/editingInvoice
// yang sama dipakai alur create, research.md R13).
function openEditFromDetail(invoice) {
  showDetail.value = false;
  editingInvoice.value = invoice;
  showForm.value = true;
}

async function afterSaved() {
  await refreshAll();
}

async function afterChanged() {
  await refreshAll();
}

// --- Export/import (T076) --------------------------------------------------
// Mirrors PreordersView.vue's doExportPreorders/doDownloadImportTemplate/
// onImportFileSelected exactly (research.md R6').
const exporting = ref(false);
const importFileInput = ref(null);
const importing = ref(false);
const showImportPreview = ref(false);
const importPreview = ref(null);
const pendingImportFile = ref(null);
const applyingImport = ref(false);

async function doExportInvoices() {
  exporting.value = true;
  try {
    const blob = await exportInvoices({ ...params });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'invoices.xlsx';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (err) {
    toast.error(err.message || t('invoices.export_failed'));
  } finally {
    exporting.value = false;
  }
}

async function doDownloadImportTemplate() {
  const blob = await getInvoiceImportTemplate();
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'template-invoices.xlsx';
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function triggerImportFile() {
  importFileInput.value?.click();
}

async function onImportFileSelected(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  pendingImportFile.value = file;
  importing.value = true;
  try {
    // dry_run=1 preview pass (all-or-nothing validation, research.md R6') —
    // sebelum benar-benar menulis apa pun.
    importPreview.value = await importInvoices(file, true);
    showImportPreview.value = true;
  } catch (err) {
    if (err.status === 409 && err.data?.row_errors) {
      const rowErrors = err.data.row_errors;
      const first = rowErrors[0];
      const suffix = rowErrors.length > 1 ? t('invoices.import_more_rows_failed', { count: rowErrors.length - 1 }) : '';
      toast.error(t('invoices.import_row_error', { row: first.row, error: first.errors[0] }) + suffix, { timeout: 12000 });
    } else {
      toast.error(err.message || t('invoices.import_failed'));
    }
    pendingImportFile.value = null;
  } finally {
    importing.value = false;
  }
}

async function confirmApplyImport() {
  if (!pendingImportFile.value) return;
  applyingImport.value = true;
  try {
    const result = await importInvoices(pendingImportFile.value, false);
    toast.success(t('invoices.import_success', { created: result.created_count ?? 0, updated: result.updated_count ?? 0 }));
    showImportPreview.value = false;
    pendingImportFile.value = null;
    importPreview.value = null;
    await refreshAll();
  } catch (err) {
    toast.error(err.message || t('invoices.import_failed'));
  } finally {
    applyingImport.value = false;
  }
}

function cancelImportPreview() {
  showImportPreview.value = false;
  pendingImportFile.value = null;
  importPreview.value = null;
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
      <div class="flex flex-col gap-1 rounded-card border border-line-2 bg-white px-4 py-3.5">
        <span class="text-[12px] font-semibold text-muted-3">{{ t('invoices.stat_unpaid') }}</span>
        <span class="text-[19px] font-bold tracking-tight">{{ formatIDR(summary.unpaid.total) }}</span>
        <span class="text-[12px] text-muted-3">{{ t('invoices.stat_count', { count: summary.unpaid.count }) }}</span>
      </div>
      <div class="flex flex-col gap-1 rounded-card border border-line-2 bg-white px-4 py-3.5">
        <span class="text-[12px] font-semibold text-muted-3">{{ t('invoices.stat_paid') }}</span>
        <span class="text-[19px] font-bold tracking-tight">{{ formatIDR(summary.paid.total) }}</span>
        <span class="text-[12px] text-muted-3">{{ t('invoices.stat_count', { count: summary.paid.count }) }}</span>
      </div>
      <div class="flex flex-col gap-1 rounded-card border border-line-2 bg-white px-4 py-3.5">
        <span class="text-[12px] font-semibold text-muted-3">{{ t('invoices.stat_overall') }}</span>
        <span class="text-[19px] font-bold tracking-tight">{{ summary.overall_count }}</span>
      </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2.5">
      <label class="flex items-center gap-2 text-[13px] font-semibold text-muted-4">
        {{ t('invoices.filter_status') }}
        <select
          v-model="statusFilter"
          class="h-[38px] rounded-lg border border-line bg-white px-3 text-[13px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @change="onStatusFilterChange"
        >
          <option value="">{{ t('invoices.filter_status_all') }}</option>
          <option value="unpaid">{{ t('invoices.status_unpaid') }}</option>
          <option value="paid">{{ t('invoices.status_paid') }}</option>
          <option value="cancelled">{{ t('invoices.status_cancelled') }}</option>
        </select>
      </label>
      <template v-if="isOwnerOrAdmin">
        <BaseButton variant="secondary" :loading="exporting" @click="doExportInvoices">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          {{ t('invoices.export_action') }}
        </BaseButton>
        <BaseButton variant="secondary" :loading="importing" @click="triggerImportFile">
          <i class="ph-duotone ph-upload-simple text-[16px]" aria-hidden="true"></i>
          {{ t('invoices.import_action') }}
        </BaseButton>
        <button type="button" class="text-[12px] font-semibold text-muted-4 underline hover:text-brand-active" @click="doDownloadImportTemplate">
          {{ t('invoices.download_template_action') }}
        </button>
        <input ref="importFileInput" type="file" accept=".xlsx" class="hidden" @change="onImportFileSelected" />
      </template>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('invoices.new_invoice_btn') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="rowsWithCompany" :loading="loading" :empty-message="t('invoices.no_invoices')">
        <template #cell-invoice_number="{ row }">
          <button type="button" class="font-mono text-[13.5px] font-semibold text-brand-active hover:underline" @click="openDetail(row)">
            {{ row.invoice_number }}
          </button>
        </template>
        <template #cell-license_name="{ row }">{{ row.license?.name ?? '—' }}</template>
        <template #cell-grand_total="{ row }">{{ formatIDR(row.grand_total) }}</template>
        <template #cell-due_date="{ row }">{{ formatDate(row.due_date) }}</template>
        <template #cell-status="{ row }">
          <StatusPill :variant="statusVariant(row.status)">{{ t(`invoices.status_${row.status}`) }}</StatusPill>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <InvoiceFormModal :open="showForm" :invoice="editingInvoice" @close="showForm = false" @saved="afterSaved" />
    <InvoiceDetailModal
      :open="showDetail"
      :invoice="detailTarget"
      @close="showDetail = false"
      @changed="afterChanged"
      @edit="openEditFromDetail"
    />

    <!-- T076 — dry-run preview before applying the import, mirrors
         PreordersView.vue's inline row_errors toast but surfaced as a
         confirm dialog since the backend's dry_run pass already reports
         created/updated counts up front (research.md R6'). -->
    <BaseModal :open="showImportPreview" :title="t('invoices.import_preview_title')" max-width-class="max-w-[480px]" @close="cancelImportPreview">
      <div class="flex flex-col gap-3 px-6 py-5">
        <p class="text-[13px]">
          {{ t('invoices.import_preview_summary', { created: importPreview?.created_count ?? 0, updated: importPreview?.updated_count ?? 0 }) }}
        </p>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="cancelImportPreview">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="applyingImport" @click="confirmApplyImport">{{ t('invoices.import_apply_action') }}</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
