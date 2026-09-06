<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listPackages, createPackage, updatePackage, deletePackage } from '../api/packages';
import { useToastStore } from '../stores/toast';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';

const { t } = useI18n();
const toast = useToastStore();

const { items, meta, loading, load, setPage } = usePaginatedList(listPackages);
onMounted(load);

const licenseTierOptions = computed(() => [
  { value: 'pro', label: t('companies.license_tier_pro') },
  { value: 'master', label: t('companies.license_tier_master') },
]);

const columns = computed(() => [
  { key: 'name', label: t('master_data.col_name') },
  { key: 'license_tier', label: t('companies.col_license_tier') },
  { key: 'company_count', label: t('companies.col_company_count') },
  { key: 'is_active', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editingPackage = ref(null);
const form = reactive({ name: '', description: '', license_tier: 'pro', is_active: true });
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function openCreate() {
  editingPackage.value = null;
  Object.assign(form, { name: '', description: '', license_tier: 'pro', is_active: true });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(pkg) {
  editingPackage.value = pkg;
  Object.assign(form, {
    name: pkg.name,
    description: pkg.description ?? '',
    license_tier: pkg.license_tier,
    is_active: pkg.is_active,
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function savePackage() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    name: form.name,
    description: form.description || null,
    license_tier: form.license_tier,
    is_active: form.is_active,
  };
  try {
    if (editingPackage.value) {
      await updatePackage(editingPackage.value.id, payload);
      toast.success(t('companies.package_updated'));
    } else {
      await createPackage(payload);
      toast.success(t('companies.package_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

function confirmDelete(pkg) {
  deleteTarget.value = pkg;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deletePackage(deleteTarget.value.id);
    toast.success(t('companies.package_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // 409 (masih dirujuk company) sudah ditoast oleh interceptor bersama.
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center justify-end gap-2.5">
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('companies.new_package_btn') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('companies.no_packages')">
        <template #cell-license_tier="{ row }">
          <StatusPill variant="mint">{{ t(`companies.license_tier_${row.license_tier}`) }}</StatusPill>
        </template>
        <template #cell-company_count="{ row }">{{ row.company_count ?? 0 }}</template>
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
    </div>

    <BaseModal :open="showForm" :title="editingPackage ? t('companies.edit_package') : t('companies.new_package')" max-width-class="max-w-[480px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="savePackage">
        <BaseInput v-model="form.name" :label="t('companies.package_name')" required maxlength="100" :error="formErrors.name" />
        <BaseTextarea v-model="form.description" :label="t('master_data.notes')" :rows="2" :error="formErrors.description" />
        <BaseSelect v-model="form.license_tier" :label="t('companies.license_tier')" :options="licenseTierOptions" required :error="formErrors.license_tier" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          {{ t('common.active') }}
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="savePackage">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      :title="t('companies.delete_package')"
      :message="t('companies.delete_package_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('vendors_materials.yes_delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
