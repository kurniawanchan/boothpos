<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listCategories, createCategory, updateCategory, deleteCategory } from '../api/categories';
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

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listCategories);
const allCategories = ref([]); // for the parent picker + hierarchy column, unpaginated
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

onMounted(async () => {
  await load();
  const res = await listCategories({ per_page: 100 });
  allCategories.value = res.data;
});

const nameById = computed(() => Object.fromEntries(allCategories.value.map((c) => [c.id, c.name])));

const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama' },
  { key: 'parent_id', label: 'Induk' },
  { key: 'product_count', label: 'Produk' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: '' },
];

const showForm = ref(false);
const editingCategory = ref(null);
const form = reactive({ code: '', name: '', parent_id: '', display_order: 0, is_active: true });
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

const parentOptions = computed(() =>
  allCategories.value.filter((c) => c.id !== editingCategory.value?.id).map((c) => ({ value: c.id, label: c.name }))
);

function openCreate() {
  editingCategory.value = null;
  Object.assign(form, { code: '', name: '', parent_id: '', display_order: 0, is_active: true });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(category) {
  editingCategory.value = category;
  Object.assign(form, {
    code: category.code,
    name: category.name,
    parent_id: category.parent_id ?? '',
    display_order: category.display_order,
    is_active: category.is_active,
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveCategory() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  try {
    if (editingCategory.value) {
      await updateCategory(editingCategory.value.id, {
        name: form.name,
        parent_id: form.parent_id || null,
        display_order: Number(form.display_order) || 0,
        is_active: form.is_active,
      });
      toast.success('Kategori diperbarui.');
    } else {
      await createCategory({
        code: form.code.toUpperCase(),
        name: form.name,
        parent_id: form.parent_id || null,
        display_order: Number(form.display_order) || 0,
        is_active: form.is_active,
      });
      toast.success('Kategori dibuat.');
    }
    showForm.value = false;
    await load();
    allCategories.value = (await listCategories({ per_page: 100 })).data;
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

function confirmDelete(category) {
  deleteTarget.value = category;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteCategory(deleteTarget.value.id);
    toast.success('Kategori dinonaktifkan.');
    showDelete.value = false;
    await load();
  } catch {
    // 409 (masih ada sub-kategori/produk aktif) sudah ditoast global.
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
        <label class="sr-only" for="category-search">Cari kategori</label>
        <input
          id="category-search"
          v-model="search"
          placeholder="Cari nama kategori…"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <BaseButton v-if="auth.canManageMasterData" @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        Kategori baru
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" empty-message="Belum ada kategori.">
        <template #cell-code="{ row }"><span class="font-mono text-[12.5px] font-bold text-brand-active">{{ row.code }}</span></template>
        <template #cell-parent_id="{ row }">{{ row.parent_id ? nameById[row.parent_id] ?? `#${row.parent_id}` : '—' }}</template>
        <template #cell-product_count="{ row }">{{ row.product_count ?? 0 }}</template>
        <template #cell-is_active="{ row }">
          <StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div v-if="auth.canManageMasterData" class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">Edit</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text" @click="confirmDelete(row)">Hapus</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showForm" :title="editingCategory ? 'Ubah kategori' : 'Kategori baru'" max-width-class="max-w-[460px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveCategory">
        <BaseInput
          v-model="form.code"
          label="Kode (2 huruf)"
          maxlength="2"
          required
          :disabled="!!editingCategory"
          hint="Permanen setelah dibuat."
          :error="formErrors.code"
        />
        <BaseInput v-model="form.name" label="Nama kategori" required maxlength="100" :error="formErrors.name" />
        <BaseSelect v-model="form.parent_id" label="Kategori induk" placeholder="Tidak ada (kategori utama)" :options="parentOptions" :error="formErrors.parent_id" />
        <BaseInput v-model="form.display_order" type="number" label="Urutan tampil" :error="formErrors.display_order" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          Aktif
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">Batal</BaseButton>
          <BaseButton :loading="saving" @click="saveCategory">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      title="Nonaktifkan kategori"
      :message="`Nonaktifkan ${deleteTarget?.name}? Ditolak bila masih ada sub-kategori atau produk aktif.`"
      confirm-label="Ya, nonaktifkan"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
