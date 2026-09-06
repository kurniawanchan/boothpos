<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listBusinessTypes, createBusinessType, updateBusinessType, deleteBusinessType } from '../api/businessTypes';
import { useToastStore } from '../stores/toast';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';

const { t } = useI18n();
const toast = useToastStore();

const { items, meta, loading, load, setPage } = usePaginatedList(listBusinessTypes);
onMounted(load);

const columns = computed(() => [
  { key: 'code', label: t('master_data.col_code') },
  { key: 'name', label: t('master_data.col_name') },
  { key: 'company_count', label: t('companies.col_company_count') },
  { key: 'is_active', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editingType = ref(null);
const form = reactive({ code: '', name: '', is_active: true });
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function openCreate() {
  editingType.value = null;
  Object.assign(form, { code: '', name: '', is_active: true });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(businessType) {
  editingType.value = businessType;
  Object.assign(form, { code: businessType.code, name: businessType.name, is_active: businessType.is_active });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveBusinessType() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = { name: form.name, is_active: form.is_active };
  if (!editingType.value) payload.code = form.code.toUpperCase();
  try {
    if (editingType.value) {
      await updateBusinessType(editingType.value.id, payload);
      toast.success(t('companies.business_type_updated'));
    } else {
      await createBusinessType(payload);
      toast.success(t('companies.business_type_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

function confirmDelete(businessType) {
  deleteTarget.value = businessType;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteBusinessType(deleteTarget.value.id);
    toast.success(t('companies.business_type_deleted'));
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
        {{ t('companies.new_business_type_btn') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('companies.no_business_types')">
        <template #cell-code="{ row }"><span class="font-mono text-[12.5px] font-bold text-brand-active">{{ row.code }}</span></template>
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

    <BaseModal :open="showForm" :title="editingType ? t('companies.edit_business_type') : t('companies.new_business_type')" max-width-class="max-w-[420px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveBusinessType">
        <BaseInput
          v-model="form.code"
          :label="t('companies.business_type_code')"
          maxlength="20"
          required
          :disabled="!!editingType"
          :hint="t('master_data.permanent_after_creation')"
          :error="formErrors.code"
        />
        <BaseInput v-model="form.name" :label="t('companies.business_type_name')" required maxlength="100" :error="formErrors.name" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          {{ t('common.active') }}
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="saveBusinessType">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      :title="t('companies.delete_business_type')"
      :message="t('companies.delete_business_type_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('vendors_materials.yes_delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
