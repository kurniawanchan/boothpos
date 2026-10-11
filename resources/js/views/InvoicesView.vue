<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listInvoices, getInvoiceSummary, exportInvoices, getInvoiceImportTemplate, importInvoices } from '../api/invoices';
import { listCompanies } from '../api/companies';
import { formatIDR, formatCount } from '../utils/formatters';
import { formatDate } from '../utils/date';
import { useQueryState } from '../composables/useQueryState';
import { useToastStore } from '../stores/toast';
import { useAuthStore } from '../stores/auth';
import PageHeader from '../components/page/PageHeader.vue';
import KpiStrip from '../components/page/KpiStrip.vue';
import TabBar from '../components/page/TabBar.vue';
import FilterBar from '../components/page/FilterBar.vue';
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

// Filter status kini berupa tab (semantik sama: parameter `status` ke API, kosong = semua) dan
// dicerminkan ke address bar (?status=paid). Nilai tak dikenal di URL jatuh ke "semua".
const STATUS_TABS = ['all', 'unpaid', 'paid', 'cancelled'];
const query = useQueryState({ status: 'all' });
const activeStatus = computed(() => (STATUS_TABS.includes(query.state.status) ? query.state.status : 'all'));
const statusParam = (tab) => (tab === 'all' ? undefined : tab);

const { items, meta, loading, error, load, setPage, setFilter, params } = usePaginatedList(
  listInvoices,
  activeStatus.value === 'all' ? {} : { status: activeStatus.value }
);
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

// `null` = belum/tidak berhasil dimuat. Angka yang tidak tersedia tampil "—", bukan 0: nol palsu pada
// tagihan lebih menyesatkan daripada angka yang hilang.
const summary = ref(null);

async function loadSummary() {
  try {
    summary.value = await getInvoiceSummary();
  } catch {
    // Statistik gagal dimuat tidak boleh menghalangi tabel tetap tampil.
    summary.value = null;
  }
}

// Empat angka ringkasan, semuanya dari GET /invoices/summary yang sudah ada (tanpa filter, sama dengan
// jumlah seluruh daftar). "Dibatalkan" diturunkan: seluruh invoice dikurangi yang belum dibayar dan lunas —
// status invoice hanya unpaid/paid/cancelled, dan overall_count menghitung ketiganya.
const cancelledCount = computed(() => {
  const s = summary.value;
  return s ? Math.max(0, s.overall_count - s.unpaid.count - s.paid.count) : null;
});

const kpiItems = computed(() => {
  const s = summary.value;
  return [
    { key: 'overall', label: t('invoices.stat_overall'), value: s ? formatCount(s.overall_count) : null, unavailable: !s, tone: 'neutral' },
    {
      key: 'unpaid',
      label: t('invoices.stat_unpaid'),
      value: s ? formatIDR(s.unpaid.total) : null,
      note: s ? t('invoices.stat_count', { count: s.unpaid.count }) : '',
      unavailable: !s,
      tone: 'warning',
    },
    {
      key: 'paid',
      label: t('invoices.stat_paid'),
      value: s ? formatIDR(s.paid.total) : null,
      note: s ? t('invoices.stat_count', { count: s.paid.count }) : '',
      unavailable: !s,
      tone: 'success',
    },
    {
      key: 'cancelled',
      label: t('invoices.status_cancelled'),
      value: s ? formatCount(cancelledCount.value) : null,
      unavailable: !s,
      tone: 'neutral',
    },
  ];
});

// Chip jumlah tab = jumlah SELURUH bagian (bukan hasil filter), dari ringkasan yang sama; tanpa ringkasan
// chip tidak ditampilkan daripada menampilkan angka yang dikarang.
const statusTabs = computed(() => {
  const s = summary.value;
  return [
    { key: 'all', label: t('invoices.filter_status_all'), count: s ? s.overall_count : undefined },
    { key: 'unpaid', label: t('invoices.status_unpaid'), count: s ? s.unpaid.count : undefined, tone: 'warning' },
    { key: 'paid', label: t('invoices.status_paid'), count: s ? s.paid.count : undefined },
    { key: 'cancelled', label: t('invoices.status_cancelled'), count: s ? cancelledCount.value : undefined },
  ];
});

async function refreshAll() {
  await Promise.all([load(), loadSummary()]);
}

onMounted(async () => {
  await Promise.all([loadCompanies(), refreshAll()]);
});

function selectStatus(tab) {
  query.set({ status: tab });
}

// Satu jalur: klik tab -> URL -> watcher -> daftar (back/forward ikut memuat ulang).
watch(activeStatus, (tab) => setFilter({ status: statusParam(tab) }));

const columns = computed(() => [
  { key: 'invoice_number', label: t('invoices.col_invoice_number') },
  { key: 'company_name', label: t('invoices.company') },
  { key: 'license_name', label: t('invoices.license') },
  { key: 'grand_total', label: t('invoices.grand_total') },
  { key: 'due_date', label: t('invoices.due_date') },
  { key: 'status', label: t('master_data.col_status') },
]);

function statusVariant(status) {
  if (status === 'paid') return 'success';
  if (status === 'cancelled') return 'neutral';
  return 'warning';
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
  <div class="pb-10">
    <PageHeader :title="t('nav.invoices')" :description="t('invoices.page_subtitle')">
      <template #actions>
        <template v-if="isOwnerOrAdmin">
          <BaseButton variant="secondary" :loading="exporting" @click="doExportInvoices">
            <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
            {{ t('invoices.export_action') }}
          </BaseButton>
          <BaseButton variant="secondary" :loading="importing" @click="triggerImportFile">
            <i class="ph-duotone ph-upload-simple text-[16px]" aria-hidden="true"></i>
            {{ t('invoices.import_action') }}
          </BaseButton>
          <BaseButton variant="secondary" @click="doDownloadImportTemplate">
            <i class="ph-duotone ph-download-simple text-[16px]" aria-hidden="true"></i>
            {{ t('invoices.download_template_action') }}
          </BaseButton>
          <input ref="importFileInput" type="file" accept=".xlsx" class="hidden" @change="onImportFileSelected" />
        </template>
        <BaseButton @click="openCreate">
          <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
          {{ t('invoices.new_invoice_btn') }}
        </BaseButton>
      </template>
    </PageHeader>

    <KpiStrip :items="kpiItems" />

    <div class="mx-[26px] border-2 border-line bg-white">
      <TabBar :tabs="statusTabs" :model-value="activeStatus" :label="t('invoices.filter_status')" @update:model-value="selectStatus" />
      <!-- API invoice tidak punya parameter pencarian: FilterBar tanpa kolom cari, penghitung = total server tab aktif. -->
      <FilterBar :searchable="false" :count="loading || error ? null : meta.total" />
      <DataTable
        :columns="columns"
        :rows="rowsWithCompany"
        :loading="loading"
        :error="error ? t('page.load_failed') : ''"
        :empty-message="t('invoices.no_invoices')"
        @retry="load"
      >
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
