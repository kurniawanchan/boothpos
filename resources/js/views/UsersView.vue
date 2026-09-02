<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listUsers, createUser, updateUser, deleteUser, uploadUserPhoto } from '../api/users';
import { listRoles } from '../api/roles';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';

const auth = useAuthStore();
const toast = useToastStore();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listUsers);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

const roleOptions = ref([]);
const roleFilterOptions = computed(() => [{ value: '', label: 'Semua peran' }, ...roleOptions.value]);
const statusFilterOptions = [
  { value: '', label: 'Semua status' },
  { value: '1', label: 'Aktif' },
  { value: '0', label: 'Nonaktif' },
];
const roleFilter = ref('');
const statusFilter = ref('');

async function loadRoles() {
  try {
    const res = await listRoles({ per_page: 100 });
    roleOptions.value = (res.data ?? []).map((r) => ({ value: r.id, label: r.name }));
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

function onRoleFilterChange(value) {
  roleFilter.value = value;
  setFilter({ role_id: value || undefined });
}
function onStatusFilterChange(value) {
  statusFilter.value = value;
  setFilter({ is_active: value === '' ? undefined : value });
}

const columns = [
  { key: 'name', label: 'Nama' },
  { key: 'username', label: 'Username' },
  { key: 'role', label: 'Peran' },
  { key: 'last_login_at', label: 'Terakhir Akses' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: '' },
];

function formatLastAccess(value) {
  if (!value) return 'Belum pernah';
  return new Date(value).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
}

const showForm = ref(false);
const editingUser = ref(null);
const form = reactive({ name: '', username: '', password: '', role_id: '', is_active: true });
const formErrors = reactive({});
const saving = ref(false);
const photoFile = ref(null);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

// FR-006 (swa-kunci) — kenyamanan UI untuk MENYEMBUNYIKAN aksi terhadap
// akun sendiri, bukan penegakan; server (UserPolicy::isSelfLockout) yang
// sesungguhnya menolak dengan 409. Konsisten dengan pola "sembunyikan,
// jangan nonaktifkan-lalu-403" yang sudah dipakai di layar lain.
function isSelf(row) {
  return row.id === auth.user?.id;
}

function openCreate() {
  editingUser.value = null;
  Object.assign(form, { name: '', username: '', password: '', role_id: '', is_active: true });
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
      toast.success('Pengguna diperbarui.');
    } else {
      saved = await createUser(payload);
      toast.success('Pengguna dibuat.');
    }

    if (photoFile.value && saved?.id) {
      await uploadUserPhoto(saved.id, photoFile.value);
    }

    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
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
    toast.success('Pengguna dihapus.');
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
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="relative flex min-w-[230px] flex-1 items-center">
        <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[16px] text-muted-3" aria-hidden="true"></i>
        <label class="sr-only" for="user-search">Cari pengguna</label>
        <input
          id="user-search"
          v-model="search"
          placeholder="Cari nama atau username…"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <div class="w-[180px]">
        <BaseSelect :model-value="roleFilter" :options="roleFilterOptions" placeholder="Semua peran" @update:model-value="onRoleFilterChange" />
      </div>
      <div class="w-[160px]">
        <BaseSelect :model-value="statusFilter" :options="statusFilterOptions" placeholder="Semua status" @update:model-value="onStatusFilterChange" />
      </div>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        Tambah pengguna
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" empty-message="Belum ada pengguna.">
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
        <template #cell-last_login_at="{ row }">
          <span class="text-[12.5px] text-muted-4">{{ formatLastAccess(row.last_login_at) }}</span>
        </template>
        <template #cell-is_active="{ row }">
          <StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">Edit</button>
            <button
              v-if="!isSelf(row)"
              type="button"
              class="text-[12.5px] font-semibold text-danger-text hover:text-danger-text"
              @click="confirmDelete(row)"
            >
              Hapus
            </button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showForm" :title="editingUser ? 'Ubah pengguna' : 'Pengguna baru'" max-width-class="max-w-[480px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveUser">
        <BaseInput v-model="form.name" label="Nama" required maxlength="100" :error="formErrors.name" />
        <BaseInput v-model="form.username" label="Username" required maxlength="50" :error="formErrors.username" />
        <BaseInput
          v-model="form.password"
          label="Password"
          type="password"
          :required="!editingUser"
          :hint="editingUser ? 'Kosongkan bila tidak ingin mengubah password.' : ''"
          :error="formErrors.password"
        />
        <BaseSelect
          v-model="form.role_id"
          label="Peran"
          required
          :options="roleOptions"
          :disabled="isSelf(editingUser ?? {})"
          :hint="isSelf(editingUser ?? {}) ? 'Tidak dapat mengganti peran akun sendiri.' : ''"
          :error="formErrors.role_id"
        />
        <div class="flex flex-col gap-1.5">
          <span class="text-[12.5px] font-semibold text-muted-4">Foto</span>
          <input type="file" accept="image/jpeg,image/png" class="text-[12.5px]" @change="onPhotoChange" />
        </div>
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input
            v-model="form.is_active"
            type="checkbox"
            class="h-4 w-4 rounded border-line accent-brand"
            :disabled="isSelf(editingUser ?? {})"
          />
          Aktif
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">Batal</BaseButton>
          <BaseButton :loading="saving" @click="saveUser">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      title="Hapus pengguna"
      :message="`Hapus ${deleteTarget?.name}?`"
      confirm-label="Ya, hapus"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
