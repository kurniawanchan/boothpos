<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listProducts, getProduct, createProduct, updateProduct, deleteProduct, addVariant, updateVariant, uploadProductImage, uploadVariantImage } from '../api/products';
import { createAdjustment } from '../api/stock';
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
import BaseTooltip from '../components/ui/BaseTooltip.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseMultiSelect from '../components/ui/BaseMultiSelect.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import MasterDataImportModal from '../components/masterData/MasterDataImportModal.vue';
import ProductDetailModal from '../components/product/ProductDetailModal.vue';
import VariantBomModal from '../components/product/VariantBomModal.vue';
import VariantDetailModal from '../components/product/VariantDetailModal.vue';
import ImageLightbox from '../components/ui/ImageLightbox.vue';

const auth = useAuthStore();
const { t } = useI18n();
const toast = useToastStore();

// 024-invoice-layout-shipping-slip — `with_variants=1` diminta sekarang
// khusus untuk kolom SKU baru di tabel ini (CLAUDE.md: endpoint ini
// SENGAJA tidak menyertakan variants secara default karena layar produk
// tadinya tidak menampilkannya — permintaan pengguna membalik keputusan
// itu untuk layar INI, `?with_variants=1` sudah ada dan dipakai POS,
// bukan endpoint baru).
const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listProducts, { with_variants: 1 });
const search = ref('');
// 005-ux-enhancements-dashboard (US1) — filter artist/category sekarang
// array (multi-select dropdown dengan pencarian), bukan lagi satu nilai
// chip tunggal dari 004-sidebar-menu-reorg. Array kosong = "Semua".
const artistFilter = ref([]);
const categoryFilter = ref([]);
const artists = ref([]);
const categories = ref([]);

const artistOptions = computed(() => artists.value.map((a) => ({ value: a.id, label: a.name })));
const categoryOptions = computed(() => categories.value.map((c) => ({ value: c.id, label: c.name })));

const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

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

// Per-variant image (added at the product owner's explicit request —
// different variants of the same product, e.g. different designs/motifs,
// can each have their own picture instead of all sharing the product's
// single image). Same client-side guard as onProductImageChange.
function onVariantImageChange(row, e) {
  const file = e.target.files?.[0] ?? null;
  row.image_error = '';
  row.image_file = null;
  if (!file) return;
  if (!file.type.startsWith('image/')) {
    row.image_error = t('master_data.image_must_be_image_generic');
    e.target.value = '';
    return;
  }
  if (file.size > MAX_IMAGE_BYTES) {
    row.image_error = t('master_data.image_max_size_generic');
    e.target.value = '';
    return;
  }
  row.image_file = file;
}

function openVariantImageLightbox(row) {
  if (!row.image_url) return;
  lightboxSrc.value = row.image_url;
  lightboxAlt.value = row.variant_name;
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
  { key: 'code_prefix', label: t('master_data.col_code') },
  { key: 'sku', label: t('master_data.col_sku') },
  { key: 'name', label: t('master_data.col_product_name') },
  { key: 'artist_name', label: t('master_data.col_artist') },
  { key: 'category_name', label: t('master_data.col_category') },
  { key: 'is_preorder', label: t('master_data.col_type') },
  { key: 'is_active', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

const emptyVariant = () => ({ id: null, copy_bom_from: '', variant_name: 'Standard', cost_price: '0', sell_price: '0', current_stock: '0', original_stock: 0, low_stock_alert: '', is_active: true, image_file: null, image_error: '' });

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

// Stock is edited per row above (row.current_stock vs. its original_stock
// snapshot), but written through POST /stock/adjustments as one batch with
// one shared reason, matching StockAdjustmentRequest's contract.
const stockAdjustmentReason = ref('');
const hasStockChanges = computed(() =>
  variantRows.value.some((row) => Number(row.current_stock) !== Number(row.original_stock ?? 0))
);

function deriveSegment(name) {
  const letters = (name || '').replace(/[^A-Za-z]/g, '').toUpperCase();
  return letters.slice(0, 3).padEnd(3, 'X');
}
const effectiveSegment = computed(() => (productForm.product_segment || deriveSegment(productForm.name)).toUpperCase().slice(0, 3).padEnd(3, 'X'));
const artistCode = computed(() => artists.value.find((a) => a.id === Number(productForm.artist_id))?.code ?? '···');
const categoryCode = computed(() => categories.value.find((c) => c.id === Number(productForm.category_id))?.code ?? '··');
const codePreview = computed(() => `${artistCode.value}-${categoryCode.value}-${effectiveSegment.value}-001`);

// SKU cell — hanya 3 SKU pertama ditampilkan per baris, sisanya di balik
// tautan "+N lainnya" yang bisa dibuka/tutup per baris (bukan sekaligus
// semua baris), supaya produk dengan banyak varian tidak membuat baris
// tabel menjadi sangat tinggi secara default.
const SKU_PREVIEW_COUNT = 3;
const expandedSkuRowIds = ref(new Set());
function toggleSkuExpand(rowId) {
  const next = new Set(expandedSkuRowIds.value);
  if (next.has(rowId)) next.delete(rowId);
  else next.add(rowId);
  expandedSkuRowIds.value = next;
}

// Clicking an individual SKU opens its own variant detail — separate from
// "Detail" (whole-product, every variant at once).
const showVariantDetail = ref(false);
const detailVariant = ref(null);
const detailVariantProduct = ref(null);
function openVariantDetail(row, variant) {
  detailVariant.value = variant;
  detailVariantProduct.value = row;
  showVariantDetail.value = true;
}

// Klik thumbnail produk membuka ImageLightbox yang sama dengan yang sudah
// dipakai di layar lain (invoice, QR pembayaran) — bukan lightbox baru.
const lightboxSrc = ref(null);
const lightboxAlt = ref('');
function openImageLightbox(row) {
  if (!row.image_url) return;
  lightboxSrc.value = row.image_url;
  lightboxAlt.value = row.name;
}

function openEditingImageLightbox() {
  if (!editingProduct.value?.image_url) return;
  lightboxSrc.value = editingProduct.value.image_url;
  lightboxAlt.value = editingProduct.value.name;
}

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
  stockAdjustmentReason.value = '';
  if (productImageInputEl.value) productImageInputEl.value.value = '';
  showDrawer.value = true;
}

async function openEdit(product) {
  const full = await getProduct(product.id);
  editingProduct.value = full;
  productImageFile.value = null;
  productImageError.value = '';
  stockAdjustmentReason.value = '';
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
    ? full.variants.map((v) => ({ ...v, low_stock_alert: v.low_stock_alert ?? '', original_stock: v.current_stock, image_file: null, image_error: '' }))
    : [emptyVariant()];
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showDrawer.value = true;
}

// --- 034-seller-po-bom: BOM varian dari drawer edit ---------------------------
// Varian yang BOM-nya SELESAI: harga modal mengikuti biaya BOM dan dikunci
// (server menolak edit manual dengan 409). BOM dibuka lewat modal yang sama
// dengan Detail Produk; setelah berubah, hanya field turunan BOM baris yang
// disegarkan — isian lain yang sedang diedit tidak ditimpa.
const showBom = ref(false);
const bomRow = ref(null);

// Pilihan "salin BOM dari" untuk varian BARU pada produk yang sudah ada:
// hanya varian tersimpan yang sudah punya BOM, dan hanya untuk pengguna yang
// boleh mengelola BOM (butuh menu purchase_orders juga — server menegakkan).
// "Mulai dengan BOM kosong" adalah placeholder select (nilai kosong), bukan opsi kedua.
const copySourceOptions = computed(() =>
  variantRows.value.filter((v) => v.id && v.has_bom).map((v) => ({ value: v.id, label: t('master_data.bom_copy_from_new', { name: `${v.sku} — ${v.variant_name}` }) })),
);
const canCopyBomOnCreate = computed(() => !!editingProduct.value && auth.canAccessMenu('purchase_orders') && copySourceOptions.value.length > 0);

function openBomFor(row) {
  bomRow.value = row;
  showBom.value = true;
}

async function refreshBomState() {
  if (!editingProduct.value) return;
  const fresh = await getProduct(editingProduct.value.id);
  for (const row of variantRows.value) {
    const updated = fresh.variants.find((v) => v.id === row.id);
    if (!updated) continue;
    Object.assign(row, {
      cost_price: updated.cost_price,
      bom_complete: updated.bom_complete,
      has_bom: updated.has_bom,
      bom_cost: updated.bom_cost,
    });
  }
}

function addVariantRow() {
  variantRows.value.push(emptyVariant());
}

// 037 — Duplikat varian: kartu BELUM TERSIMPAN tepat di bawah sumbernya, dibenihi dari sumber (nama + penanda
// salinan, harga, batas stok rendah, status dan STOK — keputusan pemilik produk: stok ikut disalin). Tidak ada
// endpoint duplikat: penyimpanan memakai alur varian-baru yang sudah ada — `copy_bom_from` membuat server
// menyalin BOM (satu transaksi, tidak pernah menandai selesai) dan stok masuk lewat penyesuaian stok bersama
// beserta alasannya (original_stock = 0, jadi selisihnya = seluruh stok salinan). Gambar, SKU, dan riwayat
// tidak disalin. BOM hanya dijanjikan bila sumbernya varian tersimpan ber-BOM DAN pengguna boleh mengelola BOM.
function duplicateVariantRow(index) {
  const source = variantRows.value[index];
  const copyBom = !!(editingProduct.value && source.id && source.has_bom && auth.canAccessMenu('purchase_orders'));
  variantRows.value.splice(index + 1, 0, {
    ...emptyVariant(),
    variant_name: t('master_data.variant_copy_suffix', { name: source.variant_name }),
    cost_price: source.cost_price,
    sell_price: source.sell_price,
    low_stock_alert: source.low_stock_alert ?? '',
    current_stock: source.current_stock,
    original_stock: 0,
    is_active: true,
    copy_bom_from: copyBom ? source.id : '',
    is_duplicate: true,
    duplicated_from_sku: source.sku ?? null,
  });
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

// Markup (profit ÷ cost) and margin (profit ÷ sell price) are different
// metrics by definition — e.g. a 50% markup is always exactly a 33%
// margin, regardless of the actual cost value. Both badges are shown side
// by side so that relationship is never mistaken for a bug.
function markupFor(row) {
  const cost = parseMoney(row.cost_price);
  const sell = parseMoney(row.sell_price);
  if (cost <= 0) return null;
  return Math.round(((sell - cost) / cost) * 100);
}

async function saveProduct() {
  // Stock is never written directly (mirrors the master-data import
  // path) — a real stock_movements row is required via the same
  // POST /stock/adjustments (StockService::applyMovement) every other
  // manual adjustment already goes through, so the reason it asks for is
  // mandatory here too, checked up front before any save call fires.
  if (hasStockChanges.value && !stockAdjustmentReason.value.trim()) {
    formErrors.stock_adjustment_reason = t('master_data.stock_adjustment_reason_required');
    return;
  }

  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  let productId = editingProduct.value?.id ?? null;
  // { variantId, file } pairs collected as variants are created/updated,
  // uploaded only after every variant has a real id (a brand-new row has
  // none until its create call returns).
  const pendingVariantImages = [];
  // { variant_id, qty_change } pairs for POST /stock/adjustments, same
  // deferred-until-real-id reasoning as pendingVariantImages above.
  const pendingStockAdjustments = [];
  function queueStockAdjustment(row, variantId) {
    const delta = Number(row.current_stock) - Number(row.original_stock ?? 0);
    if (delta !== 0) pendingStockAdjustments.push({ variant_id: variantId, qty_change: delta });
  }
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
        const newVariantPayload = row.copy_bom_from ? { ...payload, copy_bom_from_variant_id: Number(row.copy_bom_from) } : payload;
        const variantId = row.id ? (await updateVariant(row.id, payload)).id : (await addVariant(editingProduct.value.id, newVariantPayload)).id;
        if (row.image_file) pendingVariantImages.push({ variantId, file: row.image_file });
        queueStockAdjustment(row, variantId);
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
      // POST /products creates variants inline (no per-row response), so
      // the returned product's own variants — always in creation order —
      // are matched back to variantRows by index to know each new id.
      variantRows.value.forEach((row, idx) => {
        const variantId = created.variants?.[idx]?.id;
        if (!variantId) return;
        if (row.image_file) pendingVariantImages.push({ variantId, file: row.image_file });
        queueStockAdjustment(row, variantId);
      });
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
    for (const { variantId, file } of pendingVariantImages) {
      try {
        await uploadVariantImage(variantId, file);
      } catch {
        toast.error(t('master_data.product_saved_image_failed'));
      }
    }
    if (pendingStockAdjustments.length) {
      try {
        await createAdjustment({ reason: stockAdjustmentReason.value.trim(), items: pendingStockAdjustments });
      } catch {
        toast.error(t('master_data.stock_adjustment_failed'));
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

    <!-- 005-ux-enhancements-dashboard (US1) — dropdown multi-pilih dengan
         pencarian, menggantikan chip filter tunggal dari
         004-sidebar-menu-reorg (lihat research.md R2). -->
    <div class="flex flex-wrap gap-2.5">
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
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('master_data.no_products')">
        <!-- 038: gambar dan kode digabung dalam SATU kolom (gambar di atas, kode tepat di bawahnya) supaya
             gambar bisa lebih besar — 56px (036) -> 96px — tanpa melebarkan tabel: kolom khusus gambar
             dihapus. 036: kode SELALU satu baris (tadinya "SPF-KC-" / "DMC" terpotong di tanda hubung);
             kode yang sangat panjang dipotong dengan kode lengkap di tooltip, tidak pernah dibungkus.
             Placeholder satu ukuran supaya baris tetap sejajar. -->
        <template #cell-code_prefix="{ row }">
          <div class="flex flex-col items-center gap-1.5">
            <button
              v-if="row.image_url"
              type="button"
              class="cursor-zoom-in"
              :aria-label="t('master_data.enlarge_product_image', { name: row.name })"
              @click="openImageLightbox(row)"
            >
              <img :src="row.image_url" :alt="row.name" class="h-24 w-24 rounded-md border border-line-2 object-cover" />
            </button>
            <div v-else class="flex h-24 w-24 items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3">
              <i class="ph-duotone ph-image text-[30px]" aria-hidden="true"></i>
            </div>
            <span class="inline-block max-w-[190px] truncate whitespace-nowrap font-mono text-[12px] font-bold text-brand-active" :title="row.code_prefix">{{ row.code_prefix }}</span>
          </div>
        </template>
        <!-- 024-invoice-layout-shipping-slip — SKU sungguhan per varian
             (bukan sekadar code_prefix bersama); satu produk = satu atau
             lebih varian. Hanya SKU_PREVIEW_COUNT pertama ditampilkan
             default, sisanya di balik tautan "+N lainnya" per baris. -->
        <template #cell-sku="{ row }">
          <span v-if="!row.variants?.length" class="text-[11.5px] text-muted-3">—</span>
          <div v-else class="flex flex-wrap items-center gap-x-1 gap-y-1.5">
            <template
              v-for="(v, i) in (expandedSkuRowIds.has(row.id) ? row.variants : row.variants.slice(0, SKU_PREVIEW_COUNT))"
              :key="v.id"
            >
              <!-- 038: nama varian muncul saat SKU di-hover / difokus (BaseTooltip yang di-teleport, jadi tidak
                   terpotong wadah gulir tabel); klik tetap membuka detail varian. Nama kosong = tanpa tooltip. -->
              <BaseTooltip :text="v.variant_name ?? ''">
                <button
                  type="button"
                  class="font-mono text-[11.5px] text-muted-3 underline decoration-dotted hover:text-brand-active"
                  @click="openVariantDetail(row, v)"
                >{{ v.sku }}</button>
              </BaseTooltip><span
                v-if="i < (expandedSkuRowIds.has(row.id) ? row.variants.length : Math.min(row.variants.length, SKU_PREVIEW_COUNT)) - 1"
                class="text-[11.5px] text-muted-3"
              >,</span>
            </template>
            <button
              v-if="row.variants.length > SKU_PREVIEW_COUNT"
              type="button"
              class="whitespace-nowrap text-[11px] font-semibold text-brand-active underline decoration-dotted"
              @click="toggleSkuExpand(row.id)"
            >
              {{ expandedSkuRowIds.has(row.id) ? t('master_data.show_less') : t('master_data.show_more_count', { count: row.variants.length - SKU_PREVIEW_COUNT }) }}
            </button>
          </div>
        </template>
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
      max-width-class="max-w-[1040px]"
      :title="editingProduct ? editingProduct.name : t('master_data.new_product')"
      :subtitle="editingProduct ? editingProduct.code_prefix : t('master_data.code_generated_by_server')"
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
              <button
                v-if="editingProduct?.image_url && !productImageFile"
                type="button"
                class="h-16 w-16 flex-none cursor-zoom-in"
                :aria-label="t('master_data.enlarge_product_image', { name: editingProduct.name })"
                @click="openEditingImageLightbox"
              >
                <img
                  :src="editingProduct.image_url"
                  :alt="t('master_data.current_product_image')"
                  class="h-16 w-16 rounded-lg border border-line-2 object-cover"
                />
              </button>
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

        <!-- 037: baki abu-abu muda + kartu putih bergaris tipis dan bayangan lembut, jarak antar-kartu
             lega — batas antar-varian jelas tanpa blok warna berat. -->
        <div class="flex flex-col gap-5 rounded-card border border-line-2 bg-surface-subtle p-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="text-[14.5px] font-bold">{{ t('master_data.variants_and_prices') }}</span>
            <label class="flex items-center gap-2 text-[12.5px] text-muted">
              {{ t('master_data.markup') }}
              <input v-model.number="markupPercent" type="number" class="w-[70px] rounded-md border border-line px-2.5 py-1.5 text-right text-[13px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100" />
              %
            </label>
          </div>

          <div
            v-for="(row, idx) in variantRows"
            :key="idx"
            data-testid="variant-card"
            class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5 shadow-sm"
            :class="{ 'opacity-50': row.id && !row.is_active }"
          >
            <div data-testid="variant-header" class="flex flex-wrap items-center gap-2.5">
              <span v-if="row.sku" class="rounded-md bg-sky-bg px-2.5 py-1 font-mono text-[12px] font-semibold text-sky-text">{{ row.sku }}</span>
              <span v-if="row.bom_complete" class="rounded-md bg-mint-100 px-2 py-1 text-[11px] font-bold text-brand-active">{{ t('master_data.bom_from_bom') }}</span>
              <span v-if="markupFor(row) !== null" class="rounded-md px-2 py-1 text-[11px] font-bold" :class="markupFor(row) >= 0 ? 'bg-mint-100 text-brand-active' : 'bg-danger-bg text-danger-text'">
                {{ t('master_data.markup_value', { value: markupFor(row) }) }}
              </span>
              <span v-if="marginFor(row) !== null" class="rounded-md px-2 py-1 text-[11px] font-bold" :class="marginFor(row) >= 0 ? 'bg-violet-bg text-violet-text' : 'bg-danger-bg text-danger-text'">
                {{ t('master_data.margin', { value: marginFor(row) }) }}
              </span>
              <span v-if="row.id && row.has_bom" class="rounded-md bg-warn-bg px-2 py-1 text-[11px] font-bold text-warn-text">{{ t('master_data.bom_cost_label', { cost: formatIDR(row.bom_cost) }) }}</span>
              <span class="flex-1"></span>
              <!-- Urutan aksi sesuai permintaan: Buka BOM · Terapkan markup · hapus. -->
              <BaseTooltip v-if="row.id" :text="t('master_data.bom_open_tip')" align="right">
                <BaseButton variant="secondary" size="sm" @click="openBomFor(row)">
                  <i class="ph-duotone ph-stack text-[14px]" aria-hidden="true"></i>
                  {{ t('master_data.bom_open') }}
                </BaseButton>
              </BaseTooltip>
              <BaseButton variant="secondary" size="sm" @click="applyMarkup(row)">{{ t('master_data.apply_markup') }}</BaseButton>
              <button type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('master_data.delete_variant', { name: row.variant_name })" @click="removeVariantRow(idx)">
                <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
              </button>
            </div>
            <div class="grid grid-cols-2 items-end gap-3 lg:grid-cols-[1.6fr_0.8fr_1fr_1fr]">
              <BaseInput v-model="row.variant_name" class="col-span-2 lg:col-span-1" :label="t('master_data.variant_name')" />
              <BaseInput v-model="row.current_stock" type="number" min="0" :label="t('master_data.col_stock')" />
              <BaseInput
                v-model="row.cost_price"
                type="number"
                min="0"
                :label="t('master_data.cost_price')"
                :disabled="!!row.bom_complete"
                :hint="row.bom_complete ? t('master_data.bom_cost_price_locked_hint') : ''"
              />
              <BaseInput v-model="row.sell_price" type="number" min="0" :label="t('master_data.sell_price')" />
            </div>
            <BaseSelect
              v-if="!row.id && canCopyBomOnCreate"
              v-model="row.copy_bom_from"
              :label="t('master_data.bom_new_variant_label')"
              :options="copySourceOptions"
              :placeholder="t('master_data.bom_start_empty')"
            />
            <!-- 037: catatan pada kartu salinan — apa yang akan terjadi saat disimpan. -->
            <p v-if="row.is_duplicate && row.copy_bom_from && row.duplicated_from_sku" class="rounded-md bg-mint-50 px-3 py-2 text-[12px] font-semibold text-brand-active">
              {{ t('master_data.duplicate_bom_note', { sku: row.duplicated_from_sku }) }}
            </p>
            <p v-if="row.is_duplicate && Number(row.current_stock) > 0" class="rounded-md bg-warn-bg px-3 py-2 text-[12px] font-semibold text-warn-text">
              {{ t('master_data.duplicate_stock_note', { qty: row.current_stock }) }}
            </p>
            <!-- Per-variant image, added at the product owner's explicit
                 request — each variant (e.g. a different design/motif) can
                 show its own picture, independent of the product's own
                 image above. 037: 44px -> 66px (+50%), placeholder satu ukuran
                 supaya kartu tetap sejajar. -->
            <div class="flex items-center gap-3">
              <button
                v-if="row.image_url && !row.image_file"
                type="button"
                class="h-[66px] w-[66px] flex-none cursor-zoom-in"
                :aria-label="t('master_data.enlarge_variant_image', { name: row.variant_name })"
                @click="openVariantImageLightbox(row)"
              >
                <img :src="row.image_url" :alt="t('master_data.current_variant_image')" class="h-[66px] w-[66px] rounded-md border border-line-2 object-cover" />
              </button>
              <div v-else class="flex h-[66px] w-[66px] flex-none items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3">
                <i class="ph-duotone ph-image text-[24px]" aria-hidden="true"></i>
              </div>
              <input
                type="file"
                accept="image/*"
                class="flex-1 rounded-lg border border-line bg-white px-3 py-2 text-[12px] file:mr-2.5 file:rounded-md file:border-0 file:bg-mint-100 file:px-2.5 file:py-1 file:text-[11.5px] file:font-bold file:text-brand-active"
                @change="onVariantImageChange(row, $event)"
              />
            </div>
            <p v-if="row.image_error" class="text-[12px] font-semibold text-danger-text">{{ row.image_error }}</p>
            <div class="flex justify-end border-t border-line-3 pt-3">
              <BaseButton variant="secondary" size="sm" @click="duplicateVariantRow(idx)">
                <i class="ph-duotone ph-copy text-[14px]" aria-hidden="true"></i>
                {{ t('master_data.duplicate_variant') }}
              </BaseButton>
            </div>
          </div>
          <button type="button" class="flex h-11 items-center justify-center gap-2 rounded-lg border border-dashed border-disabled-2 text-[13.5px] font-bold text-muted-5 hover:border-brand hover:text-brand-active" @click="addVariantRow">
            <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
            {{ t('master_data.add_variant') }}
          </button>
          <!-- Stock is never written directly — a change here becomes a
               real stock_movements row via POST /stock/adjustments (the
               same StockService::applyMovement path the master-data import
               uses), so a shared reason is required for the whole batch. -->
          <BaseInput
            v-if="hasStockChanges"
            v-model="stockAdjustmentReason"
            :label="t('master_data.stock_adjustment_reason')"
            required
            :error="formErrors.stock_adjustment_reason"
          />
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
    <VariantDetailModal
      :open="showVariantDetail"
      :variant="detailVariant"
      :product-name="detailVariantProduct?.name"
      :artist-name="detailVariantProduct?.artist_name"
      :category-name="detailVariantProduct?.category_name"
      @close="showVariantDetail = false"
    />
    <VariantBomModal
      :open="showBom"
      :variant-id="bomRow?.id"
      :variant-sku="bomRow?.sku"
      :variant-name="bomRow?.variant_name"
      :siblings="variantRows.filter((v) => v.id)"
      @close="showBom = false"
      @changed="refreshBomState"
    />
    <ImageLightbox :open="!!lightboxSrc" :src="lightboxSrc" :alt="lightboxAlt" @close="lightboxSrc = null" />
  </div>
</template>
