<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import {
  listCustomers,
  createCustomer,
  updateCustomer,
  deleteCustomer,
  exportCustomers,
  downloadCustomerImportTemplate,
  importCustomers,
} from '../api/customers';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import CustomerTransactionsModal from '../components/customer/CustomerTransactionsModal.vue';

const auth = useAuthStore();
const toast = useToastStore();
const { t } = useI18n();

// Customer delete is owner/admin only server-side (CustomerPolicy::delete)
// — mirrors PreordersView's/EventsView's isOwnerOrAdmin computed since
// this view has no canAccessMenu gate today (create/edit are open to all
// roles that can reach the screen).
const isOwnerOrAdmin = computed(() => ['owner', 'admin'].includes((auth.role || '').toLowerCase()));

// 020-customer-data-import-export — cermin kosmetik saja dari gerbang
// server-side sesungguhnya (CustomerController::authorizedForBulkOperation(),
// canAccessAnyMenu(['artists','categories','products','stock','vendors',
// 'materials','roles','users'])). Owner/admin punya SELURUH menu_keys,
// inventory punya persis daftar itu, kasir tidak — jadi tiga peran ini
// (bukan hanya isOwnerOrAdmin di atas) yang dicerminkan di sini, per
// Constitution III (kontrol yang tidak boleh dipakai peran ini disembunyikan
// total, bukan ditampilkan lalu ditolak 403).
const canBulkManageCustomers = computed(() => ['owner', 'admin', 'inventory'].includes((auth.role || '').toLowerCase()));

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listCustomers);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);
onMounted(load);

// No "Transaksi / Pre-order / Total belanja" columns here — verified
// against CustomerResource, which only ever returns identity/contact
// fields (id, name, phone, email, social_handle, notes). The mockup shows
// those aggregate columns but the API never computes them.
const columns = computed(() => [
  { key: 'name', label: t('events_sessions.col_name') },
  { key: 'phone', label: t('events_sessions.col_phone') },
  { key: 'email', label: t('events_sessions.col_email') },
  { key: 'social_handle', label: t('events_sessions.col_social') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editingCustomer = ref(null);
const form = reactive({ name: '', phone: '', email: '', social_handle: '', notes: '', address: '' });
const formErrors = reactive({});
const saving = ref(false);

function openCreate() {
  editingCustomer.value = null;
  Object.assign(form, { name: '', phone: '', email: '', social_handle: '', notes: '', address: '' });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(customer) {
  editingCustomer.value = customer;
  Object.assign(form, {
    name: customer.name,
    phone: customer.phone ?? '',
    email: customer.email ?? '',
    social_handle: customer.social_handle ?? '',
    notes: customer.notes ?? '',
    address: customer.address ?? '',
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveCustomer() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    name: form.name,
    phone: form.phone || null,
    email: form.email || null,
    social_handle: form.social_handle || null,
    notes: form.notes || null,
    address: form.address || null,
  };
  try {
    if (editingCustomer.value) {
      await updateCustomer(editingCustomer.value.id, payload);
      toast.success(t('events_sessions.customer_updated'));
    } else {
      await createCustomer(payload);
      toast.success(t('events_sessions.customer_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function confirmDelete(customer) {
  deleteTarget.value = customer;
  showDelete.value = true;
}

// Read-only, so visible to every role that can reach this screen at all
// (no isOwnerOrAdmin gate) — same visibility as the existing "edit" action.
const showTransactions = ref(false);
const transactionsCustomerId = ref(null);

function openTransactions(customer) {
  transactionsCustomerId.value = customer.id;
  showTransactions.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteCustomer(deleteTarget.value.id);
    toast.success(t('events_sessions.customer_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // 409 (masih ada transaksi/pre-order) sudah ditoast oleh interceptor
    // bersama — pesan servernya sudah cukup jelas.
  } finally {
    deleting.value = false;
  }
}

// --- Export/import (020-customer-data-import-export) ----------------------
const exporting = ref(false);

async function doExportCustomers() {
  exporting.value = true;
  try {
    const blob = await exportCustomers();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'customers.xlsx';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (err) {
    toast.error(err.message || t('events_sessions.export_customers_failed'));
  } finally {
    exporting.value = false;
  }
}

async function doDownloadCustomerImportTemplate() {
  const blob = await downloadCustomerImportTemplate();
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'template-customers.xlsx';
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

const showImport = ref(false);
const importFile = ref(null);
const previewing = ref(false);
const importing = ref(false);
const previewResult = ref(null); // { created_count, updated_count, row_errors }

function openImport() {
  importFile.value = null;
  previewResult.value = null;
  showImport.value = true;
}

function closeImport() {
  showImport.value = false;
  importFile.value = null;
  previewResult.value = null;
}

function onImportFileChosen(e) {
  importFile.value = e.target.files?.[0] || null;
  previewResult.value = null;
}

function importErrorToRowErrors(err) {
  return err.status === 409 && err.data?.row_errors ? err.data.row_errors : null;
}

// 020-customer-data-import-export (US3) — pratinjau lewat dry_run=1,
// jalur validasi yang IDENTIK dengan impor sungguhan (tidak ada logika
// pratinjau terpisah di frontend maupun backend), sebelum tombol
// "Konfirmasi impor" diaktifkan.
async function doPreviewImport() {
  if (!importFile.value) return;
  previewing.value = true;
  previewResult.value = null;
  try {
    const result = await importCustomers(importFile.value, true);
    previewResult.value = { created_count: result.created_count, updated_count: result.updated_count, row_errors: [] };
  } catch (err) {
    const rowErrors = importErrorToRowErrors(err);
    if (rowErrors) {
      previewResult.value = { created_count: 0, updated_count: 0, row_errors: rowErrors };
    } else {
      toast.error(err.message || t('events_sessions.import_customers_failed'));
    }
  } finally {
    previewing.value = false;
  }
}

async function doConfirmImport() {
  if (!importFile.value || !previewResult.value || previewResult.value.row_errors.length > 0) return;
  importing.value = true;
  try {
    const result = await importCustomers(importFile.value, false);
    toast.success(t('events_sessions.import_customers_success', { created: result.created_count, updated: result.updated_count }));
    closeImport();
    await load();
  } catch (err) {
    const rowErrors = importErrorToRowErrors(err);
    if (rowErrors) {
      previewResult.value = { created_count: 0, updated_count: 0, row_errors: rowErrors };
    } else {
      toast.error(err.message || t('events_sessions.import_customers_failed'));
    }
  } finally {
    importing.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="relative flex min-w-[230px] flex-1 items-center">
        <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[16px] text-muted-3" aria-hidden="true"></i>
        <label class="sr-only" for="customer-search">{{ t('events_sessions.search_customer') }}</label>
        <input
          id="customer-search"
          v-model="search"
          :placeholder="t('events_sessions.search_customer_placeholder')"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <template v-if="canBulkManageCustomers">
        <BaseButton variant="secondary" :loading="exporting" @click="doExportCustomers">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          {{ t('events_sessions.export_customers_action') }}
        </BaseButton>
        <BaseButton variant="secondary" @click="openImport">
          <i class="ph-duotone ph-upload-simple text-[16px]" aria-hidden="true"></i>
          {{ t('events_sessions.import_customers_action') }}
        </BaseButton>
      </template>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('events_sessions.new_customer') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('events_sessions.no_customers')">
        <template #cell-phone="{ row }">{{ row.phone || '—' }}</template>
        <template #cell-email="{ row }">{{ row.email || '—' }}</template>
        <template #cell-social_handle="{ row }">{{ row.social_handle || '—' }}</template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openTransactions(row)">{{ t('events_sessions.view_transactions') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button v-if="isOwnerOrAdmin" type="button" class="text-[12.5px] font-semibold text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showForm" :title="editingCustomer ? t('events_sessions.edit_customer') : t('events_sessions.new_customer')" max-width-class="max-w-[460px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveCustomer">
        <BaseInput v-model="form.name" :label="t('events_sessions.name')" required maxlength="100" :error="formErrors.name" />
        <BaseInput v-model="form.phone" :label="t('events_sessions.phone')" :error="formErrors.phone" />
        <BaseTextarea v-model="form.address" :label="t('events_sessions.address')" :rows="3" :error="formErrors.address" />
        <BaseInput v-model="form.email" type="email" :label="t('events_sessions.email')" :error="formErrors.email" />
        <BaseInput v-model="form.social_handle" :label="t('events_sessions.social_handle')" :error="formErrors.social_handle" />
        <BaseTextarea v-model="form.notes" :label="t('events_sessions.notes')" :rows="2" :error="formErrors.notes" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="saveCustomer">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <CustomerTransactionsModal :open="showTransactions" :customer-id="transactionsCustomerId" @close="showTransactions = false" />

    <BaseModal :open="showImport" :title="t('events_sessions.import_customers_action')" max-width-class="max-w-[480px]" @close="closeImport">
      <div class="flex flex-col gap-4 px-6 py-5">
        <p class="text-[13px] text-muted-4">{{ t('events_sessions.import_customers_help') }}</p>
        <button type="button" class="self-start text-[12.5px] font-semibold text-brand-active underline" @click="doDownloadCustomerImportTemplate">
          {{ t('events_sessions.download_customer_template_action') }}
        </button>

        <div class="flex flex-col gap-1.5">
          <label class="text-[13px] font-semibold" for="customer-import-file">{{ t('events_sessions.import_file_label') }}</label>
          <input id="customer-import-file" type="file" accept=".xlsx" class="text-[13px]" @change="onImportFileChosen" />
        </div>

        <BaseButton variant="secondary" :disabled="!importFile" :loading="previewing" @click="doPreviewImport">
          {{ t('events_sessions.import_preview_action') }}
        </BaseButton>

        <div v-if="previewResult" class="flex flex-col gap-2 rounded-lg border border-line-2 bg-mint-50 p-3.5">
          <template v-if="previewResult.row_errors.length === 0">
            <p class="text-[13px] font-semibold text-brand-active">
              {{ t('events_sessions.import_preview_summary', { created: previewResult.created_count, updated: previewResult.updated_count }) }}
            </p>
          </template>
          <template v-else>
            <p class="text-[13px] font-semibold text-danger-text">{{ t('events_sessions.import_preview_has_errors') }}</p>
            <ul class="flex flex-col gap-1 text-[12.5px] text-danger-text">
              <li v-for="rowError in previewResult.row_errors" :key="rowError.row">
                {{ t('events_sessions.import_row_error', { row: rowError.row, error: rowError.errors[0] }) }}
              </li>
            </ul>
          </template>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="closeImport">{{ t('common.cancel') }}</BaseButton>
          <BaseButton
            :disabled="!previewResult || previewResult.row_errors.length > 0"
            :loading="importing"
            @click="doConfirmImport"
          >
            {{ t('events_sessions.import_confirm_action') }}
          </BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      :title="t('events_sessions.delete_customer')"
      :message="t('events_sessions.delete_customer_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
