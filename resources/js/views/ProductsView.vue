<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { listProducts, productSummary } from '../api/products';
import { listOutlets } from '../api/outlets';
import { listCategories } from '../api/categories';
import { exportMasterData } from '../api/masterData';
import { useAuthStore } from '../stores/auth';
import { useSettingsStore } from '../stores/settings';
import { useOutletStore } from '../stores/outlet';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import { useQueryState } from '../composables/useQueryState';
import { readStoredView, writeStoredView, VIEW_MODES } from '../composables/useProductsViewMode';
import PageHeader from '../components/page/PageHeader.vue';
import FilterBar from '../components/page/FilterBar.vue';
import TabBar from '../components/page/TabBar.vue';
import BulkBar from '../components/page/BulkBar.vue';
import ViewToggle from '../components/page/ViewToggle.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseMultiSelect from '../components/ui/BaseMultiSelect.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingState from '../components/ui/LoadingState.vue';
import MasterDataImportModal from '../components/masterData/MasterDataImportModal.vue';
import MaterialsTable from '../components/masterData/MaterialsTable.vue';
import ModifierGroupsAdmin from '../components/restaurant/ModifierGroupsAdmin.vue';
import ProductFigures from '../components/product/ProductFigures.vue';
import ItemGrid from '../components/product/ItemGrid.vue';
import ItemTable from '../components/product/ItemTable.vue';
import ImportExportMenu from '../components/product/ImportExportMenu.vue';
import BulkPriceDrawer from '../components/product/BulkPriceDrawer.vue';
import ReleaseBanner from '../components/product/ReleaseBanner.vue';
import ReleaseDialog from '../components/product/ReleaseDialog.vue';
import ApprovalList from '../components/product/ApprovalList.vue';
import ProductFormDrawer from './products/ProductFormDrawer.vue';

/**
 * Daftar Produk (043, US1): kepala halaman + strip angka + tab jenis item + baris filter + Grid atau Tabel + pager.
 * Seluruh keadaan daftar (tab, cari, kategori, status, kualitas, tampilan, urutan, halaman) hidup di alamat URL lewat
 * useQueryState, jadi bisa dibagikan/di-refresh/di-back; nilai tak dikenal diabaikan (jatuh ke bawaan), tidak fatal.
 *
 * Alur data: tab Menu item / Item ritel memanggil GET /products (satu permintaan per halaman, tanpa permintaan per
 * kartu — FR-023); angka kepala dari GET /products/summary (terpisah, tidak bergantung filter) dan hitungan tab dari
 * `counts` pada respons daftar. Tab Bahan baku / Setengah jadi / Kemasan menanam MaterialsTable (daftar bahan yang
 * sama dengan layar Bahan Baku, difilter jenis) dan tab Modifier & combo menanam ModifierGroupsAdmin — keduanya
 * memakai rute lamanya, bukan endpoint baru. Formulir TAMBAH tetap products/ProductFormDrawer.vue (dibuka dari sini); UBAH item dilakukan di halaman item — baris Tabel dan kartu Grid
 * hanya menautkan ke sana, laci ubah tidak dibuka dari daftar.
 */
const auth = useAuthStore();
const settings = useSettingsStore();
const { t } = useI18n();
const toast = useToastStore();

const PER_PAGE = 15;
const TABS = ['menu', 'material', 'semi_finished', 'packaging', 'retail', 'modifier'];
const PRODUCT_TABS = ['menu', 'retail']; // dilayani GET /products
const MATERIAL_KIND = { material: 'ingredient', semi_finished: 'semi_finished', packaging: 'packaging' };
const STATUSES = ['draft', 'active', 'discontinued'];
const QUALITIES = ['no_station', 'no_category', 'no_price', 'no_cost'];
const SORTS = ['name', '-name', 'sku', '-sku', 'price', '-price', 'status', '-status'];

const userId = auth.user?.id ?? null;
const { state, set } = useQueryState({
  tab: 'menu',
  q: '',
  category: [],
  status: [],
  quality: '',
  availability: '',
  view: readStoredView(userId),
  sort: '',
  page: 1,
});

// Nilai dari URL disaring di sini (bukan di useQueryState): yang tak dikenal jatuh ke bawaan.
const tab = computed(() => (TABS.includes(state.tab) ? state.tab : 'menu'));
const view = computed(() => (VIEW_MODES.includes(state.view) ? state.view : 'grid'));
const statusFilter = computed(() => state.status.filter((s) => STATUSES.includes(s)));
const categoryFilter = computed(() => state.category.filter((c) => /^\d+$/.test(c)).map(Number));
const quality = computed(() => (QUALITIES.includes(state.quality) ? state.quality : ''));
const availability = computed(() => (state.availability === 'sold_out' ? 'sold_out' : ''));
const sort = computed(() => (SORTS.includes(state.sort) ? state.sort : ''));
const page = computed(() => (Number.isInteger(state.page) && state.page >= 1 ? state.page : 1));
const isProductTab = computed(() => PRODUCT_TABS.includes(tab.value));
const canManage = computed(() => auth.canAccessMenu('products'));

// --- strip angka ---------------------------------------------------------------------------------------------
const summary = ref(null);
const summaryState = ref('loading');
async function loadSummary() {
  summaryState.value = 'loading';
  try {
    summary.value = await productSummary();
    summaryState.value = 'ready';
  } catch {
    summaryState.value = 'error';
  }
}

const description = computed(() => {
  const base = t('products.page_description');
  const version = summary.value?.unreleased?.active_version;
  // Versi rilis hanya disebut bila rilis menu aktif di instalasi ini; saat mati tidak ada kata "rilis" sama sekali.
  return settings.menuReleaseEnabled && version ? `${base} · ${t('products.release_active', { version })}` : base;
});

// --- daftar ----------------------------------------------------------------------------------------------------
const items = ref([]);
const meta = ref({ current_page: 1, per_page: PER_PAGE, total: 0, last_page: 1 });
const counts = ref(null);
// Mulai `true` di tab produk supaya tidak ada kedipan "belum ada item" sebelum permintaan pertama berangkat.
const loading = ref(isProductTab.value);
const error = ref(false);
const selected = ref([]);
let requestSeq = 0;

// 045 Stage 3 — outlet terpilih di topbar menyaring daftar ke item yang DIBAWA outlet itu (memilih memuat ulang halaman).
const outletStore = useOutletStore();

function listParams() {
  return {
    outlet_id: outletStore.selected != null ? [outletStore.selected] : undefined,
    tab: tab.value,
    page: page.value,
    per_page: PER_PAGE,
    q: state.q || undefined,
    category_id: categoryFilter.value.length ? categoryFilter.value : undefined,
    status: statusFilter.value.length ? statusFilter.value : undefined,
    quality: quality.value || undefined,
    // Server menyaring item yang sedang 86 bila `availability=sold_out` (angka kepala "86 hari ini" memakainya).
    availability: availability.value || undefined,
    sort: sort.value || undefined,
  };
}

async function loadList() {
  const mine = ++requestSeq;
  // Tab non-produk hanya butuh hitungan tab (sekali): satu permintaan ringan, bukan daftar penuh.
  const params = isProductTab.value ? listParams() : { tab: 'menu', per_page: 1 };
  if (!isProductTab.value && counts.value) return;
  loading.value = isProductTab.value;
  error.value = false;
  try {
    const res = await listProducts(params);
    if (mine !== requestSeq) return; // respons permintaan yang sudah tersusul diabaikan
    if (res.counts) counts.value = res.counts;
    if (isProductTab.value) {
      items.value = res.data ?? [];
      if (res.meta) meta.value = res.meta;
      selected.value = [];
      // Halaman di URL melewati batas (mis. data berkurang) -> kembali ke halaman terakhir yang ada.
      if (res.meta && res.meta.last_page >= 1 && page.value > res.meta.last_page) set({ page: res.meta.last_page });
    }
  } catch {
    if (mine === requestSeq && isProductTab.value) error.value = true;
  } finally {
    if (mine === requestSeq) loading.value = false;
  }
}

// Berpindah tab (Menu item <-> Item ritel) mengosongkan baris lama dulu: tab lain berisi item lain, dan baris tab
// sebelumnya tidak boleh tampil di bawah judul tab baru selama permintaan berjalan.
watch(tab, () => {
  items.value = [];
  selected.value = [];
  error.value = false;
});

// Satu watcher untuk SEMUA keadaan yang mengubah permintaan (juga saat URL berubah dari luar: back/forward).
watch(() => JSON.stringify([tab.value, page.value, state.q, categoryFilter.value, statusFilter.value, quality.value, availability.value, sort.value]), loadList);

// --- referensi untuk filter dan formulir ----------------------------------------------------------------------
const outlets = ref([]);
const categories = ref([]);
const categoryOptions = computed(() => categories.value.map((c) => ({ value: c.id, label: c.name })));
const statusOptions = computed(() => STATUSES.map((s) => ({ value: s, label: t(`products.status_${s}`) })));

async function loadReferences() {
  outlets.value = (await listOutlets({ per_page: 100 })).data;
  categories.value = (await listCategories({ per_page: 100 })).data;
}

onMounted(async () => {
  loadSummary();
  await loadList();
  await loadReferences();
});

// --- cari / filter / tab / tampilan ------------------------------------------------------------------------------
const searchText = ref(state.q);
const debouncedSearch = useDebouncedFn(() => set({ q: searchText.value, page: 1 }), 300);
function onSearchInput(value) {
  searchText.value = value;
  debouncedSearch();
}
// URL berubah dari luar (back/forward, tautan) -> kotak cari ikut.
watch(() => state.q, (q) => { if (q !== searchText.value) searchText.value = q; });

const categoryModel = computed({
  get: () => categoryFilter.value,
  set: (ids) => set({ category: ids.map(String), page: 1 }),
});
const statusModel = computed({
  get: () => statusFilter.value,
  set: (values) => set({ status: values, page: 1 }),
});

const filtersActive = computed(() => !!state.q || categoryFilter.value.length > 0 || statusFilter.value.length > 0 || !!quality.value || !!availability.value);
function resetFilters() {
  searchText.value = '';
  set({ q: '', category: [], status: [], quality: '', availability: '', page: 1 });
}

function onFigureFilter(f) {
  // Angka kepala menyangkut item menu: pindah ke tab Menu item dan pasang satu filter (saling meniadakan).
  if (f.kind === 'sold_out') set({ tab: 'menu', availability: 'sold_out', quality: '', page: 1 });
  else if (f.kind === 'quality' && f.quality) set({ tab: 'menu', quality: f.quality, availability: '', page: 1 });
}

const QUALITY_LABELS = { no_station: 'products.quality_no_station', no_category: 'products.quality_no_category', no_price: 'products.quality_no_price', no_cost: 'products.quality_no_cost' };
const figureChips = computed(() => {
  const chips = [];
  if (availability.value) chips.push({ key: 'availability', label: t('products.fig_sold_out') });
  if (quality.value) chips.push({ key: 'quality', label: `${t('products.fig_quality')}: ${t(QUALITY_LABELS[quality.value])}` });
  return chips;
});
function clearChip(key) {
  set({ [key]: '', page: 1 });
}

const tabs = computed(() => TABS.map((key) => ({ key, label: t(`products.tab_${key}`), count: counts.value?.[key] })));
function changeTab(key) {
  set({ tab: key, page: 1 });
}

const viewOptions = computed(() => [
  // Tanpa ikon: desain toolbar daftar memakai saklar teks saja (Grid | Tabel).
  { value: 'grid', label: t('products.view_grid') },
  { value: 'table', label: t('products.view_table') },
]);
const viewModel = computed({
  get: () => view.value,
  set: (v) => {
    writeStoredView(userId, v);
    set({ view: v });
  },
});

const sortKey = computed(() => sort.value.replace(/^-/, '') || null);
const sortDir = computed(() => (sort.value.startsWith('-') ? 'desc' : 'asc'));
function onSort({ key, dir }) {
  set({ sort: dir === 'desc' ? `-${key}` : key, page: 1 });
}

function setPage(p) {
  set({ page: p });
}

// --- tambah --------------------------------------------------------------------------------
const drawer = ref(null);
const materialsTable = ref(null);

function addItem() {
  if (isProductTab.value) drawer.value?.openCreate({ is_retail: tab.value === 'retail' });
  else materialsTable.value?.openCreate();
}
// Tab Modifier & combo punya tombol "baru"-nya sendiri di dalam daftar grupnya; tombol kepala disembunyikan di sana.
const showAddInHeader = computed(() => tab.value !== 'modifier');

function refreshAll() {
  counts.value = null; // hitungan tab ikut disegarkan oleh loadList berikutnya
  loadSummary();
  return loadList();
}

async function afterSaved() {
  await refreshAll();
}

// --- ekspor / impor (perilaku lama, dipindah dari dua tombol ke satu menu) ---------------------------------------
const exporting = ref(false);
const showImportModal = ref(false);

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
  // Impor produk juga bisa menyentuh outlet/kategori yang dirujuknya.
  await Promise.all([refreshAll(), loadReferences()]);
  materialsTable.value?.reload();
}

// --- ubah harga massal (US5) -------------------------------------------------------------------------------------
// Izin menu.price.bulk menggerbang KEDUA pintu masuk (tombol kepala dan aksi bilah pilihan): tanpa izin kendalinya
// tidak dirender sama sekali (disembunyikan, bukan dinonaktifkan). Server tetap menolak 403.
const canBulkPrice = computed(() => auth.hasPermission('menu.price.bulk'));
const bulkOpen = ref(false);
const bulkCategoryIds = ref([]);
const bulkFromSelection = ref(false);

// Cakupan server adalah KATEGORI: dari tombol kepala ia mengikuti filter kategori yang sedang aktif, dari bilah
// pilihan ia mengikuti kategori item terpilih (drawer menyatakan bahwa seluruh item di kategori itu ikut berubah).
function openBulkPrice({ fromSelection = false } = {}) {
  const ids = fromSelection
    ? [...new Set(items.value.filter((i) => selected.value.includes(i.id)).map((i) => i.category?.id).filter((id) => id != null))]
    : [...categoryFilter.value];
  bulkCategoryIds.value = ids;
  bulkFromSelection.value = fromSelection;
  bulkOpen.value = true;
}

// --- rilis menu & persetujuan (US10) -------------------------------------------------------------------------------
const releaseOpen = ref(false);
const approvalsOpen = ref(false);

// --- aksi massal --------------------------------------------------------------------------------------------------
// Ekspor master data selalu membawa SELURUH katalog produk (server tidak menyaring per baris terpilih), jadi
// labelnya jujur menyebut itu. "Ubah harga massal" hanya muncul bagi pemegang izinnya.
const bulkActions = computed(() => {
  const actions = [{ key: 'export', label: t('products.bulk_export') }];
  if (canBulkPrice.value) actions.unshift({ key: 'price', label: t('products.bulk_open') });
  return actions;
});
function onBulkAction(key) {
  if (key === 'export') doExport();
  else if (key === 'price') openBulkPrice({ fromSelection: true });
}

const emptyMessage = computed(() => (filtersActive.value ? t('products.empty_filtered') : t('products.empty_none')));
</script>

<template>
  <div class="pb-10">
    <PageHeader :title="t('nav.products')" :description="description">
      <template v-if="canManage" #actions>
        <ImportExportMenu :exporting="exporting" @export="doExport" @import="showImportModal = true" />
        <BaseButton v-if="canBulkPrice && isProductTab" variant="secondary" @click="openBulkPrice()">
          <i class="ph-duotone ph-percent text-[16px]" aria-hidden="true"></i>
          {{ t('products.bulk_open') }}
        </BaseButton>
        <BaseButton v-if="showAddInHeader" @click="addItem">
          <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
          {{ t('products.add_item') }}
        </BaseButton>
      </template>
    </PageHeader>

    <ProductFigures :summary="summary" :state="summaryState" @retry="loadSummary" @filter="onFigureFilter" />

    <ReleaseBanner :unreleased="summary?.unreleased ?? null" @release="releaseOpen = true" @approvals="approvalsOpen = true" />

    <div class="mx-[26px] overflow-hidden border-2 border-line bg-white">
      <TabBar :tabs="tabs" :model-value="tab" :label="t('products.tabs_aria')" @update:model-value="changeTab" />

      <template v-if="isProductTab">
        <FilterBar
          :search="searchText"
          :placeholder="t('products.search_placeholder')"
          :search-label="t('products.search_label')"
          :count="loading && !items.length ? null : error ? null : meta.total"
          :active="filtersActive"
          @update:search="onSearchInput"
          @reset="resetFilters"
        >
          <BaseMultiSelect v-model="categoryModel" :options="categoryOptions" :all-label="t('products.filter_category_all')" class="w-52" />
          <BaseMultiSelect v-model="statusModel" :options="statusOptions" :all-label="t('products.filter_status_all')" class="w-44" />
          <!-- Kelompok kanan (hitungan · Grid|Tabel · + Menu item) memakai slot `trailing` FilterBar. -->
          <template #trailing>
            <ViewToggle v-model="viewModel" :options="viewOptions" :label="t('products.view_aria')" />
            <BaseButton v-if="canManage" size="xs" @click="addItem">
              <span aria-hidden="true">+</span>
              {{ tab === 'retail' ? t('products.toolbar_add_retail') : t('products.toolbar_add_menu') }}
            </BaseButton>
          </template>
        </FilterBar>

        <!-- Filter dari angka kepala (86 hari ini / Kualitas data) dibuat terlihat dan bisa dilepas satu per satu. -->
        <div v-if="figureChips.length" class="flex flex-wrap items-center gap-2 px-5 pb-3" data-testid="figure-filters">
          <button
            v-for="chip in figureChips"
            :key="chip.key"
            type="button"
            class="inline-flex items-center gap-1.5 border-2 border-brand bg-mint-50 px-2.5 py-1 text-[12px] font-bold text-brand-active"
            :aria-label="t('products.remove_filter', { name: chip.label })"
            @click="clearChip(chip.key)"
          >
            {{ chip.label }}
            <i class="ph-duotone ph-x text-[12px]" aria-hidden="true"></i>
          </button>
        </div>

        <BulkBar v-if="view === 'table'" :count="selected.length" :actions="bulkActions" @action="onBulkAction" @clear="selected = []" />

        <ErrorState v-if="error" :message="t('page.load_failed')" @retry="loadList" />
        <LoadingState v-else-if="loading && !items.length" :rows="6" />
        <EmptyState v-else-if="!items.length" icon="ph-magnifying-glass" :message="emptyMessage">
          <button v-if="filtersActive" type="button" class="text-[12.5px] font-bold text-brand-active underline decoration-dotted hover:text-brand" @click="resetFilters">{{ t('page.reset_filters') }}</button>
        </EmptyState>
        <div v-else :aria-busy="loading" :class="loading ? 'opacity-60' : ''">
          <ItemGrid v-if="view === 'grid'" :items="items" :can-edit="canManage" />
          <ItemTable
            v-else
            v-model:selected="selected"
            :items="items"
            :sort-key="sortKey"
            :sort-dir="sortDir"
            :can-edit="canManage"
            @sort="onSort"
          />
        </div>
        <TablePagination v-if="!error && meta.total > 0" :meta="meta" @change="setPage" />
      </template>

      <MaterialsTable v-else-if="MATERIAL_KIND[tab]" ref="materialsTable" bare :kind="MATERIAL_KIND[tab]" @changed="refreshAll" />

      <div v-else class="px-5 py-5">
        <ModifierGroupsAdmin />
      </div>
    </div>

    <ProductFormDrawer ref="drawer" :outlets="outlets" :categories="categories" @saved="afterSaved" />

    <BulkPriceDrawer
      :open="bulkOpen"
      :categories="categories"
      :initial-category-ids="bulkCategoryIds"
      :from-selection="bulkFromSelection"
      @close="bulkOpen = false"
      @saved="refreshAll"
    />
    <!-- Hanya dipasang saat rilis menu aktif: saat mati tidak ada kendali rilis/persetujuan sama sekali. -->
    <template v-if="settings.menuReleaseEnabled">
      <ReleaseDialog v-if="auth.hasPermission('menu.release.publish')" :open="releaseOpen" @close="releaseOpen = false" @released="refreshAll" @changed="loadSummary" />
      <ApprovalList v-if="auth.hasPermission('menu.price.approve')" :open="approvalsOpen" @close="approvalsOpen = false" @changed="refreshAll" />
    </template>

    <MasterDataImportModal :open="showImportModal" @close="showImportModal = false" @imported="afterImport" />
  </div>
</template>
