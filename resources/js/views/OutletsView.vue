<script setup>
import { computed, reactive, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listOutlets, createOutlet, updateOutlet, deleteOutlet } from '../api/outlets';
import { exportMasterData } from '../api/masterData';
import { useAuthStore } from '../stores/auth';
import { useSettingsStore } from '../stores/settings';
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
import PageHeader from '../components/page/PageHeader.vue';
import FilterBar from '../components/page/FilterBar.vue';

const auth = useAuthStore();
const { t } = useI18n();
const settings = useSettingsStore();
const toast = useToastStore();

const { items, meta, params, loading, error, load, setPage, setFilter } = usePaginatedList(listOutlets);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

function onSearch(value) {
  search.value = value;
  debouncedSearch();
}
// 041 — FilterBar menampilkan "Atur ulang filter" hanya saat pencarian terpasang; hasilnya sama dengan
// mengosongkan kolom cari (satu-satunya filter halaman ini).
function resetFilters() {
  search.value = '';
  setFilter({ search: undefined });
}

onMounted(async () => {
  await settings.load();
  await load();
});

const exporting = ref(false);
const showImportModal = ref(false);

async function doExport() {
  exporting.value = true;
  try {
    await exportMasterData('outlets');
  } catch {
    toast.error(t('master_data.export_artist_failed'));
  } finally {
    exporting.value = false;
  }
}

async function afterImport() {
  // Refresh in the background but leave the modal open so the user still
  // sees the applied summary — they close it themselves via "Tutup".
  // Outlet count/quota may have changed — the Pro/Master license gate reads
  // from settings, so refresh it alongside the list itself.
  await Promise.all([load(), settings.load()]);
}

const columns = computed(() => [
  { key: 'code', label: t('master_data.col_code') },
  { key: 'name', label: t('master_data.col_name') },
  { key: 'contact', label: t('master_data.col_contact') },
  { key: 'product_count', label: t('master_data.col_product_count') },
  { key: 'is_active', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editingOutlet = ref(null);
const form = reactive({ code: '', name: '', contact_phone: '', contact_email: '', notes: '', is_active: true });
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

const canCreate = () => settings.multiOutletEnabled || !settings.outletLimitReached;

function openCreate() {
  editingOutlet.value = null;
  Object.assign(form, { code: '', name: '', contact_phone: '', contact_email: '', notes: '', is_active: true });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(outlet) {
  editingOutlet.value = outlet;
  Object.assign(form, {
    code: outlet.code,
    name: outlet.name,
    contact_phone: outlet.contact_phone ?? '',
    contact_email: outlet.contact_email ?? '',
    notes: outlet.notes ?? '',
    is_active: outlet.is_active,
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveOutlet() {
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
    if (editingOutlet.value) {
      await updateOutlet(editingOutlet.value.id, payload);
      toast.success(t('master_data.artist_updated'));
    } else {
      await createOutlet(payload);
      toast.success(t('master_data.artist_created'));
      await settings.load();
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
    // 403 kuota lisensi Pro sudah ditoast oleh interceptor bersama.
  } finally {
    saving.value = false;
  }
}

function confirmDelete(outlet) {
  deleteTarget.value = outlet;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteOutlet(deleteTarget.value.id);
    toast.success(t('master_data.artist_deactivated'));
    showDelete.value = false;
    await Promise.all([load(), settings.load()]);
  } catch {
    // 409 (masih ada produk aktif) sudah ditoast oleh interceptor bersama.
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div class="pb-10">
    <!-- 041 — judul/deskripsi/tindakan tingkat-halaman dari PageHeader. Tombol Tambah tetap NONAKTIF (bukan
         disembunyikan) saat kuota Pro habis, persis seperti sebelumnya: banner di bawah menjelaskan alasannya. -->
    <PageHeader :title="t('nav.artists')" :description="t('nav.artists_subtitle')">
      <template #actions>
        <template v-if="auth.canAccessMenu('outlets')">
          <BaseButton variant="secondary" :loading="exporting" @click="doExport">
            <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
            {{ t('common.export_xlsx') }}
          </BaseButton>
          <BaseButton variant="secondary" @click="showImportModal = true">
            <i class="ph-duotone ph-file-arrow-up text-[16px]" aria-hidden="true"></i>
            {{ t('common.bulk_import') }}
          </BaseButton>
        </template>
        <BaseButton :disabled="!canCreate()" @click="openCreate">
          <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
          {{ t('master_data.add_artist') }}
        </BaseButton>
      </template>
    </PageHeader>

    <div v-if="!settings.multiOutletEnabled && settings.outletLimitReached" class="mx-[26px] mb-4 flex items-start gap-3 rounded-card border border-warn-border bg-warn-bg px-4 py-3.5">
      <i class="ph-duotone ph-lock-key text-[19px] text-warn-text" aria-hidden="true"></i>
      <div class="flex flex-1 flex-col gap-0.5">
        <span class="text-[13px] font-bold text-warn-text">{{ t('master_data.pro_license_one_artist') }}</span>
        <span class="text-[12.5px] leading-relaxed text-warn-text opacity-90">
          {{ t('master_data.add_artist_disabled_quota') }}
          <span class="font-mono text-[11.5px]">POST /outlets</span> 403.
        </span>
      </div>
      <RouterLink :to="{ name: 'settings' }" class="flex-none rounded-md border border-warn-border-strong bg-white px-3 py-1.5 text-[12.5px] font-bold text-warn-text hover:bg-surface-subtle">
        {{ t('master_data.upgrade_to_master') }}
      </RouterLink>
    </div>

    <section class="mx-[26px] border-2 border-line bg-white">
      <FilterBar
        :search="search"
        :placeholder="t('master_data.search_artist_placeholder')"
        :search-label="t('master_data.search_artist')"
        :count="meta.total"
        :active="!!search"
        @update:search="onSearch"
        @reset="resetFilters"
      />
      <DataTable
        :columns="columns"
        :rows="items"
        :loading="loading"
        :error="error ? (error.message || t('page.load_failed')) : ''"
        :empty-message="t('master_data.no_artists')"
        @retry="load"
      >
        <template #cell-code="{ row }"><span class="font-mono text-[12.5px] font-bold text-brand-active">{{ row.code }}</span></template>
        <template #cell-contact="{ row }">
          <div class="flex flex-col gap-0.5 text-[12.5px] text-muted-4">
            <span>{{ row.contact_phone || '—' }}</span>
            <span class="text-muted-3">{{ row.contact_email || '' }}</span>
          </div>
        </template>
        <template #cell-is_active="{ row }">
          <StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text hover:text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </section>

    <BaseModal :open="showForm" :title="editingOutlet ? t('master_data.edit_artist') : t('master_data.new_artist')" max-width-class="max-w-[480px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveOutlet">
        <BaseInput
          v-model="form.code"
          :label="t('master_data.code_3_letters')"
          maxlength="3"
          required
          :disabled="!!editingOutlet"
          :hint="t('master_data.code_permanent_hint')"
          :error="formErrors.code"
        />
        <BaseInput v-model="form.name" :label="t('master_data.artist_name')" required maxlength="100" :error="formErrors.name" />
        <BaseInput v-model="form.contact_phone" :label="t('master_data.phone')" :error="formErrors.contact_phone" />
        <BaseInput v-model="form.contact_email" :label="t('master_data.email')" type="email" :error="formErrors.contact_email" />
        <BaseTextarea v-model="form.notes" :label="t('master_data.notes')" :rows="2" :error="formErrors.notes" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          {{ t('common.active') }}
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="saveOutlet">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      :title="t('master_data.deactivate_artist')"
      :message="t('master_data.deactivate_artist_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('master_data.yes_deactivate')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />

    <MasterDataImportModal :open="showImportModal" @close="showImportModal = false" @imported="afterImport" />
  </div>
</template>
