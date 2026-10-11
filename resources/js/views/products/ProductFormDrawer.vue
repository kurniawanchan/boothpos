<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { getProduct, createProduct, updateProduct, addVariant, updateVariant, uploadProductImage, uploadVariantImage } from '../../api/products';
import { createAdjustment } from '../../api/stock';
import { listStations } from '../../api/stations';
import { useAuthStore } from '../../stores/auth';
import { useToastStore } from '../../stores/toast';
import { toMoneyString } from '../../utils/money';
import { imageFileError } from './imageGuard';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseDrawer from '../../components/ui/BaseDrawer.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import BaseSelect from '../../components/ui/BaseSelect.vue';
import BaseTextarea from '../../components/ui/BaseTextarea.vue';
import ImageLightbox from '../../components/ui/ImageLightbox.vue';
import VariantBomModal from '../../components/product/VariantBomModal.vue';
import ProductCodePreview from './ProductCodePreview.vue';
import ProductVariantCard from './ProductVariantCard.vue';

/**
 * Drawer tambah/ubah produk beserta varian-variannya — dipecah utuh dari ProductsView (perilaku identik;
 * hanya lokasi kode yang berpindah). Drawer memegang seluruh keadaan formulir dan alur simpan; halaman hanya
 * memanggil `openCreate()` / `openEdit(product)` (defineExpose) dan menyegarkan daftar saat `saved`.
 * Daftar artist/kategori dipinjam dari halaman (sudah dimuat untuk filter) supaya tidak ada panggilan ganda.
 */
const props = defineProps({
  artists: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['saved']);

const auth = useAuthStore();
const { t } = useI18n();
const toast = useToastStore();

const emptyVariant = () => ({ id: null, copy_bom_from: '', variant_name: 'Standard', cost_price: '0', sell_price: '0', current_stock: '0', original_stock: 0, low_stock_alert: '', is_active: true, track_stock: true, image_file: null, image_error: '' });

const showDrawer = ref(false);
const editingProduct = ref(null);
const saving = ref(false);
const markupPercent = ref(150);

// 028 — daftar stasiun untuk dropdown; 403 (peran tanpa `dining_setup`/`kitchen`) = dropdown disembunyikan.
const stations = ref([]);
onMounted(async () => {
  try {
    stations.value = (await listStations({ active: 1 })).data;
  } catch {
    stations.value = [];
  }
});

const productForm = reactive({
  artist_id: '',
  category_id: '',
  product_segment: '',
  name: '',
  description: '',
  is_preorder: false,
  preorder_eta: '',
  is_active: true,
  station_id: '',
});
const variantRows = ref([emptyVariant()]);
const formErrors = reactive({});

// Stok diedit per baris (row.current_stock vs snapshot original_stock), tetapi ditulis lewat
// POST /stock/adjustments sebagai satu batch dengan satu alasan bersama, sesuai kontrak StockAdjustmentRequest.
const stockAdjustmentReason = ref('');
const hasStockChanges = computed(() =>
  variantRows.value.some((row) => Number(row.current_stock) !== Number(row.original_stock ?? 0))
);

function deriveSegment(name) {
  const letters = (name || '').replace(/[^A-Za-z]/g, '').toUpperCase();
  return letters.slice(0, 3).padEnd(3, 'X');
}
const effectiveSegment = computed(() => (productForm.product_segment || deriveSegment(productForm.name)).toUpperCase().slice(0, 3).padEnd(3, 'X'));
const artistCode = computed(() => props.artists.find((a) => a.id === Number(productForm.artist_id))?.code ?? '···');
const categoryCode = computed(() => props.categories.find((c) => c.id === Number(productForm.category_id))?.code ?? '··');
const codePreview = computed(() => `${artistCode.value}-${categoryCode.value}-${effectiveSegment.value}-001`);

// Lightbox milik drawer: gambar produk yang sedang diedit dan gambar per-varian. (Thumbnail di tabel punya
// lightbox sendiri di halaman — dua lightbox tidak pernah terbuka bersamaan.)
const lightboxSrc = ref(null);
const lightboxAlt = ref('');
function openEditingImageLightbox() {
  if (!editingProduct.value?.image_url) return;
  lightboxSrc.value = editingProduct.value.image_url;
  lightboxAlt.value = editingProduct.value.name;
}
function openVariantImageLightbox(row) {
  if (!row.image_url) return;
  lightboxSrc.value = row.image_url;
  lightboxAlt.value = row.variant_name;
}

const productImageFile = ref(null);
const productImageError = ref('');
const productImageInputEl = ref(null);
const uploadingProductImage = ref(false);

function onProductImageChange(e) {
  const file = e.target.files?.[0] ?? null;
  productImageError.value = '';
  productImageFile.value = null;
  if (!file) return;
  const errKey = imageFileError(file);
  if (errKey) {
    productImageError.value = t(errKey);
    e.target.value = '';
    return;
  }
  productImageFile.value = file;
}

function resetImageAndReason() {
  productImageFile.value = null;
  productImageError.value = '';
  stockAdjustmentReason.value = '';
  if (productImageInputEl.value) productImageInputEl.value.value = '';
}

function openCreate() {
  editingProduct.value = null;
  Object.assign(productForm, {
    artist_id: props.artists[0]?.id ?? '',
    category_id: props.categories[0]?.id ?? '',
    product_segment: '',
    name: '',
    description: '',
    is_preorder: false,
    preorder_eta: '',
    is_active: true,
    station_id: '',
  });
  variantRows.value = [emptyVariant()];
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  resetImageAndReason();
  showDrawer.value = true;
}

async function openEdit(product) {
  const full = await getProduct(product.id);
  editingProduct.value = full;
  resetImageAndReason();
  Object.assign(productForm, {
    artist_id: full.artist_id,
    category_id: full.category_id,
    product_segment: full.product_segment ?? '',
    name: full.name,
    description: full.description ?? '',
    is_preorder: full.is_preorder,
    preorder_eta: full.preorder_eta ?? '',
    is_active: full.is_active,
    station_id: full.station_id ?? '',
  });
  variantRows.value = full.variants.length
    ? full.variants.map((v) => ({ ...v, low_stock_alert: v.low_stock_alert ?? '', original_stock: v.current_stock, image_file: null, image_error: '' }))
    : [emptyVariant()];
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showDrawer.value = true;
}
defineExpose({ openCreate, openEdit });

// --- 034-seller-po-bom: BOM varian dari drawer edit ---------------------------
// Varian yang BOM-nya SELESAI: harga modal mengikuti biaya BOM dan dikunci (server menolak edit manual
// dengan 409). BOM dibuka lewat modal yang sama dengan Detail Produk; setelah berubah, hanya field turunan
// BOM baris yang disegarkan — isian lain yang sedang diedit tidak ditimpa.
const showBom = ref(false);
const bomRow = ref(null);

// Pilihan "salin BOM dari" untuk varian BARU pada produk yang sudah ada: hanya varian tersimpan yang sudah
// punya BOM, dan hanya untuk pengguna yang boleh mengelola BOM (butuh menu purchase_orders juga — server
// menegakkan). "Mulai dengan BOM kosong" adalah placeholder select (nilai kosong), bukan opsi kedua.
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
    // Tidak ada DELETE /variants/{id} — varian yang sudah tersimpan hanya bisa dinonaktifkan, tidak pernah
    // dihapus dari array di sisi server.
    row.is_active = false;
    return;
  }
  variantRows.value.splice(index, 1);
}

async function saveProduct() {
  // Stok tidak pernah ditulis langsung (meniru jalur impor master-data) — baris stock_movements sungguhan
  // dibuat lewat POST /stock/adjustments yang sama (StockService::applyMovement), jadi alasan yang diminta
  // wajib di sini juga, diperiksa di awal sebelum panggilan simpan apa pun berjalan.
  if (hasStockChanges.value && !stockAdjustmentReason.value.trim()) {
    formErrors.stock_adjustment_reason = t('master_data.stock_adjustment_reason_required');
    return;
  }

  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  let productId = editingProduct.value?.id ?? null;
  // Pasangan { variantId, file } dikumpulkan saat varian dibuat/diubah, diunggah baru setelah SEMUA varian
  // punya id sungguhan (baris baru tidak punya id sampai panggilan create-nya kembali).
  const pendingVariantImages = [];
  // Pasangan { variant_id, qty_change } untuk POST /stock/adjustments, alasan penundaan yang sama.
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
        station_id: productForm.station_id || null,
      });
      for (const row of variantRows.value) {
        const payload = {
          variant_name: row.variant_name,
          cost_price: toMoneyString(row.cost_price),
          sell_price: toMoneyString(row.sell_price),
          low_stock_alert: row.low_stock_alert === '' ? null : Number(row.low_stock_alert),
          is_active: row.is_active,
          track_stock: row.track_stock !== false,
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
        station_id: productForm.station_id || null,
        variants: variantRows.value.map((row) => ({
          variant_name: row.variant_name,
          cost_price: toMoneyString(row.cost_price),
          sell_price: toMoneyString(row.sell_price),
          low_stock_alert: row.low_stock_alert === '' ? null : Number(row.low_stock_alert),
          track_stock: row.track_stock !== false,
        })),
      });
      productId = created.id;
      // POST /products membuat varian secara inline (tanpa respons per-baris), jadi varian produk yang
      // dikembalikan — selalu dalam urutan pembuatan — dicocokkan ke variantRows lewat indeks.
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
    emit('saved');
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
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
          <!-- 028 — stasiun persiapan khusus produk ini (menang atas stasiun kategorinya). Hanya tampil bila
               peran boleh membaca daftar stasiun; kosong = ikut kategori. -->
          <BaseSelect v-if="stations.length" v-model="productForm.station_id" :label="t('master_data.station')" :placeholder="t('master_data.station_follow_category')" :options="stations.map((s) => ({ value: s.id, label: s.name }))" :hint="t('master_data.station_hint')" :error="formErrors.station_id" />
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

      <ProductCodePreview :artist-code="artistCode" :category-code="categoryCode" :segment="effectiveSegment" :preview="codePreview" />

      <!-- 037: baki abu-abu muda + kartu putih bergaris tipis dan bayangan lembut, jarak antar-kartu lega —
           batas antar-varian jelas tanpa blok warna berat. -->
      <div class="flex flex-col gap-5 rounded-card border border-line-2 bg-surface-subtle p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span class="text-[14.5px] font-bold">{{ t('master_data.variants_and_prices') }}</span>
          <label class="flex items-center gap-2 text-[12.5px] text-muted">
            {{ t('master_data.markup') }}
            <input v-model.number="markupPercent" type="number" class="w-[70px] rounded-md border border-line px-2.5 py-1.5 text-right text-[13px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100" />
            %
          </label>
        </div>

        <ProductVariantCard
          v-for="(row, idx) in variantRows"
          :key="idx"
          :row="row"
          :markup-percent="markupPercent"
          :can-copy-bom-on-create="canCopyBomOnCreate"
          :copy-source-options="copySourceOptions"
          @open-bom="openBomFor"
          @remove="removeVariantRow(idx)"
          @duplicate="duplicateVariantRow(idx)"
          @enlarge-image="openVariantImageLightbox"
        />
        <button type="button" class="flex h-11 items-center justify-center gap-2 rounded-lg border border-dashed border-disabled-2 text-[13.5px] font-bold text-muted-5 hover:border-brand hover:text-brand-active" @click="addVariantRow">
          <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
          {{ t('master_data.add_variant') }}
        </button>
        <!-- Stok tidak pernah ditulis langsung — perubahan di sini menjadi baris stock_movements sungguhan
             lewat POST /stock/adjustments (jalur StockService::applyMovement yang sama dengan impor
             master-data), jadi satu alasan bersama wajib untuk seluruh batch. -->
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
</template>
