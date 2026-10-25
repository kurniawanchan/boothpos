<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listUsers, createUser, updateUser, deleteUser, uploadUserPhoto, setUserOutlets } from '../api/users';
import { listRoles } from '../api/roles';
import { useAuthStore } from '../stores/auth';
import { useOutletStore } from '../stores/outlet';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import PageHeader from '../components/page/PageHeader.vue';
import FilterBar from '../components/page/FilterBar.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import SetPinModal from '../components/users/SetPinModal.vue';

const auth = useAuthStore();
const { t, locale } = useI18n();
const toast = useToastStore();

const { items, meta, loading, error, load, setPage, setFilter } = usePaginatedList(listUsers);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

const outletStore = useOutletStore();
const roleOptions = ref([]);
const roleFilterOptions = computed(() => [{ value: '', label: t('users.all_roles') }, ...roleOptions.value]);
const statusFilterOptions = computed(() => [
  { value: '', label: t('users.all_status') },
  { value: '1', label: t('users.active') },
  { value: '0', label: t('users.inactive') },
]);
// 074 — filter status PIN: '' semua, '1' sudah punya PIN, '0' belum (dikirim ke server sebagai has_pin).
const pinFilterOptions = computed(() => [
  { value: '', label: t('users.pin_filter_all') },
  { value: '1', label: t('users.pin_filter_has') },
  { value: '0', label: t('users.pin_filter_none') },
]);
const roleFilter = ref('');
const statusFilter = ref('');
const pinFilter = ref('');

async function loadRoles() {
  try {
    const res = await listRoles({ per_page: 100 });
    roleOptions.value = (res.data ?? []).map((r) => ({ value: r.id, label: r.name, allOutlets: r.all_outlets === true }));
  } catch {
    // Gagal memuat daftar peran tidak boleh meng-crash layar pengguna —
    // dropdown peran kosong, form tetap bisa dibuka untuk field lain.
    roleOptions.value = [];
  }
}

onMounted(() => {
  load();
  loadRoles();
});

function onSearchInput(value) {
  search.value = value;
  debouncedSearch();
}

// Penghitung FilterBar menggambarkan HASIL yang sedang difilter — total dari server (daftar berpaginasi).
const filtersActive = computed(() => Boolean(search.value || roleFilter.value || statusFilter.value || pinFilter.value));
function resetFilters() {
  search.value = '';
  roleFilter.value = '';
  statusFilter.value = '';
  pinFilter.value = '';
  setFilter({ search: undefined, role_id: undefined, is_active: undefined, has_pin: undefined });
}

function onRoleFilterChange(value) {
  roleFilter.value = value;
  setFilter({ role_id: value || undefined });
}
function onStatusFilterChange(value) {
  statusFilter.value = value;
  setFilter({ is_active: value === '' ? undefined : value });
}
function onPinFilterChange(value) {
  pinFilter.value = value;
  setFilter({ has_pin: value === '' ? undefined : value });
}

// 045 Stage 2 — kolom Outlet hanya bila ada lebih dari satu outlet (di satu outlet disembunyikan, bukan dinonaktifkan).
const columns = computed(() => [
  { key: 'name', label: t('users.col_name') },
  { key: 'username', label: t('users.col_username') },
  { key: 'role', label: t('users.col_role') },
  ...(outletStore.multi ? [{ key: 'outlets', label: t('users.col_outlets') }] : []),
  { key: 'pin', label: t('users.col_pin') },
  { key: 'last_login_at', label: t('users.col_last_access') },
  { key: 'is_active', label: t('users.col_status') },
  { key: 'actions', label: '' },
]);

const outletCodes = (row) => (row.outlet_ids ?? []).map((id) => outletStore.outlets.find((o) => o.id === id)?.code).filter(Boolean).join(', ');

function formatLastAccess(value) {
  if (!value) return t('users.never_accessed');
  return new Date(value).toLocaleString(locale.value === 'id' ? 'id-ID' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' });
}

const showForm = ref(false);
const editingUser = ref(null);
const form = reactive({ name: '', username: '', password: '', role_id: '', is_active: true, outlet_ids: [] });
// 045 Stage 2 — penugasan outlet hanya relevan bila ada lebih dari satu outlet dan peran bukan Owner/Admin
// (peran ber-flag all_outlets melihat semuanya). Di satu outlet kolomnya disembunyikan, bukan dinonaktifkan.
const showOutlets = computed(() => outletStore.multi && form.role_id !== '' && !roleOptions.value.find((r) => r.value === form.role_id)?.allOutlets);
const formErrors = reactive({});
const saving = ref(false);
const photoFile = ref(null);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

// 074 — mengatur PIN pengguna. Tombolnya disembunyikan untuk yang tidak berhak (kosmetik; server menolak 403 lewat
// SetUserPinRequest). `pinTarget` adalah salinan baris saat dialog dibuka: judulnya ("Atur"/"Reset") tidak berubah di tengah
// dialog setelah baris diperbarui oleh respons simpan.
const canSetPin = computed(() => auth.canAccessMenu('settings'));
const showPin = ref(false);
const pinTarget = ref(null);

function openPin(row) {
  pinTarget.value = { ...row };
  showPin.value = true;
}

function onPinSaved(updated) {
  const i = items.value.findIndex((u) => u.id === updated.id);
  if (i !== -1) items.value.splice(i, 1, { ...items.value[i], ...updated });
  toast.success(t('users.pin_saved', { name: updated.name }));
}

// FR-006 (swa-kunci) — kenyamanan UI untuk MENYEMBUNYIKAN aksi terhadap
// akun sendiri, bukan penegakan; server (UserPolicy::isSelfLockout) yang
// sesungguhnya menolak dengan 409. Konsisten dengan pola "sembunyikan,
// jangan nonaktifkan-lalu-403" yang sudah dipakai di layar lain.
function isSelf(row) {
  return row.id === auth.user?.id;
}

function openCreate() {
  editingUser.value = null;
  Object.assign(form, { name: '', username: '', password: '', role_id: '', is_active: true, outlet_ids: [] });
  photoFile.value = null;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(user) {
  editingUser.value = user;
  Object.assign(form, {
    name: user.name,
    username: user.username,
    password: '',
    role_id: user.role?.id ?? '',
    is_active: user.is_active,
    outlet_ids: [...(user.outlet_ids ?? [])],
  });
  photoFile.value = null;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function onPhotoChange(e) {
  photoFile.value = e.target.files?.[0] ?? null;
}

async function saveUser() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    name: form.name,
    username: form.username,
    role_id: form.role_id,
    is_active: form.is_active,
  };
  if (form.password) payload.password = form.password;

  try {
    let saved;
    if (editingUser.value) {
      saved = await updateUser(editingUser.value.id, payload);
      toast.success(t('users.user_updated'));
    } else {
      saved = await createUser(payload);
      toast.success(t('users.user_created'));
    }

    // BUG YANG DITEMUKAN & DIPERBAIKI — sebelumnya uploadUserPhoto() ada
    // di DALAM try/catch yang sama dengan updateUser()/createUser(),
    // padahal keduanya sudah BERHASIL tersimpan di titik ini. Kalau
    // upload foto gagal (mis. tipe berkas ditolak ImageUploadService),
    // exception itu ditangkap catch di bawah yang HANYA menangani
    // err.isValidation — untuk error lain (dan bahkan untuk error
    // validasi, karena field `image` tidak pernah dirender di form ini)
    // pengguna tidak melihat pesan apa pun, dan `showForm.value = false`
    // tidak pernah tercapai — modal terlihat "macet" padahal perubahan
    // nama/peran/status sebenarnya sudah tersimpan. Sekarang foto
    // ditangani terpisah: kegagalannya tidak lagi menahan modal tetap
    // terbuka, hanya menampilkan toast tersendiri.
    if (photoFile.value && saved?.id) {
      try {
        await uploadUserPhoto(saved.id, photoFile.value);
      } catch (photoErr) {
        if (photoErr.isValidation) toast.error(photoErr.errors?.image?.[0] || t('users.photo_upload_failed'));
        else toast.error(photoErr.message || t('users.photo_upload_failed'));
      }
    }

    // Penugasan outlet disimpan terpisah (endpoint sendiri); kegagalannya tidak menahan modal, hanya toast.
    if (showOutlets.value && saved?.id) {
      try {
        await setUserOutlets(saved.id, form.outlet_ids);
      } catch (outletErr) {
        toast.error(outletErr.message || t('users.save_failed'));
      }
    }

    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) {
      Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
    } else {
      toast.error(err.message || t('users.save_failed'));
    }
  } finally {
    saving.value = false;
  }
}

function confirmDelete(user) {
  deleteTarget.value = user;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteUser(deleteTarget.value.id);
    toast.success(t('users.user_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // 409 (swa-kunci) sudah ditoast oleh interceptor bersama.
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <PageHeader :title="t('nav.users')" :description="t('nav.users_subtitle')">
    <template #actions>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('users.add_user') }}
      </BaseButton>
    </template>
  </PageHeader>

  <div class="px-[26px] pb-10">
    <section class="overflow-hidden border-2 border-line bg-white">
      <FilterBar
        :search="search"
        :placeholder="t('users.search_user_placeholder')"
        :search-label="t('users.search_user')"
        :count="meta.total"
        :active="filtersActive"
        @update:search="onSearchInput"
        @reset="resetFilters"
      >
        <div class="w-[180px]">
          <BaseSelect :model-value="roleFilter" :options="roleFilterOptions" :placeholder="t('users.all_roles')" @update:model-value="onRoleFilterChange" />
        </div>
        <div class="w-[160px]">
          <BaseSelect :model-value="statusFilter" :options="statusFilterOptions" :placeholder="t('users.all_status')" @update:model-value="onStatusFilterChange" />
        </div>
        <div class="w-[170px]">
          <BaseSelect :model-value="pinFilter" :options="pinFilterOptions" :placeholder="t('users.pin_filter_all')" @update:model-value="onPinFilterChange" />
        </div>
      </FilterBar>

      <DataTable
        :columns="columns"
        :rows="items"
        :loading="loading"
        :error="error ? error.message || t('page.load_failed') : ''"
        :empty-message="t('users.no_users')"
        @retry="load"
      >
        <template #cell-name="{ row }">
          <div class="flex items-center gap-2.5">
            <img v-if="row.photo_url" :src="row.photo_url" class="h-8 w-8 rounded-full object-cover" alt="" />
            <div v-else class="flex h-8 w-8 items-center justify-center rounded-full bg-line-5 text-[12px] font-bold text-muted-3">
              {{ row.name?.charAt(0)?.toUpperCase() }}
            </div>
            <span class="font-semibold text-ink">{{ row.name }}</span>
          </div>
        </template>
        <template #cell-role="{ row }">{{ row.role?.name ?? '—' }}</template>
        <template #cell-outlets="{ row }">
          <span v-if="row.all_outlets" class="text-[12.5px] font-semibold text-muted-5" data-testid="user-outlets-all">{{ t('users.outlets_all') }}</span>
          <span v-else-if="!(row.outlet_ids ?? []).length" class="text-[12.5px] text-danger-text" data-testid="user-outlets-none">{{ t('users.outlets_none') }}</span>
          <span v-else class="text-[12.5px] text-muted-5" data-testid="user-outlets-codes">{{ outletCodes(row) }}</span>
        </template>
        <template #cell-pin="{ row }">
          <StatusPill v-if="row.pin_locked" variant="danger" data-testid="user-pin-status">{{ t('users.pin_locked') }}</StatusPill>
          <StatusPill v-else-if="row.has_pin" variant="success" data-testid="user-pin-status">{{ t('users.pin_has') }}</StatusPill>
          <StatusPill v-else variant="neutral" data-testid="user-pin-status">{{ t('users.pin_none') }}</StatusPill>
        </template>
        <template #cell-last_login_at="{ row }">
          <span class="text-[12.5px] text-muted-4">{{ formatLastAccess(row.last_login_at) }}</span>
        </template>
        <template #cell-is_active="{ row }">
          <StatusPill :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('users.active') : t('users.inactive') }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button
              v-if="canSetPin"
              type="button"
              class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active"
              data-testid="user-pin-action"
              @click="openPin(row)"
            >
              {{ row.has_pin ? t('users.pin_reset') : t('users.pin_set') }}
            </button>
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button
              v-if="!isSelf(row)"
              type="button"
              class="text-[12.5px] font-semibold text-danger-text hover:text-danger-text"
              @click="confirmDelete(row)"
            >
              {{ t('common.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </section>
  </div>

  <BaseModal :open="showForm" :title="editingUser ? t('users.edit_user') : t('users.new_user')" max-width-class="max-w-[480px]" @close="showForm = false">
    <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveUser">
      <BaseInput v-model="form.name" :label="t('users.name')" required maxlength="100" :error="formErrors.name" />
      <BaseInput v-model="form.username" :label="t('users.username')" required maxlength="50" :error="formErrors.username" />
      <BaseInput
        v-model="form.password"
        :label="t('users.password')"
        type="password"
        :required="!editingUser"
        :hint="editingUser ? t('users.password_hint_edit') : ''"
        :error="formErrors.password"
      />
      <BaseSelect
        v-model="form.role_id"
        :label="t('users.role')"
        required
        :options="roleOptions"
        :disabled="isSelf(editingUser ?? {})"
        :hint="isSelf(editingUser ?? {}) ? t('users.cannot_change_own_role') : ''"
        :error="formErrors.role_id"
      />
      <fieldset v-if="showOutlets" class="flex flex-col gap-1.5" data-testid="user-outlets">
        <legend class="text-[12.5px] font-semibold text-muted-4">{{ t('frame.outlet_label') }}</legend>
        <label v-for="o in outletStore.outlets" :key="o.id" class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.outlet_ids" type="checkbox" :value="o.id" class="h-4 w-4 rounded border-line accent-brand" />
          {{ o.name }}
        </label>
      </fieldset>
      <div class="flex flex-col gap-1.5">
        <span class="text-[12.5px] font-semibold text-muted-4">{{ t('users.photo') }}</span>
        <input type="file" accept="image/jpeg,image/png" class="text-[12.5px]" @change="onPhotoChange" />
      </div>
      <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
        <input
          v-model="form.is_active"
          type="checkbox"
          class="h-4 w-4 rounded border-line accent-brand"
          :disabled="isSelf(editingUser ?? {})"
        />
        {{ t('users.active') }}
      </label>
    </form>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="saving" @click="saveUser">{{ t('common.save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>

  <ConfirmDialog
    :open="showDelete"
    :title="t('users.delete_user')"
    :message="t('users.delete_user_confirm', { name: deleteTarget?.name })"
    :confirm-label="t('vendors_materials.yes_delete')"
    :loading="deleting"
    @close="showDelete = false"
    @confirm="performDelete"
  />

  <SetPinModal :open="showPin" :user="pinTarget" @close="showPin = false" @saved="onPinSaved" />
</template>
