<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listProducts, getProduct, createProduct, updateProduct, deleteProduct, addVariant, updateVariant } from '../api/products';
import { listArtists } from '../api/artists';
import { listCategories } from '../api/categories';
import { exportMasterData } from '../api/masterData';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import { formatIDR, toMoneyString, parseMoney } from '../utils/money';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseDrawer from '../components/ui/BaseDrawer.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import MasterDataImportModal from '../components/masterData/MasterDataImportModal.vue';

const auth = useAuthStore();
const toast = useToastStore();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listProducts);
const search = ref('');
const artistFilter = ref('');
const categoryFilter = ref('');
const artists = ref([]);
const categories = ref([]);

const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

onMounted(async () => {
  await load();
  artists.value = (await listArtists({ per_page: 100 })).data;
  categories.value = (await listCategories({ per_page: 100 })).data;
});

function applyFilters() {
  setFilter({ artist_id: artistFilter.value || undefined, category_id: categoryFilter.value || undefined });
}

const exporting = ref(false);
const showImportModal = ref(false);

async function doExport() {
  exporting.value = true;
  try {
    await exportMasterData('products');
  } catch {
    toast.error('Gagal mengekspor data produk.');
  } finally {
    exporting.value = false;
  }
}

async function afterImport() {
  // Refresh in the background but leave the modal open — it still shows the
  // applied summary (per-sheet counts, ignored sheets), and closing it out
  // from under the user the instant the request resolves would hide the
  // one confirmation that the bulk write actually did what was previewed.
  // The user closes it themselves via "Tutup".
  // A products import can also touch artists/categories it references.
  await Promise.all([
    load(),
    listArtists({ per_page: 100 }).then((r) => (artists.value = r.data)),
    listCategories({ per_page: 100 }).then((r) => (categories.value = r.data)),
  ]);
}

const columns = [
  { key: 'code_prefix', label: 'Kode' },
  { key: 'name', label: 'Nama produk' },
  { key: 'artist_name', label: 'Artist' },
  { key: 'category_name', label: 'Kategori' },
  { key: 'is_preorder', label: 'Tipe' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: '' },
];

const emptyVariant = () => ({ id: null, variant_name: 'Standard', cost_price: '0', sell_price: '0', low_stock_alert: '', is_active: true });

const showDrawer = ref(false);
const editingProduct = ref(null);
const saving = ref(false);
const markupPercent = ref(150);
const productForm = reactive({
  artist_id: '',
  category_id: '',
  product_segment: '',
  name: '',
  description: '',
  is_preorder: false,
  preorder_eta: '',
  is_active: true,
});
const variantRows = ref([emptyVariant()]);
const formErrors = reactive({});

function deriveSegment(name) {
  const letters = (name || '').replace(/[^A-Za-z]/g, '').toUpperCase();
  return letters.slice(0, 3).padEnd(3, 'X');
}
const effectiveSegment = computed(() => (productForm.product_segment || deriveSegment(productForm.name)).toUpperCase().slice(0, 3).padEnd(3, 'X'));
const artistCode = computed(() => artists.value.find((a) => a.id === Number(productForm.artist_id))?.code ?? '···');
const categoryCode = computed(() => categories.value.find((c) => c.id === Number(productForm.category_id))?.code ?? '··');
const codePreview = computed(() => `${artistCode.value}${categoryCode.value}${effectiveSegment.value}0001`);

function openCreate() {
  editingProduct.value = null;
  Object.assign(productForm, {
    artist_id: artists.value[0]?.id ?? '',
    category_id: categories.value[0]?.id ?? '',
    product_segment: '',
    name: '',
    description: '',
    is_preorder: false,
    preorder_eta: '',
    is_active: true,
  });
  variantRows.value = [emptyVariant()];
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showDrawer.value = true;
}

async function openEdit(product) {
  const full = await getProduct(product.id);
  editingProduct.value = full;
  Object.assign(productForm, {
    artist_id: full.artist_id,
    category_id: full.category_id,
    product_segment: full.product_segment ?? '',
    name: full.name,
    description: full.description ?? '',
    is_preorder: full.is_preorder,
    preorder_eta: full.preorder_eta ?? '',
    is_active: full.is_active,
  });
  variantRows.value = full.variants.length
    ? full.variants.map((v) => ({ ...v, low_stock_alert: v.low_stock_alert ?? '' }))
    : [emptyVariant()];
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showDrawer.value = true;
}

function addVariantRow() {
  variantRows.value.push(emptyVariant());
}

function removeVariantRow(index) {
  const row = variantRows.value[index];
  if (row.id) {
    // No DELETE /variants/{id} exists — an already-persisted variant can
    // only be soft-disabled, never removed from the array server-side.
    row.is_active = false;
    return;
  }
  variantRows.value.splice(index, 1);
}

function applyMarkup(row) {
  const cost = parseMoney(row.cost_price);
  row.sell_price = Math.round(cost * (1 + markupPercent.value / 100)).toString();
}

function marginFor(row) {
  const cost = parseMoney(row.cost_price);
  const sell = parseMoney(row.sell_price);
  if (sell <= 0) return null;
  return Math.round(((sell - cost) / sell) * 100);
}

async function saveProduct() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  try {
    if (editingProduct.value) {
      await updateProduct(editingProduct.value.id, {
        artist_id: Number(productForm.artist_id),
        category_id: Number(productForm.category_id),
        name: productForm.name,
        description: productForm.description || null,
        is_preorder: productForm.is_preorder,
        preorder_eta: productForm.is_preorder ? productForm.preorder_eta || null : null,
        is_active: productForm.is_active,
      });
      for (const row of variantRows.value) {
        const payload = {
          variant_name: row.variant_name,
          cost_price: toMoneyString(row.cost_price),
          sell_price: toMoneyString(row.sell_price),
          low_stock_alert: row.low_stock_alert === '' ? null : Number(row.low_stock_alert),
          is_active: row.is_active,
        };
        if (row.id) await updateVariant(row.id, payload);
        else await addVariant(editingProduct.value.id, payload);
      }
      toast.success('Produk diperbarui.');
    } else {
      await createProduct({
        artist_id: Number(productForm.artist_id),
        category_id: Number(productForm.category_id),
        product_segment: productForm.product_segment || null,
        name: productForm.name,
        description: productForm.description || null,
        is_preorder: productForm.is_preorder,
        preorder_eta: productForm.is_preorder ? productForm.preorder_eta || null : null,
        is_active: productForm.is_active,
        variants: variantRows.value.map((row) => ({
          variant_name: row.variant_name,
          cost_price: toMoneyString(row.cost_price),
          sell_price: toMoneyString(row.sell_price),
          low_stock_alert: row.low_stock_alert === '' ? null : Number(row.low_stock_alert),
        })),
      });
      toast.success('Produk dibuat.');
    }
    showDrawer.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function confirmDelete(product) {
  deleteTarget.value = product;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteProduct(deleteTarget.value.id);
    toast.success('Produk dinonaktifkan.');
    showDelete.value = false;
    await load();
  } catch {
    // 409 (masih ada varian aktif) sudah ditoast global.
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
        <label class="sr-only" for="product-search">Cari produk</label>
        <input
          id="product-search"
          v-model="search"
          placeholder="Cari nama produk atau SKU…"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <BaseSelect class="w-48" v-model="artistFilter" placeholder="Semua artist" :options="artists.map((a) => ({ value: a.id, label: a.name }))" @update:model-value="applyFilters" />
      <BaseSelect class="w-48" v-model="categoryFilter" placeholder="Semua kategori" :options="categories.map((c) => ({ value: c.id, label: c.name }))" @update:model-value="applyFilters" />
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
          Produk baru
        </BaseButton>
      </template>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" empty-message="Belum ada produk.">
        <template #cell-code_prefix="{ row }"><span class="font-mono text-[12px] font-bold text-brand-active">{{ row.code_prefix }}</span></template>
        <template #cell-is_preorder="{ row }">
          <StatusPill :variant="row.is_preorder ? 'warn' : 'neutral'">{{ row.is_preorder ? 'Pre-order' : 'Ready stock' }}</StatusPill>
        </template>
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

    <BaseDrawer
      :open="showDrawer"
      :title="editingProduct ? editingProduct.name : 'Produk baru'"
      subtitle="Kode produk digenerate server · POST /products"
      @close="showDrawer = false"
    >
      <div class="flex flex-col gap-[18px]">
        <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
          <span class="text-[14.5px] font-bold">Identitas produk</span>
          <div class="grid grid-cols-2 gap-3.5">
            <BaseSelect v-model="productForm.artist_id" label="Artist pemilik" required :options="artists.map((a) => ({ value: a.id, label: a.name }))" :error="formErrors.artist_id" />
            <BaseSelect v-model="productForm.category_id" label="Kategori" required :options="categories.map((c) => ({ value: c.id, label: c.name }))" :error="formErrors.category_id" />
          </div>
          <div class="grid grid-cols-[2fr_1fr] gap-3.5">
            <BaseInput v-model="productForm.name" label="Nama produk" required maxlength="150" :error="formErrors.name" />
            <BaseInput v-model="productForm.product_segment" label="Segmen (3)" maxlength="3" placeholder="Auto" :error="formErrors.product_segment" />
          </div>
          <BaseTextarea v-model="productForm.description" label="Deskripsi" :rows="2" />
          <div class="flex items-center gap-5">
            <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
              <input v-model="productForm.is_preorder" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
              Produk pre-order
            </label>
            <BaseInput v-if="productForm.is_preorder" v-model="productForm.preorder_eta" type="date" label="Estimasi tiba" class="flex-1" />
          </div>
        </div>

        <div class="flex flex-col gap-3.5 rounded-card bg-ink p-5">
          <span class="text-[10.5px] font-bold uppercase tracking-[0.14em] text-dark-muted-2">Kode produk otomatis · pratinjau</span>
          <div class="flex flex-wrap items-end gap-2.5">
            <div class="flex flex-col items-center gap-1.5"><span class="font-mono text-[26px] font-bold tracking-wide text-white">{{ artistCode }}</span><span class="text-[10px] text-dark-muted-2">artist · 3</span></div>
            <div class="flex flex-col items-center gap-1.5"><span class="font-mono text-[26px] font-bold tracking-wide text-mint-accent">{{ categoryCode }}</span><span class="text-[10px] text-dark-muted-2">kategori · 2</span></div>
            <div class="flex flex-col items-center gap-1.5"><span class="font-mono text-[26px] font-bold tracking-wide text-white">{{ effectiveSegment }}</span><span class="text-[10px] text-dark-muted-2">produk · 3</span></div>
            <div class="flex flex-1 flex-col items-end gap-1.5">
              <span class="rounded-lg border border-code-chip-border bg-code-chip px-3 py-1.5 font-mono text-[15px] font-bold text-white">{{ codePreview }}</span>
              <span class="text-[10.5px] text-dark-muted-2">pratinjau — urutan asli dari server</span>
            </div>
          </div>
        </div>

        <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="text-[14.5px] font-bold">Varian &amp; harga</span>
            <label class="flex items-center gap-2 text-[12.5px] text-muted">
              Markup
              <input v-model.number="markupPercent" type="number" class="w-[70px] rounded-md border border-line px-2.5 py-1.5 text-right text-[13px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100" />
              %
            </label>
          </div>

          <div v-for="(row, idx) in variantRows" :key="idx" class="flex flex-col gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3.5" :class="{ 'opacity-50': row.id && !row.is_active }">
            <div class="flex items-center gap-2.5">
              <span v-if="row.sku" class="rounded-md bg-mint-100 px-2.5 py-1 font-mono text-[12px] font-semibold text-brand-active">{{ row.sku }}</span>
              <span v-if="marginFor(row) !== null" class="rounded-md px-2 py-1 text-[11px] font-bold" :class="marginFor(row) >= 0 ? 'bg-mint-100 text-brand-active' : 'bg-danger-bg text-danger-text'">
                margin {{ marginFor(row) }}%
              </span>
              <span class="flex-1"></span>
              <button type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="`Hapus varian ${row.variant_name}`" @click="removeVariantRow(idx)">
                <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
              </button>
            </div>
            <div class="grid grid-cols-[1.4fr_1fr_1fr_auto] items-end gap-2.5">
              <BaseInput v-model="row.variant_name" label="Nama varian" />
              <BaseInput v-model="row.cost_price" type="number" min="0" label="Harga modal" />
              <BaseInput v-model="row.sell_price" type="number" min="0" label="Harga jual" />
              <BaseButton variant="secondary" size="sm" @click="applyMarkup(row)">Terapkan markup</BaseButton>
            </div>
          </div>
          <button type="button" class="flex h-11 items-center justify-center gap-2 rounded-lg border border-dashed border-disabled-2 text-[13.5px] font-bold text-muted-5 hover:border-brand hover:text-brand-active" @click="addVariantRow">
            <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
            Tambah varian
          </button>
        </div>
      </div>

      <template #footer>
        <BaseButton variant="secondary" @click="showDrawer = false">Batal</BaseButton>
        <BaseButton :loading="saving" @click="saveProduct">Simpan produk</BaseButton>
      </template>
    </BaseDrawer>

    <ConfirmDialog
      :open="showDelete"
      title="Nonaktifkan produk"
      :message="`Nonaktifkan ${deleteTarget?.name}? Ditolak bila masih ada varian aktif.`"
      confirm-label="Ya, nonaktifkan"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />

    <MasterDataImportModal :open="showImportModal" @close="showImportModal = false" @imported="afterImport" />
  </div>
</template>
