<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listRoles, createRole, updateRole, deleteRole } from '../api/roles';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import PageHeader from '../components/page/PageHeader.vue';
import FilterBar from '../components/page/FilterBar.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import RoleMenuPicker from '../components/settings/RoleMenuPicker.vue';
import RolePermissionPicker from '../components/settings/RolePermissionPicker.vue';

const auth = useAuthStore();
const { t } = useI18n();
const toast = useToastStore();

const { items, meta, loading, error, load, setPage, setFilter } = usePaginatedList(listRoles);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

onMounted(load);

function onSearchInput(value) {
  search.value = value;
  debouncedSearch();
}
function resetFilters() {
  search.value = '';
  setFilter({ search: undefined });
}

const columns = computed(() => [
  { key: 'name', label: t('roles.col_role_name') },
  { key: 'menu_keys', label: t('roles.col_menu_access') },
  { key: 'user_count', label: t('roles.col_users') },
  { key: 'is_system_default', label: t('roles.col_type') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editingRole = ref(null);
const form = reactive({ name: '', menu_keys: [], permissions: [] });
const formErrors = reactive({});
const saving = ref(false);

function openCreate() {
  editingRole.value = null;
  Object.assign(form, { name: '', menu_keys: [], permissions: [] });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(role) {
  editingRole.value = role;
  // `permissions` = izin yang BERLAKU menurut server (bawaan menurut nama peran bila belum diatur); menyimpan
  // mengunci daftar ini secara eksplisit.
  Object.assign(form, { name: role.name, menu_keys: [...role.menu_keys], permissions: [...(role.permissions ?? [])] });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveRole() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = { name: form.name, menu_keys: form.menu_keys, permissions: form.permissions };
  try {
    if (editingRole.value) {
      await updateRole(editingRole.value.id, payload);
      toast.success(t('roles.role_updated'));
    } else {
      await createRole(payload);
      toast.success(t('roles.role_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    // 409 (FR-013 — peran terakhir yang bisa mengelola pengguna & peran)
    // sudah ditoast dengan pesan spesifik oleh interceptor bersama
    // (api/client.js) — pesannya berbeda dari 409 delete-guard di bawah,
    // jadi tidak perlu penanganan khusus lagi di sini.
    if (err.isValidation) {
      Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
    }
  } finally {
    saving.value = false;
  }
}

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function confirmDelete(role) {
  deleteTarget.value = role;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteRole(deleteTarget.value.id);
    toast.success(t('roles.role_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // Kedua guard 409 (FR-014 "masih dipakai N pengguna" dan FR-013
    // "peran terakhir yang bisa mengelola akses") sudah ditoast dengan
    // pesan spesifik masing-masing oleh interceptor bersama — lihat
    // RoleController::destroy() untuk teks pastinya.
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <PageHeader :title="t('nav.roles')" :description="t('nav.roles_subtitle')">
    <template #actions>
      <BaseButton v-if="auth.canAccessMenu('roles')" @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('roles.add_role') }}
      </BaseButton>
    </template>
  </PageHeader>

  <div class="px-[26px] pb-10">
    <section class="overflow-hidden border-2 border-line bg-white">
      <FilterBar
        :search="search"
        :placeholder="t('roles.search_role_placeholder')"
        :search-label="t('roles.search_role')"
        :count="meta.total"
        :active="Boolean(search)"
        @update:search="onSearchInput"
        @reset="resetFilters"
      />

      <DataTable
        :columns="columns"
        :rows="items"
        :loading="loading"
        :error="error ? error.message || t('page.load_failed') : ''"
        :empty-message="t('roles.no_roles')"
        @retry="load"
      >
        <template #cell-name="{ row }"><span class="font-semibold">{{ row.name }}</span></template>
        <template #cell-menu_keys="{ row }">
          <span class="text-[12.5px] text-muted-4">{{ t('roles.menu_count', { count: row.menu_keys.length }) }}</span>
        </template>
        <template #cell-user_count="{ row }">{{ t('roles.user_count', { count: row.user_count }) }}</template>
        <template #cell-is_system_default="{ row }">
          <StatusPill :variant="row.is_system_default ? 'neutral' : 'accent'">{{ row.is_system_default ? t('roles.default_type') : t('roles.custom_type') }}</StatusPill>
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
  </div>

  <BaseModal :open="showForm" :title="editingRole ? t('roles.edit_role') : t('roles.new_role')" max-width-class="max-w-[560px]" @close="showForm = false">
    <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveRole">
      <BaseInput v-model="form.name" :label="t('roles.role_name')" required maxlength="50" :error="formErrors.name" />
      <RoleMenuPicker v-model="form.menu_keys" :error="formErrors['menu_keys.0'] || formErrors.menu_keys" />
      <RolePermissionPicker v-model="form.permissions" :error="formErrors['permissions.0'] || formErrors.permissions" />
      <div class="mt-1 flex justify-end gap-2">
        <BaseButton type="button" variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton type="submit" :loading="saving">{{ t('common.save') }}</BaseButton>
      </div>
    </form>
  </BaseModal>

  <ConfirmDialog
    :open="showDelete"
    :title="t('roles.delete_role')"
    :message="t('roles.delete_role_confirm', { name: deleteTarget?.name })"
    :confirm-label="t('vendors_materials.yes_delete')"
    :loading="deleting"
    @close="showDelete = false"
    @confirm="performDelete"
  />
</template>
