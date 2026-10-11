<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listProducts, deleteProduct } from '../api/products';
import { listArtists } from '../api/artists';
import { listCategories } from '../api/categories';
import { exportMasterData } from '../api/masterData';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import PageHeader from '../components/page/PageHeader.vue';
import FilterBar from '../components/page/FilterBar.vue';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseMultiSelect from '../components/ui/BaseMultiSelect.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import ImageLightbox from '../components/ui/ImageLightbox.vue';
import MasterDataImportModal from '../components/masterData/MasterDataImportModal.vue';
import ProductDetailModal from '../components/product/ProductDetailModal.vue';
import VariantDetailModal from '../components/product/VariantDetailModal.vue';
import ProductCodeCell from './products/ProductCodeCell.vue';
import ProductSkuCell from './products/ProductSkuCell.vue';
import ProductFormDrawer from './products/ProductFormDrawer.vue';

/**
 * Daftar produk (041, US2): PageHeader + FilterBar + tabel. Sengaja TANPA strip angka: daftar ini berpaginasi
 * dan tidak ada empat angka jujur yang sudah dimuat (tidak ada endpoint ringkasan produk; konstitusi: daftar
 * tetap ramping, tanpa panggilan API baru). Formulir tambah/ubah ada di products/ProductFormDrawer.vue.
 */
const auth = useAuthStore();
const { t } = useI18n();
const toast = useToastStore();

// 024-invoice-layout-shipping-slip — `with_variants=1` diminta khusus untuk kolom SKU di tabel ini
// (CLAUDE.md: endpoint ini SENGAJA tidak menyertakan variants secara default; layar INI membalik keputusan
// itu atas permintaan pengguna, `?with_variants=1` sudah ada dan dipakai POS, bukan endpoint baru).
const { items, meta, loading, error, load, setPage, setFilter } = usePaginatedList(listProducts, { with_variants: 1 });
const search = ref('');
// 005-ux-enhancements-dashboard (US1) — filter artist/category berupa array (multi-select dengan pencarian).
// Array kosong = "Semua".
const artistFilter = ref([]);
const categoryFilter = ref([]);
const artists = ref([]);
const categories = ref([]);

const artistOptions = computed(() => artists.value.map((a) => ({ value: a.id, label: a.name })));
const categoryOptions = computed(() => categories.value.map((c) => ({ value: c.id, label: c.name })));

const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);
function onSearchInput(value) {
  search.value = value;
  debouncedSearch();
}

onMounted(async () => {
  await load();
  artists.value = (await listArtists({ per_page: 100 })).data;
  categories.value = (await listCategories({ per_page: 100 })).data;
});

function applyFilters() {
  setFilter({
    artist_id: artistFilter.value.length ? artistFilter.value : undefined,
    category_id: categoryFilter.value.length ? categoryFilter.value : undefined,
  });
}

const filtersActive = computed(() => !!search.value || artistFilter.value.length > 0 || categoryFilter.value.length > 0);
function resetFilters() {
  search.value = '';
  artistFilter.value = [];
  categoryFilter.value = [];
  setFilter({ search: undefined, artist_id: undefined, category_id: undefined });
}

const exporting = ref(false);
const showImportModal = ref(false);
const showDetail = ref(false);
const detailProductId = ref(null);
const drawer = ref(null);

function openDetail(product) {
  detailProductId.value = product.id;
  showDetail.value = true;
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
  // Segarkan di latar tetapi biarkan modal terbuka — modal masih menampilkan ringkasan yang diterapkan
  // (jumlah per sheet, sheet yang diabaikan); menutupnya begitu request selesai akan menyembunyikan satu-satunya
  // konfirmasi bahwa penulisan massal benar-benar sesuai pratinjau. Pengguna menutupnya sendiri lewat "Tutup".
  // Impor produk juga bisa menyentuh artist/kategori yang dirujuknya.
  await Promise.all([
    load(),
    listArtists({ per_page: 100 }).then((r) => (artists.value = r.data)),
    listCategories({ per_page: 100 }).then((r) => (categories.value = r.data)),
  ]);
}

const columns = computed(() => [
  { key: 'code_prefix', label: t('master_data.col_code') },
  { key: 'sku', label: t('master_data.col_sku') },
  { key: 'name', label: t('master_data.col_product_name') },
  { key: 'artist_name', label: t('master_data.col_artist') },
  { key: 'category_name', label: t('master_data.col_category') },
  { key: 'is_preorder', label: t('master_data.col_type') },
  { key: 'is_active', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

// Klik SKU tunggal membuka detail varian itu — terpisah dari "Detail" (seluruh produk, semua varian).
const showVariantDetail = ref(false);
const detailVariant = ref(null);
const detailVariantProduct = ref(null);
function openVariantDetail(row, variant) {
  detailVariant.value = variant;
  detailVariantProduct.value = row;
  showVariantDetail.value = true;
}

// Klik thumbnail produk membuka ImageLightbox yang sama dengan layar lain (invoice, QR pembayaran).
const lightboxSrc = ref(null);
const lightboxAlt = ref('');
function openImageLightbox(row) {
  if (!row.image_url) return;
  lightboxSrc.value = row.image_url;
  lightboxAlt.value = row.name;
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
  <div>
    <PageHeader :title="t('nav.products')" :description="t('nav.products_subtitle')">
      <template v-if="auth.canAccessMenu('products')" #actions>
        <BaseButton variant="secondary" :loading="exporting" @click="doExport">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          {{ t('common.export_xlsx') }}
        </BaseButton>
        <BaseButton variant="secondary" @click="showImportModal = true">
          <i class="ph-duotone ph-file-arrow-up text-[16px]" aria-hidden="true"></i>
          {{ t('common.bulk_import') }}
        </BaseButton>
        <BaseButton @click="drawer.openCreate()">
          <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
          {{ t('master_data.new_product') }}
        </BaseButton>
      </template>
    </PageHeader>

    <div class="mx-[26px] mb-10 overflow-hidden border-2 border-line bg-white">
      <!-- 005-ux-enhancements-dashboard (US1) — dropdown multi-pilih dengan pencarian, menggantikan chip
           filter tunggal dari 004-sidebar-menu-reorg (lihat research.md R2). -->
      <FilterBar
        :search="search"
        :placeholder="t('master_data.search_product_placeholder')"
        :search-label="t('master_data.search_product')"
        :count="loading && !items.length ? null : meta.total"
        :active="filtersActive"
        @update:search="onSearchInput"
        @reset="resetFilters"
      >
        <BaseMultiSelect
          v-model="artistFilter"
          :options="artistOptions"
          :all-label="t('master_data.all_artists')"
          class="w-56"
          @update:model-value="applyFilters"
        />
        <BaseMultiSelect
          v-model="categoryFilter"
          :options="categoryOptions"
          :all-label="t('master_data.all_categories')"
          class="w-56"
          @update:model-value="applyFilters"
        />
      </FilterBar>

      <DataTable
        :columns="columns"
        :rows="items"
        :loading="loading"
        :error="error ? t('page.load_failed') : ''"
        :empty-message="t('master_data.no_products')"
        @retry="load"
      >
        <template #cell-code_prefix="{ row }"><ProductCodeCell :product="row" @enlarge="openImageLightbox" /></template>
        <template #cell-sku="{ row }"><ProductSkuCell :product="row" @open-variant="openVariantDetail" /></template>
        <template #cell-is_preorder="{ row }">
          <StatusPill :variant="row.is_preorder ? 'warning' : 'neutral'">{{ row.is_preorder ? t('master_data.preorder') : t('master_data.ready_stock') }}</StatusPill>
        </template>
        <template #cell-is_active="{ row }">
          <StatusPill :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openDetail(row)">{{ t('master_data.detail') }}</button>
            <template v-if="auth.canAccessMenu('products')">
              <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="drawer.openEdit(row)">{{ t('common.edit') }}</button>
              <button type="button" class="text-[12.5px] font-semibold text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
            </template>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <ProductFormDrawer ref="drawer" :artists="artists" :categories="categories" @saved="load" />

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
    <VariantDetailModal
      :open="showVariantDetail"
      :variant="detailVariant"
      :product-name="detailVariantProduct?.name"
      :artist-name="detailVariantProduct?.artist_name"
      :category-name="detailVariantProduct?.category_name"
      @close="showVariantDetail = false"
    />
    <ImageLightbox :open="!!lightboxSrc" :src="lightboxSrc" :alt="lightboxAlt" @close="lightboxSrc = null" />
  </div>
</template>
