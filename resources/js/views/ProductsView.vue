<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listProducts, getProduct, createProduct, updateProduct, deleteProduct, addVariant, updateVariant, uploadProductImage } from '../api/products';
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
import ProductDetailModal from '../components/product/ProductDetailModal.vue';

const auth = useAuthStore();
const { t } = useI18n();
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
const showDetail = ref(false);
const detailProductId = ref(null);

function openDetail(product) {
  detailProductId.value = product.id;
  showDetail.value = true;
}

// Client-side file guard, same pattern used by MasterDataImportModal's
// .xlsx check — fail fast before a round trip. ASSUMPTION: 5 MB cap and
// image/* mime, since the backend contract doesn't specify a limit here.
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const productImageFile = ref(null);
const productImageError = ref('');
const productImageInputEl = ref(null);
const uploadingProductImage = ref(false);

function onProductImageChange(e) {
  const file = e.target.files?.[0] ?? null;
  productImageError.value = '';
  productImageFile.value = null;
  if (!file) return;
  if (!file.type.startsWith('image/')) {
    productImageError.value = t('master_data.image_must_be_image_generic');
    e.target.value = '';
    return;
  }
  if (file.size > MAX_IMAGE_BYTES) {
    productImageError.value = t('master_data.image_max_size_generic');
    e.target.value = '';
    return;
  }
  productImageFile.value = file;
}

async function doExport() {
  exporting.value = true;
  try {
    await exportMasterData('products');
  } catch {
    toast.error(t('master_data.export_product_failed'));
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

const columns = computed(() => [
  { key: 'image_url', label: '' },
  { key: 'code_prefix', label: t('master_data.col_code') },
  { key: 'name', label: t('master_data.col_product_name') },
  { key: 'artist_name', label: t('master_data.col_artist') },
  { key: 'category_name', label: t('master_data.col_category') },
  { key: 'is_preorder', label: t('master_data.col_type') },
  { key: 'is_active', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

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
  productImageFile.value = null;
  productImageError.value = '';
  if (productImageInputEl.value) productImageInputEl.value.value = '';
  showDrawer.value = true;
}

async function openEdit(product) {
  const full = await getProduct(product.id);
  editingProduct.value = full;
  productImageFile.value = null;
  productImageError.value = '';
  if (productImageInputEl.value) productImageInputEl.value.value = '';
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
  let productId = editingProduct.value?.id ?? null;
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
      toast.success(t('master_data.product_updated'));
    } else {
      const created = await createProduct({
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
      productId = created.id;
      toast.success(t('master_data.product_created'));
    }
    if (productImageFile.value && productId) {
      uploadingProductImage.value = true;
      try {
        await uploadProductImage(productId, productImageFile.value);
      } catch {
        toast.error(t('master_data.product_saved_image_failed'));
      } finally {
        uploadingProductImage.value = false;
      }
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
    toast.success(t('master_data.product_deactivated'));
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
        <label class="sr-only" for="product-search">{{ t('master_data.search_product') }}</label>
        <input
          id="product-search"
          v-model="search"
          :placeholder="t('master_data.search_product_placeholder')"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <template v-if="auth.canAccessMenu('products')">
        <BaseButton variant="secondary" :loading="exporting" @click="doExport">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          {{ t('common.export_xlsx') }}
        </BaseButton>
        <BaseButton variant="secondary" @click="showImportModal = true">
          <i class="ph-duotone ph-file-arrow-up text-[16px]" aria-hidden="true"></i>
          {{ t('common.bulk_import') }}
        </BaseButton>
        <BaseButton @click="openCreate">
          <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
          {{ t('master_data.new_product') }}
        </BaseButton>
      </template>
    </div>

    <!-- 004-sidebar-menu-reorg (US5, FR-007) — chip filters replacing the
         previous BaseSelect dropdowns, mirroring PosView.vue's existing
         category-chip pattern exactly (see contracts/ui-contract.md). -->
    <div class="flex flex-wrap gap-1.5">
      <button
        type="button"
        class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
        :class="!artistFilter ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
        @click="artistFilter = ''; applyFilters()"
      >
        {{ t('master_data.all_artists') }}
      </button>
      <button
        v-for="a in artists"
        :key="a.id"
        type="button"
        class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
        :class="artistFilter === a.id ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
        @click="artistFilter = a.id; applyFilters()"
      >
        {{ a.name }}
      </button>
    </div>
    <div class="flex flex-wrap gap-1.5">
      <button
        type="button"
        class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
        :class="!categoryFilter ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
        @click="categoryFilter = ''; applyFilters()"
      >
        {{ t('master_data.all_categories') }}
      </button>
      <button
        v-for="c in categories"
        :key="c.id"
        type="button"
        class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
        :class="categoryFilter === c.id ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
        @click="categoryFilter = c.id; applyFilters()"
      >
        {{ c.name }}
      </button>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('master_data.no_products')">
        <template #cell-image_url="{ row }">
          <img v-if="row.image_url" :src="row.image_url" :alt="row.name" class="h-9 w-9 rounded-md border border-line-2 object-cover" />
          <div v-else class="flex h-9 w-9 items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3">
            <i class="ph-duotone ph-image text-[16px]" aria-hidden="true"></i>
          </div>
        </template>
        <template #cell-code_prefix="{ row }"><span class="font-mono text-[12px] font-bold text-brand-active">{{ row.code_prefix }}</span></template>
        <template #cell-is_preorder="{ row }">
          <StatusPill :variant="row.is_preorder ? 'warn' : 'neutral'">{{ row.is_preorder ? t('master_data.preorder') : t('master_data.ready_stock') }}</StatusPill>
        </template>
        <template #cell-is_active="{ row }">
          <StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openDetail(row)">{{ t('master_data.detail') }}</button>
            <template v-if="auth.canAccessMenu('products')">
              <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
              <button type="button" class="text-[12.5px] font-semibold text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
            </template>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseDrawer
      :open="showDrawer"
      :title="editingProduct ? editingProduct.name : t('master_data.new_product')"
      :subtitle="t('master_data.code_generated_by_server')"
      @close="showDrawer = false"
    >
      <div class="flex flex-col gap-[18px]">
        <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
          <span class="text-[14.5px] font-bold">{{ t('master_data.product_identity') }}</span>
          <div class="grid grid-cols-2 gap-3.5">
            <BaseSelect v-model="productForm.artist_id" :label="t('master_data.owner_artist')" required :options="artists.map((a) => ({ value: a.id, label: a.name }))" :error="formErrors.artist_id" />
            <BaseSelect v-model="productForm.category_id" :label="t('master_data.category')" required :options="categories.map((c) => ({ value: c.id, label: c.name }))" :error="formErrors.category_id" />
          </div>
          <div class="grid grid-cols-[2fr_1fr] gap-3.5">
            <BaseInput v-model="productForm.name" :label="t('master_data.product_name')" required maxlength="150" :error="formErrors.name" />
            <BaseInput v-model="productForm.product_segment" :label="t('master_data.segment_3')" maxlength="3" :placeholder="t('master_data.auto')" :error="formErrors.product_segment" />
          </div>
          <BaseTextarea v-model="productForm.description" :label="t('master_data.description')" :rows="2" />
          <div class="flex items-center gap-5">
            <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
              <input v-model="productForm.is_preorder" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
              {{ t('master_data.preorder_product') }}
            </label>
            <BaseInput v-if="productForm.is_preorder" v-model="productForm.preorder_eta" type="date" :label="t('master_data.eta')" class="flex-1" />
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="text-[12.5px] font-semibold text-muted-4" for="product-image">{{ t('master_data.product_image') }}</label>
            <div class="flex items-center gap-3">
              <img
                v-if="editingProduct?.image_url && !productImageFile"
                :src="editingProduct.image_url"
                :alt="t('master_data.current_product_image')"
                class="h-16 w-16 flex-none rounded-lg border border-line-2 object-cover"
              />
              <input
                id="product-image"
                ref="productImageInputEl"
                type="file"
                accept="image/*"
                class="flex-1 rounded-lg border border-line bg-white px-3.5 py-2.5 text-[13px] file:mr-3 file:rounded-md file:border-0 file:bg-mint-100 file:px-3 file:py-1.5 file:text-[12.5px] file:font-bold file:text-brand-active"
                @change="onProductImageChange"
              />
            </div>
            <p v-if="productImageError" class="text-[12px] font-semibold text-danger-text">{{ productImageError }}</p>
          </div>
        </div>

        <div class="flex flex-col gap-3.5 rounded-card bg-ink p-5">
          <span class="text-[10.5px] font-bold uppercase tracking-[0.14em] text-dark-muted-2">{{ t('master_data.auto_code_preview') }}</span>
          <div class="flex flex-wrap items-end gap-2.5">
            <div class="flex flex-col items-center gap-1.5"><span class="font-mono text-[26px] font-bold tracking-wide text-white">{{ artistCode }}</span><span class="text-[10px] text-dark-muted-2">{{ t('master_data.artist_label') }}</span></div>
            <div class="flex flex-col items-center gap-1.5"><span class="font-mono text-[26px] font-bold tracking-wide text-mint-accent">{{ categoryCode }}</span><span class="text-[10px] text-dark-muted-2">{{ t('master_data.category_label') }}</span></div>
            <div class="flex flex-col items-center gap-1.5"><span class="font-mono text-[26px] font-bold tracking-wide text-white">{{ effectiveSegment }}</span><span class="text-[10px] text-dark-muted-2">{{ t('master_data.product_label') }}</span></div>
            <div class="flex flex-1 flex-col items-end gap-1.5">
              <span class="rounded-lg border border-code-chip-border bg-code-chip px-3 py-1.5 font-mono text-[15px] font-bold text-white">{{ codePreview }}</span>
              <span class="text-[10.5px] text-dark-muted-2">{{ t('master_data.preview_note') }}</span>
            </div>
          </div>
        </div>

        <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="text-[14.5px] font-bold">{{ t('master_data.variants_and_prices') }}</span>
            <label class="flex items-center gap-2 text-[12.5px] text-muted">
              {{ t('master_data.markup') }}
              <input v-model.number="markupPercent" type="number" class="w-[70px] rounded-md border border-line px-2.5 py-1.5 text-right text-[13px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100" />
              %
            </label>
          </div>

          <div v-for="(row, idx) in variantRows" :key="idx" class="flex flex-col gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3.5" :class="{ 'opacity-50': row.id && !row.is_active }">
            <div class="flex items-center gap-2.5">
              <span v-if="row.sku" class="rounded-md bg-mint-100 px-2.5 py-1 font-mono text-[12px] font-semibold text-brand-active">{{ row.sku }}</span>
              <span v-if="marginFor(row) !== null" class="rounded-md px-2 py-1 text-[11px] font-bold" :class="marginFor(row) >= 0 ? 'bg-mint-100 text-brand-active' : 'bg-danger-bg text-danger-text'">
                {{ t('master_data.margin', { value: marginFor(row) }) }}
              </span>
              <span class="flex-1"></span>
              <button type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('master_data.delete_variant', { name: row.variant_name })" @click="removeVariantRow(idx)">
                <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
              </button>
            </div>
            <div class="grid grid-cols-[1.4fr_1fr_1fr_auto] items-end gap-2.5">
              <BaseInput v-model="row.variant_name" :label="t('master_data.variant_name')" />
              <BaseInput v-model="row.cost_price" type="number" min="0" :label="t('master_data.cost_price')" />
              <BaseInput v-model="row.sell_price" type="number" min="0" :label="t('master_data.sell_price')" />
              <BaseButton variant="secondary" size="sm" @click="applyMarkup(row)">{{ t('master_data.apply_markup') }}</BaseButton>
            </div>
          </div>
          <button type="button" class="flex h-11 items-center justify-center gap-2 rounded-lg border border-dashed border-disabled-2 text-[13.5px] font-bold text-muted-5 hover:border-brand hover:text-brand-active" @click="addVariantRow">
            <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
            {{ t('master_data.add_variant') }}
          </button>
        </div>
      </div>

      <template #footer>
        <BaseButton variant="secondary" @click="showDrawer = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="saving" @click="saveProduct">{{ t('master_data.save_product') }}</BaseButton>
      </template>
    </BaseDrawer>

    <ConfirmDialog
      :open="showDelete"
      :title="t('master_data.deactivate_product')"
      :message="t('master_data.deactivate_product_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('master_data.yes_deactivate')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />

    <MasterDataImportModal :open="showImportModal" @close="showImportModal = false" @imported="afterImport" />
    <ProductDetailModal :open="showDetail" :product-id="detailProductId" @close="showDetail = false" />
  </div>
</template>
