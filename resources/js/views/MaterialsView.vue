<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listMaterials, createMaterial, updateMaterial, deleteMaterial } from '../api/materials';
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
import MaterialVendorPricesModal from '../components/masterData/MaterialVendorPricesModal.vue';

const auth = useAuthStore();
const { t } = useI18n();
const toast = useToastStore();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listMaterials);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

onMounted(load);

const exporting = ref(false);
const showImportModal = ref(false);

async function doExport() {
  exporting.value = true;
  try {
    await exportMasterData('materials');
  } catch {
    toast.error(t('vendors_materials.export_material_failed'));
  } finally {
    exporting.value = false;
  }
}

async function afterImport() {
  await load();
}

const columns = computed(() => [
  { key: 'code', label: t('master_data.col_code') },
  { key: 'name', label: t('master_data.col_name') },
  { key: 'unit', label: t('vendors_materials.col_unit') },
  { key: 'vendor_price_count', label: t('vendors_materials.col_vendor_count') },
  { key: 'is_active', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editingMaterial = ref(null);
const form = reactive({ code: '', name: '', unit: '', notes: '', is_active: true });
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

const showPrices = ref(false);
const pricesMaterialId = ref(null);

function openCreate() {
  editingMaterial.value = null;
  Object.assign(form, { code: '', name: '', unit: '', notes: '', is_active: true });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(material) {
  editingMaterial.value = material;
  Object.assign(form, {
    code: material.code,
    name: material.name,
    unit: material.unit,
    notes: material.notes ?? '',
    is_active: material.is_active,
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openPrices(material) {
  pricesMaterialId.value = material.id;
  showPrices.value = true;
}

async function saveMaterial() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    code: form.code.toUpperCase(),
    name: form.name,
    unit: form.unit,
    notes: form.notes || null,
    is_active: form.is_active,
  };
  try {
    if (editingMaterial.value) {
      await updateMaterial(editingMaterial.value.id, payload);
      toast.success(t('vendors_materials.material_updated'));
    } else {
      await createMaterial(payload);
      toast.success(t('vendors_materials.material_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

function confirmDelete(material) {
  deleteTarget.value = material;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteMaterial(deleteTarget.value.id);
    toast.success(t('vendors_materials.material_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // 409 (masih dipakai harga vendor/BOM) sudah ditoast interceptor bersama.
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
        <label class="sr-only" for="material-search">{{ t('vendors_materials.search_material') }}</label>
        <input
          id="material-search"
          v-model="search"
          :placeholder="t('vendors_materials.search_material_placeholder')"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <template v-if="auth.canAccessMenu('materials')">
        <BaseButton variant="secondary" :loading="exporting" @click="doExport">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          {{ t('common.export_xlsx') }}
        </BaseButton>
        <BaseButton variant="secondary" @click="showImportModal = true">
          <i class="ph-duotone ph-file-arrow-up text-[16px]" aria-hidden="true"></i>
          {{ t('common.bulk_import') }}
        </BaseButton>
      </template>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('vendors_materials.new_material_btn') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('vendors_materials.no_materials')">
        <template #cell-code="{ row }"><span class="font-mono text-[12.5px] font-bold text-brand-active">{{ row.code }}</span></template>
        <template #cell-vendor_price_count="{ row }">{{ row.vendor_price_count ?? 0 }}</template>
        <template #cell-is_active="{ row }">
          <StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openPrices(row)">{{ t('vendors_materials.vendor_prices_action') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text hover:text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showForm" :title="editingMaterial ? t('vendors_materials.edit_material') : t('vendors_materials.new_material')" max-width-class="max-w-[480px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveMaterial">
        <BaseInput
          v-model="form.code"
          :label="t('vendors_materials.material_code')"
          maxlength="20"
          required
          :disabled="!!editingMaterial"
          :hint="t('master_data.permanent_after_creation')"
          :error="formErrors.code"
        />
        <BaseInput v-model="form.name" :label="t('vendors_materials.material_name')" required maxlength="150" :error="formErrors.name" />
        <BaseInput v-model="form.unit" :label="t('vendors_materials.unit_with_examples')" required maxlength="20" :error="formErrors.unit" />
        <BaseTextarea v-model="form.notes" :label="t('master_data.notes')" :rows="2" :error="formErrors.notes" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          {{ t('common.active') }}
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="saveMaterial">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      :title="t('vendors_materials.delete_material')"
      :message="t('vendors_materials.delete_material_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('vendors_materials.yes_delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />

    <MasterDataImportModal :open="showImportModal" @close="showImportModal = false" @imported="afterImport" />
    <MaterialVendorPricesModal :open="showPrices" :material-id="pricesMaterialId" @close="showPrices = false" @changed="load" />
  </div>
</template>
