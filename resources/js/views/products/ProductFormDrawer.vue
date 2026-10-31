<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { STOCK_SOURCES, stockSourceWarningKeys } from '../../utils/stockSource';
import { useI18n } from 'vue-i18n';
import { getProduct, createProduct, updateProduct, addVariant, updateVariant, uploadProductImage, uploadVariantImage, saveVariants, saveModifierLinks } from '../../api/products';
import { createAdjustment } from '../../api/stock';
import { listStations } from '../../api/stations';
import { listModifierGroups } from '../../api/modifierGroups';
import { useAuthStore } from '../../stores/auth';
import { useSettingsStore } from '../../stores/settings';
import { useOutletStore } from '../../stores/outlet';
import { useToastStore } from '../../stores/toast';
import { toMoneyString } from '../../utils/money';
import { imageFileError } from './imageGuard';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseDrawer from '../../components/ui/BaseDrawer.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import ImageLightbox from '../../components/ui/ImageLightbox.vue';
import VariantBomModal from '../../components/product/VariantBomModal.vue';
import ProductFormBlock from './ProductFormBlock.vue';
import ProductField from './ProductField.vue';
import ProductVariantsTable from './ProductVariantsTable.vue';
import ProductCreateModifiers from './ProductCreateModifiers.vue';
import { controlClass, textareaClass, borderOf } from './formStyle';
import { blankLinkRow, validateRows, toLinksPayload } from './modifierLinkRows';
import { moveRow, excludedIds } from '../../utils/modifierExclusion';
import InheritedModifierList from '../../components/product/InheritedModifierList.vue';
import ConfirmDialog from '../../components/ui/ConfirmDialog.vue';
import { useVariantDelete } from '../../composables/useVariantDelete';

/**
 * Drawer tambah/ubah produk beserta varian-variannya — dipecah utuh dari ProductsView. Drawer memegang seluruh
 * keadaan formulir dan alur simpan; halaman hanya memanggil `openCreate()` / `openEdit(product)` (defineExpose)
 * dan menyegarkan daftar saat `saved` (payload `{ id, created }` — ADITIF, pemanggil lama boleh mengabaikannya).
 * Daftar outlet/kategori dipinjam dari halaman (sudah dimuat untuk filter) supaya tidak ada panggilan ganda.
 *
 * 043 — tampilan mengikuti desain "Menu item": bagian rata (Informasi dasar, Harga & pajak, Varian & harga,
 * Modifier, Dapur, Ketersediaan) dipisah garis tipis, kontrol natif bergaris tipis.
 * - MODE BUAT: seluruh tata letak baru. "Harga dasar" item = harga varian PERTAMA (satu nilai, dua tampilan:
 *   `basePrice` menulis ke `variantRows[0]`). Setelah `POST /products` (yang tidak menerima is_active/stok per
 *   varian maupun tautan modifier) formulir menjalankan LANJUTAN terpisah: nonaktifkan varian tak dicentang
 *   (`PUT /products/{id}/variants`), tautkan modifier (`PUT /products/{id}/modifier-links`), lalu stok awal
 *   (`POST /stock/adjustments`). Item SUDAH ada saat lanjutan gagal, jadi kegagalannya hanya menampilkan galat dan
 *   formulir tetap ditutup + `saved` dipancarkan — jangan mengulang POST (akan menggandakan item).
 *   070: tabel varian mode buat memakai tata letak yang sama dengan mode ubah; bagian Ketersediaan di formulir buat
 *   dihapus (outlet pembawa tetap lewat "Aktif di outlet"), dan Status Draft/Aktif kini ada di formulir UBAH.
 * - MODE UBAH: FORMULIR YANG SAMA dengan mode buat (bagian, urutan, tabel, kepala/kaki, lebar) — hanya Status yang
 *   tidak ada (status item yang sudah ada berubah lewat Hentikan/Aktifkan di halaman item) dan segmen kode yang
 *   terkunci (server tidak pernah menerimanya saat ubah). Tabel varian SAMA (`ProductVariantsTable`) dengan kolom
 *   tambahan (harga modal bila boleh melihat biaya, stok saat ini) dan aksi baris (BOM, markup, duplikat). Tabel
 *   Modifier dibenihi dari tautan tingkat-PRODUK item (`modifier_links` dengan `source: 'product'`); tautan
 *   dari KATEGORI hanya ditampilkan (cadangan di server — mengirimnya akan mengubahnya diam-diam menjadi tautan
 *   produk, sama seperti ModifierLinksDrawer) dan hanya dikirim bila barisnya BERUBAH. Alur simpan lama
 *   (updateProduct → per-varian → stok → gambar) tidak berubah urutannya; tautan modifier ditambahkan sesudah varian.
 */
const props = defineProps({
  outlets: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['saved']);

const auth = useAuthStore();
const { t } = useI18n();
const toast = useToastStore();
const settings = useSettingsStore();

const emptyVariant = () => ({ id: null, copy_bom_from: '', variant_name: 'Standard', cost_price: '0', sell_price: '0', current_stock: '0', original_stock: 0, low_stock_alert: '', is_active: true, track_stock: true, image_file: null, image_error: '' });

// Varian baru di formulir BUAT: nama & harga kosong (placeholder desain), stok awal kosong.
const createVariant = () => ({ ...emptyVariant(), variant_name: '', sell_price: '', current_stock: '', original_stock: 0 });
// Baris tunggal tanpa nama memakai nama bawaan lama ("Standard") — server mewajibkan nama varian.
const DEFAULT_VARIANT_NAME = 'Standard';

const showDrawer = ref(false);
const editingProduct = ref(null);
const isCreate = computed(() => !editingProduct.value);
// Rilis menu aktif: server memaksa item baru berstatus draft, jadi pilihan status tidak ditawarkan.
const releaseOn = computed(() => settings.menuReleaseEnabled === true);
const saving = ref(false);
const markupPercent = ref(150);
// Biaya hanya untuk pengguna dengan menu products/reports — cermin aturan server (`ProductController::show` membuang
// cost_price/has_bom/bom_cost dari payload selain itu). Tanpa izin: kolom modal disembunyikan dan cost_price tidak
// dikirim saat ubah (mengirim nilai kosong akan menimpa modal sungguhan dengan 0).
const canSeeCost = computed(() => auth.canAccessMenu('products') || auth.canAccessMenu('reports'));

// 028 — daftar stasiun untuk dropdown; 403 (peran tanpa `dining_setup`/`kitchen`) = dropdown disembunyikan.
const stations = ref([]);
onMounted(async () => {
  try {
    stations.value = (await listStations({ active: 1 })).data;
  } catch {
    stations.value = [];
  }
});

// 043 — fakta item baru: nama pendek, kategori pajak, estimasi persiapan, penanda item ritel. Kunci kategori pajak
// SAMA dengan yang disimpan server; label tampilannya ada di terjemahan (`products.tax_*`).
const TAX_CATEGORIES = ['restaurant_food_beverage', 'retail', 'other'];
const taxOptions = computed(() => TAX_CATEGORIES.map((k) => ({ value: k, label: t(`products.tax_${k}`) })));

// 045 Stage 3 — outlet yang menjual item (dicentang semua bawaannya). Tampil hanya bila beberapa outlet terlihat.
const outletStore = useOutletStore();

const productForm = reactive({
  outlet_ids: [],
  home_outlet_id: '',
  category_id: '',
  product_segment: '',
  name: '',
  description: '',
  is_active: true,
  station_id: '',
  short_name: '',
  tax_category: '',
  prep_minutes: '',
  is_retail: false,
  stock_source: 'variant', // 087 US6
});
const variantRows = ref([createVariant()]);

const formErrors = reactive({});

// "Harga dasar" item = harga varian pertama; menulis di sini atau di tabel varian mengubah nilai yang sama.
const basePrice = computed({
  get: () => variantRows.value[0]?.sell_price ?? '',
  set: (v) => {
    if (variantRows.value[0]) variantRows.value[0].sell_price = v;
  },
});

// Tautan modifier (buat DAN ubah): grup dari GET /modifier-groups; gagal/403 -> bagian disembunyikan, bukan dinonaktifkan.
const modifierRows = ref([blankLinkRow()]);
// Mode ubah: tautan dari KATEGORI (hanya tampil) dan potret payload awal untuk mendeteksi perubahan.
const inheritedLinks = ref([]);
// 086 — grup kategori yang dilepas item ini + keadaan awalnya (hanya dikirim bila berubah; tanpa kunci server membiarkannya).
const excludedLinks = ref([]);
const excludedBaseline = ref('[]');
const modifierBaseline = ref('[]');
const modifierGroups = ref([]);
const modifierErrors = ref({});
const groupsReady = ref(false);
const groupById = computed(() => new Map(modifierGroups.value.map((g) => [g.id, g])));
async function loadModifierGroups() {
  groupsReady.value = false;
  try {
    modifierGroups.value = (await listModifierGroups())?.data ?? [];
    groupsReady.value = true;
  } catch {
    modifierGroups.value = [];
  }
}

// Stok diedit per baris (row.current_stock vs snapshot original_stock), tetapi ditulis lewat
// POST /stock/adjustments sebagai satu batch dengan satu alasan bersama, sesuai kontrak StockAdjustmentRequest.
const stockAdjustmentReason = ref('');
const hasStockChanges = computed(() =>
  variantRows.value.some((row) => Number(row.current_stock) !== Number(row.original_stock ?? 0))
);

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

// `defaults` memungkinkan tab "Item ritel" membuka formulir dengan penanda ritel sudah dicentang.
function openCreate(defaults = {}) {
  editingProduct.value = null;
  Object.assign(productForm, {
    outlet_ids: outletStore.outlets.map((o) => o.id),
    home_outlet_id: props.outlets[0]?.id ?? '',
    // Kategori sengaja kosong ("Pilih…" seperti desain): wajib dipilih, bukan ditebak.
    category_id: '',
    product_segment: '',
    name: '',
    description: '',
    is_active: true,
    station_id: '',
    short_name: '',
    tax_category: '',
    prep_minutes: '',
    is_retail: defaults.is_retail === true,
    stock_source: 'variant',
  });
  variantRows.value = [createVariant()];
  modifierRows.value = [blankLinkRow()];
  inheritedLinks.value = [];
  excludedLinks.value = [];
  excludedBaseline.value = '[]';
  modifierBaseline.value = '[]';
  modifierErrors.value = {};
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  resetImageAndReason();
  showDrawer.value = true;
  loadModifierGroups();
}

// API mengirim uang sebagai "15000.00"; di kolom angka itu tampil "15000,00" menurut locale dan terpotong. Tampilkan angka polos.
function plainNumber(value) {
  return value === null || value === undefined || value === '' ? value : String(Number(value));
}

async function openEdit(product) {
  const full = await getProduct(product.id);
  editingProduct.value = full;
  resetImageAndReason();
  Object.assign(productForm, {
    outlet_ids: (full.outlets ?? []).filter((o) => o.carried).map((o) => o.id),
    home_outlet_id: full.home_outlet_id,
    category_id: full.category_id,
    product_segment: full.product_segment ?? '',
    name: full.name,
    description: full.description ?? '',
    is_active: full.is_active,
    station_id: full.station_id ?? '',
    short_name: full.short_name ?? '',
    tax_category: full.tax_category ?? '',
    prep_minutes: full.prep_minutes ?? '',
    is_retail: full.is_retail === true,
    stock_source: full.stock_source ?? 'variant',
  });
  variantRows.value = full.variants.length
    ? full.variants.map((v) => ({ ...v, cost_price: plainNumber(v.cost_price), sell_price: plainNumber(v.sell_price), low_stock_alert: v.low_stock_alert ?? '', original_stock: v.current_stock, image_file: null, image_error: '' }))
    : [emptyVariant()];
  // Tautan modifier: hanya baris tingkat-PRODUK yang dapat diubah; `source: 'category'` dibawa terpisah (hanya tampil).
  const links = full.modifier_links ?? [];
  const own = links
    .filter((l) => l.source !== 'category')
    .map((l) => ({
      group_id: l.group_id,
      is_required: !!l.is_required,
      min: String(l.min_select ?? 0),
      max: l.max_select === null || l.max_select === undefined ? '' : String(l.max_select),
    }));
  modifierRows.value = own.length ? own : [blankLinkRow()];
  modifierBaseline.value = JSON.stringify(toLinksPayload(modifierRows.value));
  inheritedLinks.value = links.filter((l) => l.source === 'category');
  excludedLinks.value = [...(full.modifier_excluded ?? [])];
  excludedBaseline.value = JSON.stringify(excludedIds(excludedLinks.value));
  modifierErrors.value = {};
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showDrawer.value = true;
  loadModifierGroups();
}
defineExpose({ openCreate, openEdit });

// 086 — melepas/memulihkan grup warisan kategori untuk item ini; pemindahan baris sama dengan laci Grup modifier.
function excludeInherited(groupId) {
  const moved = moveRow(inheritedLinks.value, excludedLinks.value, groupId);
  inheritedLinks.value = moved.from;
  excludedLinks.value = moved.to;
}
function restoreInherited(groupId) {
  const moved = moveRow(excludedLinks.value, inheritedLinks.value, groupId);
  excludedLinks.value = moved.from;
  inheritedLinks.value = moved.to;
}

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
  variantRows.value.push(isCreate.value ? createVariant() : emptyVariant());
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

// 2026-10-31 (permintaan pemilik) — hapus permanen varian tersimpan (hanya tanpa riwayat; server yang menilai).
const variantDelete = useVariantDelete((variant) => {
  variantRows.value = variantRows.value.filter((r) => r.id !== variant.id);
  emit('saved');
});

function removeVariantRow(index) {
  const row = variantRows.value[index];
  if (row.id) {
    // Tombol x hanya menonaktifkan varian tersimpan; hapus permanen punya tombolnya sendiri (ikon tempat sampah,
    // DELETE /variants/{id}/permanent) karena hanya boleh untuk varian tanpa riwayat.
    row.is_active = false;
    return;
  }
  variantRows.value.splice(index, 1);
}

// Fakta item (043): dikirim apa adanya pada buat DAN ubah; kosong -> null supaya nilai lama bisa dihapus.
function factsPayload() {
  return {
    short_name: productForm.short_name.trim() || null,
    tax_category: productForm.tax_category || null,
    prep_minutes: productForm.prep_minutes === '' || productForm.prep_minutes === null ? null : Number(productForm.prep_minutes),
    is_retail: productForm.is_retail,
    stock_source: productForm.stock_source, // 087 US6 — server menentukan efeknya di semua modul
  };
}

// Validasi klien khusus mode BUAT (server tetap pemutus akhir). Galat memakai kunci yang sama dengan 422 server
// (`name`, `variants.0.sell_price`, …) supaya kedua sumber tampil di tempat yang sama.
// Aturan modifier yang sama dengan server (lihat modifierLinkRows) — dipakai buat dan ubah.
function validateModifierRules() {
  modifierErrors.value = validateRows(modifierRows.value, (id) => groupById.value.get(id)?.selection_type === 'single', {
    min: t('products.ml_err_min'),
    max: t('products.ml_err_max'),
    single: t('products.ml_err_single'),
  });
  return Object.keys(modifierErrors.value).length === 0;
}

// Mode ubah: hanya diperiksa bila bagian modifier bisa diedit (daftar grup terbaca) — kalau tidak, tautan tidak disentuh.
function validateModifiers() {
  return !groupsReady.value || validateModifierRules();
}

function validateCreate() {
  const required = t('products.f_required');
  if (!productForm.name.trim()) formErrors.name = required;
  if (productForm.home_outlet_id === '' || productForm.home_outlet_id === null) formErrors.home_outlet_id = required;
  if (productForm.category_id === '' || productForm.category_id === null) formErrors.category_id = required;
  variantRows.value.forEach((row, i) => {
    if (!row.variant_name.trim() && variantRows.value.length > 1) formErrors[`variants.${i}.variant_name`] = t('products.f_variant_name_required');
    const price = String(row.sell_price).trim();
    if (price === '' || Number.isNaN(Number(price)) || Number(price) < 0) formErrors[`variants.${i}.sell_price`] = t('products.f_price_invalid');
  });
  // 070 — item harus dijual di minimal satu outlet (server menolak daftar kosong juga).
  if (outletStore.multi && productForm.outlet_ids.length === 0) formErrors.outlet_ids = t('products.f_availability_none');
  validateModifierRules();
  return Object.keys(formErrors).length === 0 && Object.keys(modifierErrors.value).length === 0;
}

async function saveProduct() {
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  modifierErrors.value = {};
  if (isCreate.value && !validateCreate()) return;
  if (!isCreate.value && !validateModifiers()) return;

  // Stok tidak pernah ditulis langsung (meniru jalur impor master-data) — baris stock_movements sungguhan
  // dibuat lewat POST /stock/adjustments yang sama (StockService::applyMovement), jadi alasan yang diminta
  // wajib di sini juga, diperiksa di awal sebelum panggilan simpan apa pun berjalan. Formulir BUAT memakai alasan
  // tetap ("Stok awal item baru") — tidak ada stok sebelumnya yang perlu dijelaskan.
  if (!isCreate.value && hasStockChanges.value && !stockAdjustmentReason.value.trim()) {
    formErrors.stock_adjustment_reason = t('master_data.stock_adjustment_reason_required');
    return;
  }

  saving.value = true;
  let productId = editingProduct.value?.id ?? null;
  // Pasangan { variantId, file } dikumpulkan saat varian dibuat/diubah, diunggah baru setelah SEMUA varian
  // punya id sungguhan (baris baru tidak punya id sampai panggilan create-nya kembali).
  const pendingVariantImages = [];
  // Pasangan { variant_id, qty_change } untuk POST /stock/adjustments, alasan penundaan yang sama.
  const pendingStockAdjustments = [];
  function queueStockAdjustment(row, variantId) {
    // 087 — produk bersumber modifier tidak punya stok varian (server menolak koreksinya dengan 409).
    if (productForm.stock_source === 'modifier') return;
    const delta = Number(row.current_stock) - Number(row.original_stock ?? 0);
    if (delta !== 0) pendingStockAdjustments.push({ variant_id: variantId, qty_change: delta });
  }
  let created = false;
  try {
    if (editingProduct.value) {
      const updated = await updateProduct(editingProduct.value.id, {
        ...(outletStore.multi ? { outlet_ids: productForm.outlet_ids } : {}),
        home_outlet_id: Number(productForm.home_outlet_id),
        category_id: Number(productForm.category_id),
        name: productForm.name,
        description: productForm.description || null,
        is_active: productForm.is_active,
        station_id: productForm.station_id || null,
        ...factsPayload(),
      });
      for (const row of variantRows.value) {
        const payload = {
          variant_name: row.variant_name,
          ...(canSeeCost.value ? { cost_price: toMoneyString(row.cost_price) } : {}),
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
      // 087 — peringatan sumber stok (saldo varian tersisa / tanpa pemetaan bahan): diberi tahu, tidak menolak.
      for (const key of stockSourceWarningKeys(updated)) toast.warning(t(key), { timeout: 9000 });
      // Tautan modifier: hanya bila bagian itu terbaca DAN barisnya berubah (PUT mengganti seluruh tautan produk,
      // jadi tanpa perubahan tidak ada alasan menyentuhnya). Gagal di sini tidak membatalkan simpan: item sudah berubah.
      const links = toLinksPayload(modifierRows.value);
      const exclusionIds = excludedIds(excludedLinks.value);
      const exclusionChanged = JSON.stringify(exclusionIds) !== excludedBaseline.value;
      if (groupsReady.value && (JSON.stringify(links) !== modifierBaseline.value || exclusionChanged)) {
        try {
          // 086 — argumen pengecualian hanya ikut bila berubah (tanpa argumen = kuncinya tidak dikirim).
          const result = await saveModifierLinks(productId, links, ...(exclusionChanged ? [exclusionIds] : []));
          if (result?.applied === false) toast.push(t('products.ml_pending'));
        } catch {
          toast.error(t('products.f_links_failed'));
        }
      }
    } else {
      // Baris tunggal tanpa nama -> nama bawaan; beberapa baris sudah dicek `validateCreate` (nama wajib).
      const variantsToSend = variantRows.value.map((row) => ({
        variant_name: row.variant_name.trim() || DEFAULT_VARIANT_NAME,
        cost_price: toMoneyString(row.cost_price),
        sell_price: toMoneyString(row.sell_price),
        low_stock_alert: row.low_stock_alert === '' ? null : Number(row.low_stock_alert),
        track_stock: row.track_stock !== false,
      }));
      const createdProduct = await createProduct({
        ...(outletStore.multi ? { outlet_ids: productForm.outlet_ids } : {}),
        home_outlet_id: Number(productForm.home_outlet_id),
        category_id: Number(productForm.category_id),
        product_segment: productForm.product_segment || null,
        name: productForm.name,
        description: productForm.description || null,
        // Rilis aktif: server memaksa draft apa pun nilainya; dikirim apa adanya supaya perilaku rilis-mati tetap sama.
        is_active: productForm.is_active,
        station_id: productForm.station_id || null,
        ...factsPayload(),
        variants: variantsToSend,
      });
      created = true;
      productId = createdProduct.id;
      // POST /products membuat varian secara inline (tanpa respons per-baris), jadi varian produk yang
      // dikembalikan — selalu dalam urutan pembuatan — dicocokkan ke variantRows lewat indeks.
      const toDeactivate = [];
      variantRows.value.forEach((row, idx) => {
        const variantId = createdProduct.variants?.[idx]?.id;
        if (!variantId) return;
        if (row.image_file) pendingVariantImages.push({ variantId, file: row.image_file });
        queueStockAdjustment(row, variantId);
        if (!row.is_active) {
          toDeactivate.push({ id: variantId, variant_name: variantsToSend[idx].variant_name, sell_price: variantsToSend[idx].sell_price, is_active: false });
        }
      });
      toast.success(t('master_data.product_created'));

      // LANJUTAN pasca-buat (lihat docblock): masing-masing berdiri sendiri, gagalnya satu tidak membatalkan yang lain.
      if (toDeactivate.length) {
        try {
          await saveVariants(productId, { variants: toDeactivate });
        } catch {
          toast.error(t('products.f_inactive_failed'));
        }
      }
      const links = toLinksPayload(modifierRows.value);
      if (links.length) {
        try {
          const result = await saveModifierLinks(productId, links);
          if (result?.applied === false) toast.push(t('products.ml_pending'));
        } catch {
          toast.error(t('products.f_links_failed'));
        }
      }
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
        const reason = isCreate.value ? t('products.f_initial_stock_reason') : stockAdjustmentReason.value.trim();
        await createAdjustment({ reason, items: pendingStockAdjustments });
      } catch {
        toast.error(t('master_data.stock_adjustment_failed'));
      }
    }
    showDrawer.value = false;
    emit('saved', { id: productId, created });
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
    flush
    max-width-class="max-w-[1000px]"
    :title="isCreate ? t('products.f_menu_item') : editingProduct.name"
    @close="showDrawer = false"
  >
    <!-- Kepala desain: label kecil redup ("Baru"/"Ubah") di atas judul tebal; tombol × tetap milik BaseDrawer. -->
    <template #header>
      <div class="flex flex-col gap-0.5">
        <p class="text-[12px] text-muted">{{ isCreate ? t('products.f_kicker_new') : t('products.f_kicker_edit') }}</p>
        <h2 class="text-[19px] font-bold tracking-tight">{{ isCreate ? t('products.f_menu_item') : editingProduct.name }}</h2>
      </div>
    </template>

    <div class="flex flex-col" data-testid="product-form">
      <!-- INFORMASI DASAR -->
      <ProductFormBlock :title="t('products.f_sec_basic')">
        <ProductField v-slot="{ id }" :label="t('products.f_item_name')" required :error="formErrors.name">
          <input :id="id" v-model="productForm.name" type="text" maxlength="150" :placeholder="t('products.f_item_name_ph')" :class="[controlClass, borderOf(formErrors.name)]" />
        </ProductField>
        <div class="grid grid-cols-2 gap-3.5">
          <ProductField v-slot="{ id }" :label="t('products.f_short_name')" :hint="t('products.f_short_name_hint')" :error="formErrors.short_name">
            <input :id="id" v-model="productForm.short_name" type="text" maxlength="24" :class="[controlClass, borderOf(formErrors.short_name)]" />
          </ProductField>
          <ProductField v-slot="{ id }" :label="t('products.f_menu_category')" required :error="formErrors.category_id">
            <select :id="id" v-model="productForm.category_id" :class="[controlClass, borderOf(formErrors.category_id)]">
              <option value="">{{ t('common.select_placeholder') }}</option>
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </ProductField>
        </div>
        <!-- Outlet wajib (home_outlet_id) dan segmen kode: dua kolom biasa, bukan blok khusus. -->
        <div class="grid grid-cols-2 gap-3.5">
          <ProductField v-slot="{ id }" :label="t('master_data.owner_artist')" required :error="formErrors.home_outlet_id">
            <select :id="id" v-model="productForm.home_outlet_id" :class="[controlClass, borderOf(formErrors.home_outlet_id)]">
              <option v-for="a in outlets" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </ProductField>
          <ProductField v-slot="{ id }" :label="t('master_data.segment_3')" :error="formErrors.product_segment">
            <input :id="id" v-model="productForm.product_segment" type="text" maxlength="3" :disabled="!isCreate" :placeholder="t('master_data.auto')" :class="[controlClass, borderOf(formErrors.product_segment)]" />
          </ProductField>
        </div>
        <fieldset v-if="outletStore.multi" class="flex flex-col gap-1.5" data-testid="active-outlets">
          <legend class="text-[12.5px] font-semibold text-muted-4">{{ t('products.f_active_outlets') }}</legend>
          <p class="text-[11.5px] text-muted-3">{{ t('products.f_active_outlets_hint') }}</p>
          <label v-for="o in outletStore.outlets" :key="o.id" class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
            <input v-model="productForm.outlet_ids" type="checkbox" :value="o.id" class="h-4 w-4 rounded border-line accent-brand" />
            {{ o.name }}
          </label>
          <p v-if="formErrors.outlet_ids" class="text-[12px] font-medium text-danger-text" role="alert">{{ formErrors.outlet_ids }}</p>
        </fieldset>
        <ProductField v-slot="{ id }" :label="t('master_data.description')">
          <textarea :id="id" v-model="productForm.description" rows="3" :class="[textareaClass, borderOf('')]"></textarea>
        </ProductField>
        <p v-if="isCreate" class="text-[11.5px] text-muted-3" data-testid="code-note">{{ t('products.f_code_note') }}</p>
      </ProductFormBlock>

      <!-- HARGA & PAJAK. "Harga dasar" = harga varian pertama (satu nilai, dua tampilan) di KEDUA mode; saat ubah, harga
           varian tetap lewat jalur simpan per-varian (dan jalur rilis/persetujuan bila aktif). -->
      <ProductFormBlock
        :title="t('products.f_sec_price')"
        :description="releaseOn ? `${t('products.f_sec_price_desc')} ${t('products.f_sec_price_desc_release')}` : t('products.f_sec_price_desc')"
      >
        <div class="grid grid-cols-2 gap-3.5">
          <ProductField v-slot="{ id }" :label="t('products.f_base_price')" required :error="formErrors['variants.0.sell_price']">
            <div class="flex">
              <span class="flex h-9 flex-none items-center border border-r-0 border-line bg-surface-subtle px-3 text-[12.5px] font-semibold text-muted">{{ t('products.f_currency') }}</span>
              <input :id="id" v-model="basePrice" type="number" min="0" step="any" :class="[controlClass, borderOf(formErrors['variants.0.sell_price'])]" />
            </div>
          </ProductField>
          <ProductField v-slot="{ id }" :label="t('products.tax_category')" :error="formErrors.tax_category">
            <select :id="id" v-model="productForm.tax_category" :class="[controlClass, borderOf(formErrors.tax_category)]">
              <option value="">{{ t('common.select_placeholder') }}</option>
              <option v-for="o in taxOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </ProductField>
        </div>
      </ProductFormBlock>

      <!-- VARIAN & HARGA — SATU tabel untuk buat dan ubah. Markup (mode ubah, hanya bila boleh melihat biaya) ada di
           kepala bagian, seperti drawer lama. -->
      <ProductFormBlock :title="t('products.f_sec_variants')" :description="t('products.f_sec_variants_desc')">
        <template v-if="canSeeCost" #actions>
          <label class="flex items-center gap-2 text-[12.5px] text-muted">
            {{ t('master_data.markup') }}
            <input v-model.number="markupPercent" type="number" class="h-8 w-[70px] border border-line px-2.5 text-right text-[13px] outline-none focus:border-brand focus:ring-2 focus:ring-mint-100" />
            %
          </label>
        </template>
        <ProductVariantsTable
          :rows="variantRows"
          :errors="formErrors"
          :mode="isCreate ? 'create' : 'edit'"
          :stock-source="productForm.stock_source"
          :can-see-cost="canSeeCost"
          :markup-percent="markupPercent"
          :can-copy-bom-on-create="canCopyBomOnCreate"
          :copy-source-options="copySourceOptions"
          @add="addVariantRow"
          @remove="removeVariantRow"
          @duplicate="duplicateVariantRow"
          @open-bom="openBomFor"
          @enlarge-image="openVariantImageLightbox"
          @delete-permanent="(row) => variantDelete.ask({ id: row.id, name: row.variant_name })"
        />
        <!-- Stok tidak pernah ditulis langsung — perubahan di sini menjadi baris stock_movements sungguhan
             lewat POST /stock/adjustments (jalur StockService::applyMovement yang sama dengan impor
             master-data), jadi satu alasan bersama wajib untuk seluruh batch. -->
        <BaseInput
          v-if="!isCreate && hasStockChanges"
          v-model="stockAdjustmentReason"
          :label="t('master_data.stock_adjustment_reason')"
          required
          :error="formErrors.stock_adjustment_reason"
        />
      </ProductFormBlock>

      <!-- MODIFIER — buat DAN ubah; hanya bila daftar grup bisa dibaca (tanpa izin: bagian disembunyikan). Saat ubah, tabel
           berisi tautan tingkat-produk; tautan dari kategori ditampilkan di bawahnya, hanya-baca dan tidak dikirim. -->
      <ProductFormBlock v-if="groupsReady" :title="t('products.f_sec_modifier')">
        <ProductCreateModifiers :rows="modifierRows" :groups="modifierGroups" :errors="modifierErrors" @add="modifierRows.push(blankLinkRow())" @remove="(i) => modifierRows.splice(i, 1)" />
        <InheritedModifierList
          v-if="!isCreate && (inheritedLinks.length || excludedLinks.length)"
          :inherited="inheritedLinks"
          :excluded="excludedLinks"
          editable
          testid="inherited-links"
          @exclude="excludeInherited"
          @restore="restoreInherited"
        />
      </ProductFormBlock>

      <!-- DAPUR. Stasiun hanya tampil bila peran boleh membaca daftar stasiun; kosong = ikut kategori. -->
      <ProductFormBlock :title="t('products.f_sec_kitchen')">
        <div class="grid grid-cols-2 gap-3.5">
          <ProductField v-if="stations.length" v-slot="{ id }" :label="t('products.f_station')" :error="formErrors.station_id">
            <select :id="id" v-model="productForm.station_id" :class="[controlClass, borderOf(formErrors.station_id)]">
              <option value="">{{ t('master_data.station_follow_category') }}</option>
              <option v-for="s in stations" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </ProductField>
          <ProductField v-slot="{ id }" :label="t('products.f_prep')" :error="formErrors.prep_minutes">
            <div class="flex">
              <input :id="id" v-model="productForm.prep_minutes" type="number" min="0" max="600" step="1" :class="[controlClass, borderOf(formErrors.prep_minutes), 'min-w-0 flex-1']" />
              <span class="flex h-9 flex-none items-center border border-l-0 border-line bg-surface-subtle px-3 text-[12.5px] text-muted">{{ t('products.f_minutes') }}</span>
            </div>
          </ProductField>
        </div>
        <label class="flex items-center gap-2.5 text-[13px] font-medium text-muted-5">
          <input v-model="productForm.is_retail" type="checkbox" class="h-4 w-4 accent-brand" />
          {{ t('products.is_retail') }}
        </label>
        <!-- 087 US6 — sumber stok: varian (stok per varian) atau modifier (bahan dari pilihan modifier yang dipesan). -->
        <fieldset class="flex flex-col gap-1.5" data-testid="stock-source">
          <legend class="text-[12.5px] font-bold text-muted-4">{{ t('products.stock_source') }}</legend>
          <div class="flex flex-wrap gap-4">
            <label v-for="src in STOCK_SOURCES" :key="src" class="flex items-center gap-2 text-[13px] font-medium text-muted-5">
              <input v-model="productForm.stock_source" type="radio" name="stock_source" :value="src" class="h-4 w-4 accent-brand" />
              {{ t(`products.stock_source_${src}`) }}
            </label>
          </div>
          <p class="text-[11.5px] text-muted-3">{{ t(`products.stock_source_${productForm.stock_source}_hint`) }}</p>
        </fieldset>
      </ProductFormBlock>

      <!-- STATUS (070) — hanya saat UBAH: Draft atau Aktif. Saat buat item langsung aktif (atau draft bila rilis menu aktif);
           saat rilis aktif status berubah lewat rilis, jadi hanya catatan. Catatan: menonaktifkan item yang sudah terbit
           menjadikannya dihentikan (aturan Product::booted), bukan draft. -->
      <ProductFormBlock v-if="!isCreate" :title="t('products.f_status')">
        <p v-if="releaseOn" class="text-[13px] text-muted-5" data-testid="status-release-note">{{ t('products.f_status_release_edit') }}</p>
        <div v-else class="flex flex-col gap-1.5">
          <div class="inline-flex self-start" role="group" :aria-label="t('products.f_status')" data-testid="status-toggle">
            <button
              type="button"
              :aria-pressed="!productForm.is_active"
              class="h-9 border px-4 text-[13px] font-semibold"
              :class="!productForm.is_active ? 'border-brand bg-brand text-white' : 'border-line bg-white text-muted-5 hover:bg-surface-subtle'"
              @click="productForm.is_active = false"
            >
              {{ t('products.f_status_draft') }}
            </button>
            <button
              type="button"
              :aria-pressed="productForm.is_active"
              class="h-9 border border-l-0 px-4 text-[13px] font-semibold"
              :class="productForm.is_active ? 'border-brand bg-brand text-white' : 'border-line bg-white text-muted-5 hover:bg-surface-subtle'"
              @click="productForm.is_active = true"
            >
              {{ t('products.f_status_active') }}
            </button>
          </div>
          <p class="text-[11.5px] text-muted-3">{{ t('products.f_status_hint') }}</p>
        </div>
      </ProductFormBlock>

      <!-- LAINNYA — yang tetap dibutuhkan API tetapi tidak ada di desain: gambar produk. -->
      <ProductFormBlock :title="t('products.f_sec_other')">
        <ProductField v-slot="{ id }" :label="t('master_data.product_image')">
          <div class="flex items-center gap-3">
            <button
              v-if="editingProduct?.image_url && !productImageFile"
              type="button"
              class="h-16 w-16 flex-none cursor-zoom-in"
              :aria-label="t('master_data.enlarge_product_image', { name: editingProduct.name })"
              @click="openEditingImageLightbox"
            >
              <img :src="editingProduct.image_url" :alt="t('master_data.current_product_image')" class="h-16 w-16 rounded-lg border border-line-2 object-cover" />
            </button>
            <input
              :id="id"
              ref="productImageInputEl"
              type="file"
              accept="image/*"
              class="min-w-0 flex-1 border border-line bg-white px-3 py-1.5 text-[13px] file:mr-3 file:border-0 file:bg-mint-100 file:px-3 file:py-1 file:text-[12.5px] file:font-bold file:text-brand-active"
              @change="onProductImageChange"
            />
          </div>
          <p v-if="productImageError" class="text-[12px] font-semibold text-danger-text">{{ productImageError }}</p>
        </ProductField>
      </ProductFormBlock>
    </div>

    <template #footer>
      <BaseButton variant="secondary" @click="showDrawer = false">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="saving" @click="saveProduct">{{ t('products.f_save_item') }}</BaseButton>
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
  <ConfirmDialog
    :open="variantDelete.target.value !== null"
    :title="t('products.variant_delete_title')"
    :message="t('products.variant_delete_message', { name: variantDelete.target.value?.name ?? '' })"
    :confirm-label="t('products.variant_delete_confirm')"
    :loading="variantDelete.deleting.value"
    @close="variantDelete.cancel()"
    @confirm="variantDelete.confirm()"
  />
</template>
