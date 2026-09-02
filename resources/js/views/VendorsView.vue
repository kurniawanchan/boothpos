<script setup>
import { reactive, ref, onMounted } from 'vue';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listVendors, createVendor, updateVendor, deleteVendor } from '../api/vendors';
import { exportMasterData } from '../api/masterData';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import MasterDataImportModal from '../components/masterData/MasterDataImportModal.vue';

const auth = useAuthStore();
const toast = useToastStore();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listVendors);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

onMounted(load);

const exporting = ref(false);
const showImportModal = ref(false);

async function doExport() {
  exporting.value = true;
  try {
    await exportMasterData('vendors');
  } catch {
    toast.error('Gagal mengekspor data vendor.');
  } finally {
    exporting.value = false;
  }
}

async function afterImport() {
  await load();
}

const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama' },
  { key: 'contact', label: 'Kontak' },
  { key: 'material_price_count', label: 'Bahan' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: '' },
];

const showForm = ref(false);
const editingVendor = ref(null);
const form = reactive({ code: '', name: '', contact_phone: '', contact_email: '', notes: '', is_active: true });
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function openCreate() {
  editingVendor.value = null;
  Object.assign(form, { code: '', name: '', contact_phone: '', contact_email: '', notes: '', is_active: true });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(vendor) {
  editingVendor.value = vendor;
  Object.assign(form, {
    code: vendor.code,
    name: vendor.name,
    contact_phone: vendor.contact_phone ?? '',
    contact_email: vendor.contact_email ?? '',
    notes: vendor.notes ?? '',
    is_active: vendor.is_active,
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveVendor() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    code: form.code.toUpperCase(),
    name: form.name,
    contact_phone: form.contact_phone || null,
    contact_email: form.contact_email || null,
    notes: form.notes || null,
    is_active: form.is_active,
  };
  try {
    if (editingVendor.value) {
      await updateVendor(editingVendor.value.id, payload);
      toast.success('Vendor diperbarui.');
    } else {
      await createVendor(payload);
      toast.success('Vendor dibuat.');
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

function confirmDelete(vendor) {
  deleteTarget.value = vendor;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteVendor(deleteTarget.value.id);
    toast.success('Vendor dihapus.');
    showDelete.value = false;
    await load();
  } catch {
    // 409 (masih punya harga bahan terdaftar) sudah ditoast oleh interceptor bersama.
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="relative flex min-w-[230px] flex-1 items-center">
        <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[16px] text-muted-3" aria-hidden="true"></i>
        <label class="sr-only" for="vendor-search">Cari vendor</label>
        <input
          id="vendor-search"
          v-model="search"
          placeholder="Cari nama vendor…"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <template v-if="auth.canAccessMenu('vendors')">
        <BaseButton variant="secondary" :loading="exporting" @click="doExport">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          Ekspor .xlsx
        </BaseButton>
        <BaseButton variant="secondary" @click="showImportModal = true">
          <i class="ph-duotone ph-file-arrow-up text-[16px]" aria-hidden="true"></i>
          Impor massal
        </BaseButton>
      </template>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        Tambah vendor
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" empty-message="Belum ada vendor.">
        <template #cell-code="{ row }"><span class="font-mono text-[12.5px] font-bold text-brand-active">{{ row.code }}</span></template>
        <template #cell-contact="{ row }">
          <div class="flex flex-col gap-0.5 text-[12.5px] text-muted-4">
            <span>{{ row.contact_phone || '—' }}</span>
            <span class="text-muted-3">{{ row.contact_email || '' }}</span>
          </div>
        </template>
        <template #cell-material_price_count="{ row }">{{ row.material_price_count ?? 0 }}</template>
        <template #cell-is_active="{ row }">
          <StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">Edit</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text hover:text-danger-text" @click="confirmDelete(row)">Hapus</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showForm" :title="editingVendor ? 'Ubah vendor' : 'Vendor baru'" max-width-class="max-w-[480px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveVendor">
        <BaseInput
          v-model="form.code"
          label="Kode vendor"
          maxlength="20"
          required
          :disabled="!!editingVendor"
          hint="Permanen setelah dibuat."
          :error="formErrors.code"
        />
        <BaseInput v-model="form.name" label="Nama vendor" required maxlength="150" :error="formErrors.name" />
        <BaseInput v-model="form.contact_phone" label="Telepon" :error="formErrors.contact_phone" />
        <BaseInput v-model="form.contact_email" label="Email" type="email" :error="formErrors.contact_email" />
        <BaseTextarea v-model="form.notes" label="Catatan" :rows="2" :error="formErrors.notes" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          Aktif
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">Batal</BaseButton>
          <BaseButton :loading="saving" @click="saveVendor">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      title="Hapus vendor"
      :message="`Hapus ${deleteTarget?.name}? Ditolak bila masih memiliki harga bahan yang terdaftar.`"
      confirm-label="Ya, hapus"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />

    <MasterDataImportModal :open="showImportModal" @close="showImportModal = false" @imported="afterImport" />
  </div>
</template>
