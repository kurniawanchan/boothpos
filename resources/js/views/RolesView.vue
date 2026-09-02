<script setup>
import { reactive, ref, onMounted } from 'vue';
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
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import RoleMenuPicker from '../components/settings/RoleMenuPicker.vue';

const auth = useAuthStore();
const toast = useToastStore();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listRoles);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

onMounted(load);

const columns = [
  { key: 'name', label: 'Nama Peran' },
  { key: 'menu_keys', label: 'Akses Menu' },
  { key: 'user_count', label: 'Pengguna' },
  { key: 'is_system_default', label: 'Tipe' },
  { key: 'actions', label: '' },
];

const showForm = ref(false);
const editingRole = ref(null);
const form = reactive({ name: '', menu_keys: [] });
const formErrors = reactive({});
const saving = ref(false);

function openCreate() {
  editingRole.value = null;
  Object.assign(form, { name: '', menu_keys: [] });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(role) {
  editingRole.value = role;
  Object.assign(form, { name: role.name, menu_keys: [...role.menu_keys] });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveRole() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = { name: form.name, menu_keys: form.menu_keys };
  try {
    if (editingRole.value) {
      await updateRole(editingRole.value.id, payload);
      toast.success('Peran diperbarui.');
    } else {
      await createRole(payload);
      toast.success('Peran dibuat.');
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
    toast.success('Peran dihapus.');
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
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="relative flex min-w-[230px] flex-1 items-center">
        <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[16px] text-muted-3" aria-hidden="true"></i>
        <label class="sr-only" for="role-search">Cari peran</label>
        <input
          id="role-search"
          v-model="search"
          placeholder="Cari nama peran…"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <BaseButton v-if="auth.canAccessMenu('roles')" @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        Tambah peran
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" empty-message="Belum ada peran.">
        <template #cell-name="{ row }"><span class="font-semibold">{{ row.name }}</span></template>
        <template #cell-menu_keys="{ row }">
          <span class="text-[12.5px] text-muted-4">{{ row.menu_keys.length }} menu</span>
        </template>
        <template #cell-user_count="{ row }">{{ row.user_count }} pengguna</template>
        <template #cell-is_system_default="{ row }">
          <StatusPill :variant="row.is_system_default ? 'neutral' : 'mint'">{{ row.is_system_default ? 'Bawaan' : 'Kustom' }}</StatusPill>
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

    <BaseModal :open="showForm" :title="editingRole ? 'Ubah peran' : 'Peran baru'" max-width-class="max-w-[560px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveRole">
        <BaseInput v-model="form.name" label="Nama peran" required maxlength="50" :error="formErrors.name" />
        <RoleMenuPicker v-model="form.menu_keys" :error="formErrors['menu_keys.0'] || formErrors.menu_keys" />
        <div class="mt-1 flex justify-end gap-2">
          <BaseButton type="button" variant="secondary" @click="showForm = false">Batal</BaseButton>
          <BaseButton type="submit" :loading="saving">Simpan</BaseButton>
        </div>
      </form>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      title="Hapus peran?"
      :message="`Peran '${deleteTarget?.name}' akan dihapus. Tindakan ini tidak bisa dibatalkan.`"
      confirm-label="Ya, hapus"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
