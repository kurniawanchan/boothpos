<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listCategories, createCategory, updateCategory, deleteCategory, uploadCategoryImage } from '../api/categories';
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
import BaseSelect from '../components/ui/BaseSelect.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import MasterDataImportModal from '../components/masterData/MasterDataImportModal.vue';

const auth = useAuthStore();
const toast = useToastStore();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listCategories);
const allCategories = ref([]); // for the parent picker + hierarchy column, unpaginated
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

async function loadAllCategories() {
  allCategories.value = (await listCategories({ per_page: 100 })).data;
}

onMounted(async () => {
  await load();
  await loadAllCategories();
});

const exporting = ref(false);
const showImportModal = ref(false);

async function doExport() {
  exporting.value = true;
  try {
    await exportMasterData('categories');
  } catch {
    toast.error('Gagal mengekspor data kategori.');
  } finally {
    exporting.value = false;
  }
}

async function afterImport() {
  // Refresh in the background but leave the modal open so the user still
  // sees the applied summary — they close it themselves via "Tutup".
  await Promise.all([load(), loadAllCategories()]);
}

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

// Client-side file guard, same pattern used by MasterDataImportModal's
// .xlsx check — fail fast before a round trip. ASSUMPTION: 5 MB cap and
// image/* mime, since the backend contract doesn't specify a limit here.
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const categoryImageFile = ref(null);
const categoryImageError = ref('');
const categoryImageInputEl = ref(null);

function onCategoryImageChange(e) {
  const file = e.target.files?.[0] ?? null;
  categoryImageError.value = '';
  categoryImageFile.value = null;
  if (!file) return;
  if (!file.type.startsWith('image/')) {
    categoryImageError.value = 'Berkas harus berupa gambar.';
    e.target.value = '';
    return;
  }
  if (file.size > MAX_IMAGE_BYTES) {
    categoryImageError.value = 'Ukuran gambar maksimal 5 MB.';
    e.target.value = '';
    return;
  }
  categoryImageFile.value = file;
}

const parentOptions = computed(() =>
  allCategories.value.filter((c) => c.id !== editingCategory.value?.id).map((c) => ({ value: c.id, label: c.name }))
);

function resetCategoryImage() {
  categoryImageFile.value = null;
  categoryImageError.value = '';
  if (categoryImageInputEl.value) categoryImageInputEl.value.value = '';
}

function openCreate() {
  editingCategory.value = null;
  Object.assign(form, { code: '', name: '', parent_id: '', display_order: 0, is_active: true });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  resetCategoryImage();
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
  resetCategoryImage();
  showForm.value = true;
}

async function saveCategory() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  let categoryId = editingCategory.value?.id ?? null;
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
      const created = await createCategory({
        code: form.code.toUpperCase(),
        name: form.name,
        parent_id: form.parent_id || null,
        display_order: Number(form.display_order) || 0,
        is_active: form.is_active,
      });
      categoryId = created.id;
      toast.success('Kategori dibuat.');
    }
    if (categoryImageFile.value && categoryId) {
      try {
        await uploadCategoryImage(categoryId, categoryImageFile.value);
      } catch {
        toast.error('Kategori tersimpan, tapi gagal mengunggah gambar.');
      }
    }
    showForm.value = false;
    await load();
    await loadAllCategories();
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
      <template v-if="auth.canManageMasterData">
        <BaseButton variant="secondary" :loading="exporting" @click="doExport">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          Ekspor .xlsx
        </BaseButton>
        <BaseButton variant="secondary" @click="showImportModal = true">
          <i class="ph-duotone ph-file-arrow-up text-[16px]" aria-hidden="true"></i>
          Impor massal
        </BaseButton>
        <BaseButton @click="openCreate">
          <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
          Kategori baru
        </BaseButton>
      </template>
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
        <div class="flex flex-col gap-1.5">
          <label class="text-[12.5px] font-semibold text-muted-4" for="category-image">Gambar kategori</label>
          <div class="flex items-center gap-3">
            <img
              v-if="editingCategory?.image_url && !categoryImageFile"
              :src="editingCategory.image_url"
              alt="Gambar kategori saat ini"
              class="h-14 w-14 flex-none rounded-lg border border-line-2 object-cover"
            />
            <input
              id="category-image"
              ref="categoryImageInputEl"
              type="file"
              accept="image/*"
              class="flex-1 rounded-lg border border-line bg-white px-3.5 py-2.5 text-[13px] file:mr-3 file:rounded-md file:border-0 file:bg-mint-100 file:px-3 file:py-1.5 file:text-[12.5px] file:font-bold file:text-brand-active"
              @change="onCategoryImageChange"
            />
          </div>
          <p v-if="categoryImageError" class="text-[12px] font-semibold text-danger-text">{{ categoryImageError }}</p>
        </div>
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

    <MasterDataImportModal :open="showImportModal" @close="showImportModal = false" @imported="afterImport" />
  </div>
</template>
