<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listLicenses, createLicense, updateLicense, deleteLicense } from '../api/licenses';
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

// 019-billing-system — layar mandiri baru untuk `License` (menggantikan
// `PackagesView.vue`, yang dihapus di komit yang sama). License sekarang
// punya menu key sendiri ('licenses', lihat MenuKeys::ALL &
// AppSidebar.vue's NAV_DEFS) — tidak lagi menumpang menu key 'companies'.

const { t } = useI18n();
const toast = useToastStore();

const { items, meta, loading, load, setPage } = usePaginatedList(listLicenses);
onMounted(load);

const licenseTierOptions = computed(() => [
  { value: 'pro', label: t('licenses.license_tier_pro') },
  { value: 'master', label: t('licenses.license_tier_master') },
]);

const paymentTypeOptions = computed(() => [
  { value: 'one_time', label: t('licenses.payment_type_one_time') },
  { value: 'subscription', label: t('licenses.payment_type_subscription') },
]);

const columns = computed(() => [
  { key: 'name', label: t('master_data.col_name') },
  { key: 'license_tier', label: t('licenses.col_license_tier') },
  { key: 'price', label: t('licenses.col_price') },
  { key: 'payment_type', label: t('licenses.col_payment_type') },
  { key: 'is_active', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editingLicense = ref(null);
const form = reactive({ name: '', description: '', license_tier: 'pro', price: '', payment_type: 'one_time', is_active: true });
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function openCreate() {
  editingLicense.value = null;
  Object.assign(form, { name: '', description: '', license_tier: 'pro', price: '', payment_type: 'one_time', is_active: true });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(license) {
  editingLicense.value = license;
  Object.assign(form, {
    name: license.name,
    description: license.description ?? '',
    license_tier: license.license_tier,
    price: license.price,
    payment_type: license.payment_type,
    is_active: license.is_active,
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveLicense() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    name: form.name,
    description: form.description || null,
    license_tier: form.license_tier,
    price: form.price,
    payment_type: form.payment_type,
    is_active: form.is_active,
  };
  try {
    if (editingLicense.value) {
      await updateLicense(editingLicense.value.id, payload);
      toast.success(t('licenses.license_updated'));
    } else {
      await createLicense(payload);
      toast.success(t('licenses.license_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

function confirmDelete(license) {
  deleteTarget.value = license;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteLicense(deleteTarget.value.id);
    toast.success(t('licenses.license_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // 409 (masih dirujuk Company/Invoice) sudah ditoast oleh interceptor bersama.
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
        {{ t('licenses.new_license_btn') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('licenses.no_licenses')">
        <template #cell-license_tier="{ row }">
          <StatusPill variant="mint">{{ t(`licenses.license_tier_${row.license_tier}`) }}</StatusPill>
        </template>
        <template #cell-price="{ row }">{{ row.price }}</template>
        <template #cell-payment_type="{ row }">{{ t(`licenses.payment_type_${row.payment_type}`) }}</template>
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

    <BaseModal :open="showForm" :title="editingLicense ? t('licenses.edit_license') : t('licenses.new_license')" max-width-class="max-w-[480px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveLicense">
        <BaseInput v-model="form.name" :label="t('licenses.license_name')" required maxlength="100" :error="formErrors.name" />
        <BaseTextarea v-model="form.description" :label="t('master_data.notes')" :rows="2" :error="formErrors.description" />
        <BaseSelect v-model="form.license_tier" :label="t('licenses.license_tier')" :options="licenseTierOptions" required :error="formErrors.license_tier" />
        <BaseInput v-model="form.price" type="number" min="0" step="0.01" :label="t('licenses.price')" required :error="formErrors.price" />
        <BaseSelect v-model="form.payment_type" :label="t('licenses.payment_type')" :options="paymentTypeOptions" required :error="formErrors.payment_type" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          {{ t('common.active') }}
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="saveLicense">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      :title="t('licenses.delete_license')"
      :message="t('licenses.delete_license_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('vendors_materials.yes_delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
