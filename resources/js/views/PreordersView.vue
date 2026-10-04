<script setup>
import { reactive, ref, computed, onMounted, watch, h, render, getCurrentInstance } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { usePaginatedList } from '../composables/usePaginatedList';
import {
  listPreorders,
  getPreorder,
  createPreorder,
  updatePreorder,
  deletePreorder,
  updatePreorderStatus,
  updatePreorderDispatchStatus,
  exportPreorders,
  downloadPreorderImportTemplate,
  importPreorders,
  resendPreorderNotification,
  getPreorderSummary,
  bulkPreorderInvoices,
  bulkEmailPreorderInvoices,
  duplicatePreorders,
  deletePreorderPayment,
  createPreorderPayment,
} from '../api/preorders';
import { createShipment, updateShipment } from '../api/shipments';
import { getPaymentProofBlobUrl } from '../api/payments';
import { lookupVariants } from '../api/products';
import { getCustomer } from '../api/customers';
import { listArtists } from '../api/artists';
import { listEvents } from '../api/events';
import { useToastStore } from '../stores/toast';
import { useAuthStore } from '../stores/auth';
import PreorderInvoiceModal from '../components/preorder/PreorderInvoiceModal.vue';
import ImageLightbox from '../components/ui/ImageLightbox.vue';
import PreorderPaymentReceiptModal from '../components/preorder/PreorderPaymentReceiptModal.vue';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import { formatIDR, parseMoney, toMoneyString } from '../utils/money';
import { formatDate, formatDateTime } from '../utils/date';
import { downloadElementAsPdf, captureElementCanvas } from '../utils/pdfCapture';
import { buildShippingSlipHtml } from '../utils/invoiceDocument';
import PreorderInvoiceDocument from '../components/preorder/PreorderInvoiceDocument.vue';
import PreorderPaymentDocument from '../components/preorder/PreorderPaymentDocument.vue';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseDrawer from '../components/ui/BaseDrawer.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseMultiSelect from '../components/ui/BaseMultiSelect.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import CustomerSearchDropdown from '../components/preorder/CustomerSearchDropdown.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import AddPaymentModal from '../components/payment/AddPaymentModal.vue';
import PaymentSummaryCard from '../components/payment/PaymentSummaryCard.vue';
import PaymentHistoryList from '../components/payment/PaymentHistoryList.vue';
import PreorderStatusStepper from '../components/preorder/PreorderStatusStepper.vue';
import PreorderPrintMenu from '../components/preorder/PreorderPrintMenu.vue';
import PreorderRowActions from '../components/preorder/PreorderRowActions.vue';
import PreorderDuplicateResultModal from '../components/preorder/PreorderDuplicateResultModal.vue';
import PreorderSplitModal from '../components/preorder/PreorderSplitModal.vue';

const toast = useToastStore();
const auth = useAuthStore();
const { t } = useI18n();
// 007-preorder-import-export-notify (FR-015) — export/import/resend
// dibatasi owner/admin saja, meski menu_key 'preorders' sendiri masih
// dipakai bersama kasir/inventory untuk CRUD dasar (server menegakkan
// isOwnerOrAdmin() secara terpisah; ini hanya cermin kosmetik).
const isOwnerOrAdmin = computed(() => ['owner', 'admin'].includes((auth.role || '').toLowerCase()));

const STATUS_LABEL = computed(() => ({
  ordered: t('preorders.step_ordered'),
  dp_paid: t('preorders.step_dp_paid'),
  arrived: t('preorders.step_arrived'),
  settled: t('preorders.step_settled'),
  handed_over: t('preorders.step_handed_over'),
  cancelled: t('events_sessions.status_cancelled'),
}));
const STATUS_VARIANT = { ordered: 'neutral', dp_paid: 'warn', arrived: 'mint', settled: 'mint', handed_over: 'dark', cancelled: 'danger' };
// Penanda manual invoice-terkirim / pengiriman-berjalan. Urutan objek =
// urutan tombol di panel detail. HARUS sinkron dengan
// Preorder::DISPATCH_STATUSES di backend (validasi tetap di sana).
const DISPATCH_LABEL = computed(() => ({
  pending: t('preorders.dispatch_status_pending'),
  invoice_sent: t('preorders.dispatch_status_invoice_sent'),
  shipping: t('preorders.dispatch_status_shipping'),
}));
const DISPATCH_VARIANT = { pending: 'neutral', invoice_sent: 'warn', shipping: 'mint' };
// "Shipping in progress" hanya berlaku untuk Mail Order (fulfillment=courier)
// — pesanan pickup diambil di booth. Backend menegakkan ini (409); ini cermin
// UX supaya opsinya tidak ditawarkan sama sekali. Filter list tetap memuat
// semua opsi.
const detailDispatchOptions = computed(() =>
  Object.entries(DISPATCH_LABEL.value)
    .filter(([value]) => value !== 'shipping' || detail.value?.fulfillment === 'courier')
    .map(([value, label]) => ({ value, label }))
);
// 021-preorder-form-updates (US3, research.md Decision 5) — HARUS tetap
// sinkron dengan App\Support\Couriers::OPTIONS/DEFAULT di backend (satu
// sumber definisi konseptual, disalin ke sini karena tidak ada mekanisme
// berbagi konstanta PHP<->JS di codebase ini). Backend tetap sumber
// kebenaran untuk validasi; daftar ini hanya untuk render dropdown.
const COURIER_OPTIONS = ['JNE', 'J&T', 'SiCepat', 'Pos Indonesia', 'Other'];
const COURIER_DEFAULT = 'JNE';
const FULFILLMENT_LABEL = computed(() => ({
  pickup: t('preorders.fulfillment_pickup'),
  // 021-preorder-form-updates (US3, FR-007) — label saja yang berubah;
  // nilai enum tersimpan tetap 'courier' (research.md Decision 4).
  courier: t('preorders.fulfillment_mail_order'),
}));

const { items, meta, loading, load, setPage, setFilter, params } = usePaginatedList(listPreorders);
const route = useRoute();
const router = useRouter();

// 013-preorder-list-filters-receipt (US5, T026) — summary aggregate always
// refetched alongside the list, using the exact same filter params
// (`params` from usePaginatedList is the single source of truth for
// "what's currently filtered", see api-deltas.md), so the two never
// disagree.
const summary = ref(null);
const summaryLoading = ref(false);

async function loadSummary() {
  summaryLoading.value = true;
  try {
    summary.value = await getPreorderSummary({ ...params });
  } finally {
    summaryLoading.value = false;
  }
}

function applyFilter(patch) {
  setFilter(patch);
  loadSummary();
}

// 013-preorder-list-filters-receipt (US1, T008) — filter penjual, pola
// sama seperti seller filter di ReportsView.vue.
const artists = ref([]);

onMounted(load);
onMounted(loadSummary);
onMounted(async () => {
  artists.value = (await listArtists({ per_page: 100, is_active: true })).data;
});

// 021-preorder-form-updates (US3, research.md Decision 9) — form New
// Preorder TIDAK PERNAH punya pemilih event sebelum fitur ini; ditambah
// di sini karena hari-jemput (di bawah) butuh event untuk menurunkan
// pilihan tanggalnya.
const events = ref([]);
onMounted(async () => {
  events.value = (await listEvents({ per_page: 100 })).data;
});
const eventOptions = computed(() => events.value.map((e) => ({ value: e.id, label: e.name })));

// 009-ui-ux-refinements US5 (T043) — CustomerTransactionsModal.vue
// navigates here with ?preorder_id=<id> to open this existing detail
// drawer rather than duplicating it in the Customers screen. The query
// param is stripped once consumed so a refresh doesn't reopen it.
onMounted(async () => {
  const id = route.query.preorder_id;
  if (!id) return;
  await openDetailById(id);
  router.replace({ query: { ...route.query, preorder_id: undefined } });
});

// 007-preorder-import-export-notify (US1) — pencarian nama pelanggan,
// debounced sama seperti pola pencarian ProductsView.vue.
const customerSearch = ref('');
const debouncedCustomerSearch = useDebouncedFn(() => applyFilter({ search: customerSearch.value || undefined }), 300);

// BUG YANG DITEMUKAN & DIPERBAIKI — ketiga filter status/fulfillment/
// penjual sebelumnya memakai BaseSelect TANPA `v-model`/`:model-value`
// (hanya mendengarkan @update:model-value), jadi label yang tertampil di
// tombolnya SELALU menampilkan placeholder ("All statuses"/dst.) meski
// filter sudah benar-benar aktif di baliknya — ditemukan lewat verifikasi
// browser sungguhan (memilih satu penjual memang menyaring daftar, tapi
// dropdown-nya tetap terlihat menunjukkan "All sellers"). Diganti ke
// BaseMultiSelect (pola yang sama seperti artist_id[]/category_id[] di
// ProductsView, 005-ux-enhancements-dashboard) sekaligus memenuhi
// permintaan "bisa filter semua dan lebih dari satu" — array kosong
// berarti "Semua", dan ketiga filter ini + pencarian tetap DI-AND-kan satu
// sama lain di backend (PreorderController::applyFilters()), sementara
// beberapa nilai dalam satu filter yang sama di-OR-kan (whereIn).
const statusFilter = ref([]);
const fulfillmentFilter = ref([]);
const artistFilter = ref([]);
const dispatchFilter = ref([]);
const statusOptions = computed(() => Object.entries(STATUS_LABEL.value).map(([value, label]) => ({ value, label })));
const dispatchOptions = computed(() => Object.entries(DISPATCH_LABEL.value).map(([value, label]) => ({ value, label })));
const fulfillmentOptions = computed(() => Object.entries(FULFILLMENT_LABEL.value).map(([value, label]) => ({ value, label })));
const sellerOptions = computed(() => artists.value.map((a) => ({ value: a.id, label: a.name })));

function applyPreorderFilters() {
  applyFilter({
    status: statusFilter.value.length ? statusFilter.value : undefined,
    fulfillment: fulfillmentFilter.value.length ? fulfillmentFilter.value : undefined,
    artist_id: artistFilter.value.length ? artistFilter.value : undefined,
    dispatch_status: dispatchFilter.value.length ? dispatchFilter.value : undefined,
  });
}

// Requested: clicking a customer's name in the list opens their full
// contact/address info — the list row itself only carries customer_name
// (kept thin for pagination payload size), so this fetches the one
// customer on demand via the new GET /customers/{id}.
const showCustomerInfo = ref(false);
const customerInfoLoading = ref(false);
const customerInfo = ref(null);
async function openCustomerInfo(customerId) {
  if (!customerId) return;
  showCustomerInfo.value = true;
  customerInfoLoading.value = true;
  customerInfo.value = null;
  try {
    customerInfo.value = await getCustomer(customerId);
  } catch (err) {
    toast.error(err.message || t('preorders.load_failed'));
    showCustomerInfo.value = false;
  } finally {
    customerInfoLoading.value = false;
  }
}

const columns = computed(() => [
  { key: 'select', label: '' },
  { key: 'preorder_number', label: t('preorders.col_number'), sortable: true },
  { key: 'customer_name', label: t('preorders.col_customer'), sortable: true },
  // 'sellers' has no single sortable value (a preorder can have several
  // sellers) — left without `sortable` on purpose, see
  // PreorderController::applySort()'s docblock.
  { key: 'sellers', label: t('preorders.col_seller') },
  { key: 'status', label: t('preorders.col_status'), sortable: true },
  { key: 'dispatch_status', label: t('preorders.col_dispatch_status') },
  { key: 'fulfillment', label: t('preorders.col_fulfillment'), sortable: true },
  { key: 'total_amount', label: t('preorders.col_total'), sortable: true },
  { key: 'outstanding', label: t('preorders.col_outstanding'), sortable: true },
  { key: 'created_at', label: t('preorders.col_created'), sortable: true },
  { key: 'updated_at', label: t('preorders.col_updated'), sortable: true },
  { key: 'actions', label: t('preorders.col_actions') },
]);

// Column sort state — mirrored into the same `sort_by`/`sort_dir` query
// params the backend's applySort() whitelist reads (PreorderController).
const sortBy = ref(null);
const sortDir = ref('asc');
function handleSort({ key, dir }) {
  sortBy.value = key;
  sortDir.value = dir;
  applyFilter({ sort_by: key, sort_dir: dir });
}

// Requested: automatically flag a Mail Order pre-order that's missing its
// shipping cost or the customer's address — both matter only once
// fulfillment is 'courier' (Self pickup never needs either), and both are
// realistic gaps: shipping_cost defaults to 0 unless set explicitly, and
// a customer created as a walk-in may have no address on file at all.
function needsShippingAttention(row) {
  return row.fulfillment === 'courier' && (parseMoney(row.shipping_cost) <= 0 || !row.customer_has_address);
}
function preorderRowClass(row) {
  return needsShippingAttention(row) ? 'bg-warn-bg' : '';
}

// Requested: clicking the flag shows the shipping cost + notes right
// there, instead of forcing a trip into the full detail drawer just to
// see whether the gap is real or already explained (e.g. staff sometimes
// write the actual address into notes as a workaround).
const showShippingFlagInfo = ref(false);
const shippingFlagInfoRow = ref(null);
function openShippingFlagInfo(row) {
  shippingFlagInfoRow.value = row;
  showShippingFlagInfo.value = true;
}

// --- Create form (no mockup reference — designed fresh) ----------------
const showCreate = ref(false);
// 022-preorder-invoice-crud-overhaul (US1) — null berarti mode "buat
// baru"; berisi id berarti form yang SAMA persis sedang dipakai untuk
// mengedit preorder tsb (submitCreate() bercabang berdasar ini).
const editingPreorderId = ref(null);
const createCustomer = ref(null);
const createFulfillment = ref('pickup');
const createShippingCost = ref('0');
const createDiscount = ref('0');
const createEventId = ref('');
const createPickupDay = ref('');
const createCourierName = ref(COURIER_DEFAULT);
const createExpectedDate = ref('');
const createNotes = ref('');
const createItems = ref([]);
const createSearch = ref('');
const createResults = ref([]);
const creating = ref(false);
const createErrors = reactive({});

// 024-invoice-layout-shipping-slip — "Add item" sekarang berperilaku sama
// dengan CustomerSearchDropdown.vue: klik/fokus lapangan langsung
// menampilkan daftar produk yang bisa dijelajahi (lookupVariants('') kini
// mengembalikan halaman default, bukan kosong), lalu mengetik menyaring.
const runCreateSearch = useDebouncedFn(async () => {
  createResults.value = (await lookupVariants(createSearch.value.trim(), 8)).data;
}, 300);
// `setTimeout` bukan bagian dari daftar global yang diizinkan compiler
// template Vue (beda dari Math/Date/dst.) — dipanggil dari method di sini,
// bukan langsung sebagai ekspresi inline di template.
function closeCreateResultsSoon() {
  setTimeout(() => { createResults.value = []; }, 150);
}

function openCreate() {
  editingPreorderId.value = null;
  createCustomer.value = null;
  createFulfillment.value = 'pickup';
  createShippingCost.value = '0';
  createDiscount.value = '0';
  createEventId.value = '';
  createPickupDay.value = '';
  createCourierName.value = COURIER_DEFAULT;
  createExpectedDate.value = '';
  createNotes.value = '';
  createItems.value = [];
  createSearch.value = '';
  createResults.value = [];
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  showCreate.value = true;
}

// 022-preorder-invoice-crud-overhaul (US1, FR-001) — form YANG SAMA
// dengan "buat baru" di atas, hanya diisi ulang dari data preorder yang
// sudah ada dan disubmit lewat updatePreorder() (lihat submitCreate()).
// Tidak ada form terpisah untuk edit — item/diskon/fulfillment/dst semua
// field yang sama persis yang sudah ada di form ini.
async function openEdit(row) {
  const full = await getPreorder(row.id);
  editingPreorderId.value = full.id;
  createCustomer.value = full.customer ?? { id: full.customer_id ?? null, name: row.customer_name };
  createFulfillment.value = full.fulfillment;
  createShippingCost.value = String(full.shipping_cost ?? '0');
  createDiscount.value = String(full.discount ?? '0');
  createEventId.value = full.event_id ?? '';
  createPickupDay.value = full.pickup_day ?? '';
  createCourierName.value = full.courier_name ?? COURIER_DEFAULT;
  createExpectedDate.value = full.expected_date ?? '';
  createNotes.value = full.notes ?? '';
  createItems.value = (full.items ?? []).map((i) => ({
    // `id` marks this as a genuinely EXISTING preorder_items row — the
    // backend preserves its locked-in price snapshot when it sees this id
    // come back unchanged. Deleting the row and re-adding the same variant
    // via "Add item" (below) produces a row with NO id, so the backend
    // re-snapshots the variant's CURRENT price instead — this is the fix
    // for a stale/wrong line price that couldn't otherwise be refreshed.
    id: i.id, variant_id: i.variant_id, sku: i.sku_snapshot, label: i.name_snapshot, sell_price: i.sell_price, qty: i.qty, image_url: i.image_url ?? null, category_name: i.category_name ?? null,
  }));
  createSearch.value = '';
  createResults.value = [];
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  showCreate.value = true;
}

function addCreateItem(variant) {
  const existing = createItems.value.find((i) => i.variant_id === variant.variant_id);
  if (existing) existing.qty += 1;
  // No `id` here on purpose — this is what tells the backend "re-snapshot
  // this variant's current price", even if it's the same variant as a row
  // just deleted from this same form (see the mapping above).
  else createItems.value.push({ id: null, variant_id: variant.variant_id, sku: variant.sku, label: variant.label, sell_price: variant.sell_price, qty: 1, image_url: variant.image_url ?? null, category_name: variant.category_name ?? null });
  createSearch.value = '';
  createResults.value = [];
}

function bumpCreateItem(item, step) {
  item.qty = Math.max(1, item.qty + step);
}

// 021-preorder-form-updates (US1, FR-006) — entri langsung sebagai
// pelengkap stepper +/- yang sudah ada, bukan penggantinya. Minimum 1
// tetap dijaga di sini (bukan cuma di backend) supaya UI tidak sempat
// menampilkan 0/negatif sebelum request dikirim.
function setCreateItemQty(item, rawValue) {
  const parsed = parseInt(rawValue, 10);
  item.qty = Number.isFinite(parsed) && parsed >= 1 ? parsed : 1;
}

function removeCreateItem(idx) {
  createItems.value.splice(idx, 1);
}

// 021-preorder-form-updates (US3, research.md Decision 2) — pilihan hari
// jemput diturunkan dari rentang tanggal ASLI event yang dipilih, bukan
// label generik "Day 1"/"Day 2" tetap. Tidak ada apa pun untuk dipilih
// kalau belum ada event terpilih (FR-008a).
const createSelectedEvent = computed(() => events.value.find((e) => e.id === createEventId.value) ?? null);
const createPickupDayOptions = computed(() => {
  const event = createSelectedEvent.value;
  if (!event) return [];
  const options = [];
  let cursor = new Date(event.start_date);
  const end = new Date(event.end_date);
  let dayNumber = 1;
  while (cursor <= end) {
    const iso = cursor.toISOString().slice(0, 10);
    options.push({ value: iso, label: `${t('preorders.pickup_day_option', { day: dayNumber })} (${formatDate(iso)})` });
    cursor.setDate(cursor.getDate() + 1);
    dayNumber++;
  }
  return options;
});

// FR-011 — beralih fulfillment membersihkan field yang tidak lagi
// relevan, dan mengganti event/hilangnya event membersihkan hari jemput
// yang sudah tidak valid (FR-009a versi form; versi backend untuk
// preorder yang SUDAH tersimpan ada di T026/EventController).
watch(createFulfillment, (mode) => {
  if (mode === 'pickup') createCourierName.value = COURIER_DEFAULT;
  else createPickupDay.value = '';
});
watch(createEventId, () => {
  if (!createPickupDayOptions.value.some((o) => o.value === createPickupDay.value)) {
    createPickupDay.value = '';
  }
});

const createSubtotal = computed(() => createItems.value.reduce((sum, i) => sum + parseMoney(i.sell_price) * i.qty, 0));
// 021-preorder-form-updates (US2) — diskon dikurangkan di sisi tampilan
// juga, murni untuk pratinjau langsung; server tetap yang menghitung
// ulang dan memvalidasi nilai final (Constitution IV).
const createTotal = computed(() => Math.max(0, createSubtotal.value + (createFulfillment.value === 'courier' ? Number(createShippingCost.value) || 0 : 0) - (Number(createDiscount.value) || 0)));
const canSubmitCreate = computed(() => !!createCustomer.value && createItems.value.length > 0);

async function submitCreate() {
  if (!canSubmitCreate.value) return;
  creating.value = true;
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  const payload = {
    customer_id: createCustomer.value.id,
    event_id: createEventId.value || null,
    fulfillment: createFulfillment.value,
    shipping_cost: createFulfillment.value === 'courier' ? toMoneyString(createShippingCost.value) : undefined,
    discount: toMoneyString(createDiscount.value),
    pickup_day: createFulfillment.value === 'pickup' ? (createPickupDay.value || null) : null,
    courier_name: createFulfillment.value === 'courier' ? createCourierName.value : null,
    expected_date: createExpectedDate.value || null,
    notes: createNotes.value || null,
    items: createItems.value.map((i) => ({ id: i.id ?? null, variant_id: i.variant_id, qty: i.qty })),
  };
  try {
    if (editingPreorderId.value) {
      await updatePreorder(editingPreorderId.value, payload);
      toast.success(t('preorders.preorder_updated'));
      if (detail.value?.id === editingPreorderId.value) await openDetail({ id: editingPreorderId.value, customer_name: createCustomer.value.name, fulfillment: payload.fulfillment });
    } else {
      await createPreorder(payload);
      toast.success(t('preorders.preorder_created'));
    }
    showCreate.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(createErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    creating.value = false;
  }
}

// 022-preorder-invoice-crud-overhaul (US1, FR-003/FR-004) — hanya
// dipanggil untuk baris berstatus "ordered" (tombol Hapus sendiri sudah
// disembunyikan untuk status lain — lihat cell-actions), tapi 409 dari
// server tetap ditangani (mis. race condition status berubah di tab lain).
const showDeleteConfirm = ref(false);
const deleteTarget = ref(null);
const deletingPreorder = ref(false);

function confirmDeletePreorder(row) {
  deleteTarget.value = row;
  showDeleteConfirm.value = true;
}

async function performDeletePreorder() {
  deletingPreorder.value = true;
  try {
    await deletePreorder(deleteTarget.value.id);
    toast.success(t('preorders.preorder_deleted'));
    showDeleteConfirm.value = false;
    await load();
  } catch {
    // 409 (status bukan "ordered" / sudah ada pembayaran) sudah ditoast
    // oleh interceptor global — dialog tetap terbuka supaya pesan itu
    // terlihat di belakangnya.
  } finally {
    deletingPreorder.value = false;
  }
}

// --- Duplikat (027-preorder-duplicate-split) -------------------------------
// Satu endpoint untuk satu maupun banyak pre-order; server SELALU membalas 200
// dengan laporan per-pre-order, jadi kegagalan satu baris dibaca dari `status`,
// bukan dari kode HTTP. Harga salinan diambil dari harga varian SAAT INI.
const duplicating = ref(false);
const duplicateResults = ref([]);
const showDuplicateResults = ref(false);

async function doDuplicate(ids) {
  if (!ids.length || duplicating.value) return;
  duplicating.value = true;
  try {
    const { data: results } = await duplicatePreorders(ids);

    if (results.length === 1) {
      const [only] = results;
      if (only.status === 'created') {
        toast.success(t('preorders.duplicate_success', { number: only.preorder.preorder_number }));
        // Langsung buka salinannya supaya bisa ditinjau/diedit (spec US1 #3).
        await openDetailById(only.preorder.id);
      } else {
        toast.error(only.error || t('preorders.duplicate_failed'));
      }
    } else {
      // Banyak sekaligus: ringkasan per-pre-order (yang gagal tetap terlihat).
      duplicateResults.value = results;
      showDuplicateResults.value = true;
    }

    await load();
    loadSummary(); // kartu ringkasan harus ikut menghitung salinan baru (FR-024)
    return results;
  } catch (err) {
    toast.error(err.message || t('preorders.duplicate_failed'));
  } finally {
    duplicating.value = false;
  }
}

// --- Hapus pembayaran --------------------------------------------------------
// Owner/admin saja dan hanya selama pre-order belum diserahkan/dibatalkan;
// server menegakkan keduanya (403/409), ini cermin UX supaya tombolnya tak
// ditawarkan. Status dihitung ulang server dari pembayaran yang tersisa.
const canDeletePayments = computed(
  () => isOwnerOrAdmin.value && !!detail.value && !['handed_over', 'cancelled'].includes(detail.value.status),
);
const paymentDeleteTarget = ref(null);
const showPaymentDeleteConfirm = ref(false);
const deletingPayment = ref(false);

function confirmDeletePayment(payment) {
  paymentDeleteTarget.value = payment;
  showPaymentDeleteConfirm.value = true;
}

async function performDeletePayment() {
  if (!detail.value || !paymentDeleteTarget.value) return;
  deletingPayment.value = true;
  try {
    const fresh = await deletePreorderPayment(detail.value.id, paymentDeleteTarget.value.id);
    detail.value = { ...detail.value, ...fresh };
    showPaymentDeleteConfirm.value = false;
    toast.success(t('preorders.payment_deleted', { status: STATUS_LABEL.value[fresh.status] ?? fresh.status }));
    await load();
    loadSummary();
  } catch {
    // 409 sudah ditoast interceptor global; dialog tetap terbuka.
  } finally {
    deletingPayment.value = false;
  }
}

// --- Pisah (027-preorder-duplicate-split US3) ------------------------------
// Baris list tidak membawa item, jadi dialog selalu dibuka dari payload detail.
const showSplit = ref(false);
const splitTarget = ref(null);

async function openSplit(id) {
  try {
    splitTarget.value = await getPreorder(id);
    showSplit.value = true;
  } catch (err) {
    toast.error(err.message || t('preorders.load_failed'));
  }
}

async function onSplitDone(result) {
  showSplit.value = false;
  const numbers = result.created.map((c) => c.preorder_number).join(', ');
  toast.success(t('preorders.split_success', { number: numbers }));
  // Bila panel detail sedang menampilkan pesanan ini, segarkan supaya daftar
  // "Dipisah menjadi" muncul.
  if (showDetail.value && detail.value?.id === result.original.id) await refreshDetail();
  await load();
  loadSummary();
}

async function doDuplicateSelected() {
  const ids = [...selectedIds.value];
  const results = await doDuplicate(ids);
  if (results) selectedIds.value = new Set();
}

async function openDuplicateResult(id) {
  showDuplicateResults.value = false;
  await openDetailById(id);
}

// --- Detail drawer -------------------------------------------------------
const showDetail = ref(false);
const detail = ref(null);
const detailLoading = ref(false);
const transitioning = ref(false);
const showCancelForm = ref(false);
const cancelReason = ref('');
const showRecordPayment = ref(false);

const showShipmentForm = ref(false);
const shipment = ref(null);
const shipmentForm = reactive({
  courier_name: '',
  tracking_number: '',
  recipient_name: '',
  recipient_phone: '',
  address_line: '',
  province: '',
  notes: '',
});
const savingShipment = ref(false);

// --- Print invoice/receipt (US2) -----------------------------------------
const showInvoiceModal = ref(false);
const invoicePreorderId = ref(null);

// 010-split-payment-preorder-reports (US4, T020) — struk pembayaran per
// baris riwayat, terpisah dari invoice pesanan di atas (research.md R5).
const showPaymentReceiptModal = ref(false);
const receiptPreorderId = ref(null);
const receiptPaymentId = ref(null);
// 024-invoice-layout-shipping-slip (US7) — template tersembunyi untuk
// rendering surat jalan sebagai PDF via html2canvas + jsPDF.
const shippingSlipEl = ref(null);

// 024-invoice-layout-shipping-slip — bukti pembayaran (jika ada) dibuka di
// lightbox yang sama dengan yang dipakai QR pembayaran di tempat lain.
const proofLightboxSrc = ref(null);
const loadingProofId = ref(null);
async function viewPaymentProof(proofId) {
  loadingProofId.value = proofId;
  try {
    proofLightboxSrc.value = await getPaymentProofBlobUrl(proofId);
  } catch (err) {
    toast.error(err.message || t('preorders.proof_load_failed'));
  } finally {
    loadingProofId.value = null;
  }
}
function closeProofLightbox() {
  if (proofLightboxSrc.value) URL.revokeObjectURL(proofLightboxSrc.value);
  proofLightboxSrc.value = null;
}

// Variant thumbnails (add-item search results, added-item rows, and the
// detail drawer's ordered-items list) — a separate lightbox instance from
// proofLightboxSrc above since that one owns an object URL it must revoke.
const itemLightboxSrc = ref(null);
const itemLightboxAlt = ref('');
function openItemLightbox(item) {
  if (!item.image_url) return;
  itemLightboxSrc.value = item.image_url;
  itemLightboxAlt.value = item.label ?? item.name_snapshot;
}

// `preorderId` default ke detail yang sedang terbuka; baris list memberi
// id-nya sendiri (dan paymentId = null → modal memakai pembayaran terakhir).
function openPaymentReceipt(paymentId, preorderId = detail.value?.id) {
  if (!preorderId) return;
  receiptPreorderId.value = preorderId;
  receiptPaymentId.value = paymentId;
  showPaymentReceiptModal.value = true;
}

function openInvoice(row) {
  invoicePreorderId.value = row.id;
  showInvoiceModal.value = true;
}

// Aksi per baris list (dirender PreorderRowActions.vue). Edit/Delete
// disembunyikan — bukan dinonaktifkan — saat pasti ditolak server (aturan
// 022 FR-001a/FR-003/FR-004, lihat komentar di handler masing-masing);
// Invoice pembayaran dinonaktifkan selama belum ada pembayaran.
function rowActions(row) {
  const noPayment = parseMoney(row.paid_amount) <= 0;
  const actions = [
    { key: 'invoice', label: t('preorders.print_action') },
    {
      key: 'payment_invoice',
      label: t('preorders.print_payment_receipt'),
      disabled: noPayment,
      title: noPayment ? t('preorders.print_payment_invoice_disabled') : '',
    },
  ];
  if (!['handed_over', 'cancelled'].includes(row.status)) actions.push({ key: 'edit', label: t('common.edit') });
  if (['ordered', 'cancelled'].includes(row.status)) actions.push({ key: 'delete', label: t('common.delete'), danger: true });
  actions.push({ key: 'duplicate', label: t('preorders.duplicate') });
  // 027 — Split disembunyikan untuk status tertutup (pasti ditolak server) tetapi
  // DINONAKTIFKAN dengan alasan bila sudah ada pembayaran, seperti "Invoice pembayaran".
  if (!['handed_over', 'cancelled'].includes(row.status)) {
    const paid = !!row.has_payments || parseMoney(row.paid_amount) > 0;
    actions.push({
      key: 'split',
      label: t('preorders.split'),
      disabled: paid,
      title: paid ? t('preorders.split_disabled_has_payment') : '',
    });
  }
  actions.push({ key: 'detail', label: t('preorders.detail') });
  return actions;
}

function onRowAction(row, key) {
  if (key === 'invoice') openInvoice(row);
  else if (key === 'payment_invoice') openPaymentReceipt(null, row.id);
  else if (key === 'edit') openEdit(row);
  else if (key === 'delete') confirmDeletePreorder(row);
  else if (key === 'duplicate') doDuplicate([row.id]);
  else if (key === 'split') openSplit(row.id);
  else if (key === 'detail') openDetail(row);
}

// Dropdown "Cetak" di panel detail. Invoice pembayaran menunjuk pembayaran
// TERAKHIR (modalnya sendiri juga jatuh ke pembayaran terakhir bila id tak
// ada); tiap pembayaran lama tetap punya tombol cetaknya di riwayat pembayaran.
const lastPaymentId = computed(() => {
  const payments = detail.value?.payments ?? [];
  return payments.length ? payments[payments.length - 1].id : null;
});

// Penanda manual invoice-terkirim / pengiriman-berjalan (panel detail).
const savingDispatch = ref(false);
async function setDispatchStatus(next) {
  if (!detail.value || savingDispatch.value || detail.value.dispatch_status === next) return;
  savingDispatch.value = true;
  try {
    const updated = await updatePreorderDispatchStatus(detail.value.id, next);
    detail.value = { ...detail.value, dispatch_status: updated.dispatch_status };
    toast.success(t('preorders.dispatch_status_updated'));
    await load(); // kolom & filter di list ikut segar
  } catch (err) {
    toast.error(err.message || t('preorders.dispatch_status_update_failed'));
  } finally {
    savingDispatch.value = false;
  }
}

// --- Bulk download/email (022-preorder-invoice-crud-overhaul US5) ---------
const selectedIds = ref(new Set());
const bulkDocumentType = ref('invoice');
const bulkDownloading = ref(false);
const bulkEmailing = ref(false);

function toggleSelected(id) {
  const next = new Set(selectedIds.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  selectedIds.value = next;
}

// 029-fix-bulk-invoice-logo — unduh massal merender KOMPONEN yang sama dengan
// modalnya (PreorderInvoiceDocument / PreorderPaymentDocument), jadi PDF-nya
// identik dengan dokumen di layar (logo/identitas toko, header dua kolom,
// kartu "Cara pembayaran"). appContext diteruskan agar vue-i18n tersedia di
// render terpisah ini. Lebar = lebar dokumen di modal 720px: invoice berada
// di dalam padding px-6 (672px), payment invoice membawa paddingnya sendiri.
const appContext = getCurrentInstance().appContext;
const BULK_DOCUMENTS = {
  invoice: { component: PreorderInvoiceDocument, width: 672 },
  payment_invoice: { component: PreorderPaymentDocument, width: 720 },
};
function mountBulkDocument(invoice, documentType) {
  const { component, width } = BULK_DOCUMENTS[documentType];
  const container = document.createElement('div');
  container.style.position = 'fixed';
  container.style.left = '-9999px';
  container.style.width = `${width}px`;
  document.body.appendChild(container);
  const vnode = h(component, { invoice });
  vnode.appContext = appContext;
  render(vnode, container);
  return { container, dispose: () => { render(null, container); container.remove(); } };
}

async function doBulkDownload() {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  bulkDownloading.value = true;
  try {
    const { data: invoices } = await bulkPreorderInvoices(ids, bulkDocumentType.value);
    const [{ default: JSZip }, { jsPDF }] = await Promise.all([
      import('jszip'), import('jspdf'),
    ]);
    const zip = new JSZip();

    // Sequential, not parallel — rendering N canvases at once would freeze
    // the tab for a realistic batch size (plan.md Performance Goals).
    for (const invoice of invoices) {
      const { container, dispose } = mountBulkDocument(invoice, bulkDocumentType.value);
      let canvas;
      try {
        canvas = await captureElementCanvas(container);
      } finally {
        dispose();
      }

      const imgData = canvas.toDataURL('image/png');
      const widthPt = (canvas.width * 72) / 96;
      const heightPt = (canvas.height * 72) / 96;
      const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
      pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt);
      zip.file(`${bulkDocumentType.value}-${invoice.preorder_number}.pdf`, pdf.output('blob'));
    }

    const zipBlob = await zip.generateAsync({ type: 'blob' });
    const url = URL.createObjectURL(zipBlob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `preorder-${bulkDocumentType.value}s.zip`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (err) {
    toast.error(err.message || t('preorders.bulk_download_failed'));
  } finally {
    bulkDownloading.value = false;
  }
}

async function doBulkEmail() {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  bulkEmailing.value = true;
  try {
    const { data: results } = await bulkEmailPreorderInvoices(ids, bulkDocumentType.value);
    const sentCount = results.filter((r) => r.status === 'sent').length;
    const skippedCount = results.length - sentCount;
    toast.success(t('preorders.bulk_email_summary', { sent: sentCount, skipped: skippedCount }));
  } catch (err) {
    toast.error(err.message || t('preorders.bulk_email_failed'));
  } finally {
    bulkEmailing.value = false;
  }
}

// 024-invoice-layout-shipping-slip (US7) — bulk unduh surat jalan (ZIP).
async function doBulkShippingSlips() {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  bulkDownloading.value = true;
  try {
    const { data: invoices } = await bulkPreorderInvoices(ids, 'invoice');
    const [{ default: JSZip }, { jsPDF }] = await Promise.all([
      import('jszip'), import('jspdf'),
    ]);
    const zip = new JSZip();
    for (const invoice of invoices) {
      const container = document.createElement('div');
      container.style.position = 'fixed';
      container.style.left = '-9999px';
      container.innerHTML = buildShippingSlipHtml(invoice);
      document.body.appendChild(container);
      const canvas = await captureElementCanvas(container);
      document.body.removeChild(container);

      const imgData = canvas.toDataURL('image/png');
      const widthPt = (canvas.width * 72) / 96;
      const heightPt = (canvas.height * 72) / 96;
      const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
      pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt);
      zip.file(`surat-jalan-${invoice.preorder_number}.pdf`, pdf.output('blob'));
    }

    const zipBlob = await zip.generateAsync({ type: 'blob' });
    const url = URL.createObjectURL(zipBlob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'surat-jalan.zip';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (err) {
    toast.error(err.message || t('preorders.bulk_download_failed'));
  } finally {
    bulkDownloading.value = false;
  }
}

// --- Export/import (US3) --------------------------------------------------
const exporting = ref(false);
const importFileInput = ref(null);
const importing = ref(false);

async function doExportPreorders() {
  exporting.value = true;
  try {
    // 007-preorder-import-export-notify (US3, Acceptance Scenario 1) —
    // ekspor menghormati filter yang sedang aktif, dibaca langsung dari
    // params reaktif usePaginatedList (satu-satunya sumber "apa yang
    // sedang aktif" saat ini, bukan disalin ke ref terpisah).
    const blob = await exportPreorders({ ...params });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'preorders.xlsx';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (err) {
    toast.error(err.message || t('preorders.export_failed'));
  } finally {
    exporting.value = false;
  }
}

async function doDownloadImportTemplate() {
  const blob = await downloadPreorderImportTemplate();
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'template-preorders.xlsx';
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function triggerImportFile() {
  importFileInput.value?.click();
}

async function onImportFileSelected(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  importing.value = true;
  try {
    const result = await importPreorders(file);
    toast.success(t('preorders.import_success', { count: result.created_count }));
    await load();
  } catch (err) {
    if (err.status === 409 && err.data?.row_errors) {
      const rowErrors = err.data.row_errors;
      const first = rowErrors[0];
      const suffix = rowErrors.length > 1 ? t('preorders.import_more_rows_failed', { count: rowErrors.length - 1 }) : '';
      toast.error(t('preorders.import_row_error', { row: first.row, error: first.errors[0] }) + suffix, { timeout: 12000 });
    } else {
      toast.error(err.message || t('preorders.import_failed'));
    }
  } finally {
    importing.value = false;
  }
}

// --- Notification resend (US4) --------------------------------------------
const resendingNotification = ref(false);

async function doResendNotification() {
  if (!detail.value) return;
  resendingNotification.value = true;
  try {
    const result = await resendPreorderNotification(detail.value.id);
    detail.value.latest_notification = { trigger: 'manual_resend', status: result.status, sent_at: result.sent_at, error_message: null };
    if (result.status === 'sent') toast.success(t('preorders.notification_resent'));
    else toast.warning(t(`preorders.notification_status_${result.status}`));
  } catch (err) {
    toast.error(err.message || t('preorders.notification_resend_failed'));
  } finally {
    resendingNotification.value = false;
  }
}

async function openDetail(row) {
  showDetail.value = true;
  detailLoading.value = true;
  shipment.value = null;
  showShipmentForm.value = false;
  try {
    const full = await getPreorder(row.id);
    // customer_name masih dibawa dari baris list yang sudah dimuat, bukan
    // dari respons ini (lihat openDetailById di bawah untuk yang itu).
    detail.value = { ...full, customer_name: row.customer_name, fulfillment: full.fulfillment ?? row.fulfillment };
    // BUG YANG DITEMUKAN & DIPERBAIKI — `shipment` di atas selalu
    // dipaksa null dan TIDAK PERNAH diisi dari `full.shipment` (yang
    // present() sudah kembalikan sejak lama), jadi data pengiriman yang
    // sudah tersimpan tidak pernah tampil, layar selalu menunjukkan
    // "Belum ada data pengiriman" walau shipment sungguhan sudah ada di
    // database — ditemukan lewat verifikasi browser sungguhan.
    shipment.value = full.shipment ?? null;
  } finally {
    detailLoading.value = false;
  }
}

// 009-ui-ux-refinements US5 (T043) — deep-link entry point from
// CustomerTransactionsModal.vue (CustomersView.vue), which only has a
// preorder id, not a list row. Unlike openDetail() above, GET
// /preorders/{id} (show()) DOES eager-load 'customer' (see
// PreorderController::show()), so customer_name/fulfillment come from the
// same response instead of a list row.
async function openDetailById(id) {
  showDetail.value = true;
  detailLoading.value = true;
  shipment.value = null;
  showShipmentForm.value = false;
  try {
    const full = await getPreorder(id);
    detail.value = { ...full, customer_name: full.customer?.name ?? '', fulfillment: full.fulfillment };
    shipment.value = full.shipment ?? null;
  } catch (err) {
    showDetail.value = false;
    toast.error(err.message || t('preorders.load_failed'));
  } finally {
    detailLoading.value = false;
  }
}

async function refreshDetail() {
  const full = await getPreorder(detail.value.id);
  detail.value = { ...detail.value, ...full };
}

const detailLines = computed(() =>
  (detail.value?.items ?? []).map((i) => ({ ...i, line_total: (parseMoney(i.sell_price) * i.qty).toFixed(2) }))
);

async function markArrived() {
  transitioning.value = true;
  try {
    await updatePreorderStatus(detail.value.id, 'arrived');
    toast.success(t('preorders.arrived_marked'));
    await Promise.all([refreshDetail(), load()]);
  } catch {
    // 409 (lompatan status tidak valid) sudah ditoast global.
  } finally {
    transitioning.value = false;
  }
}

async function markHandedOver() {
  transitioning.value = true;
  try {
    await updatePreorderStatus(detail.value.id, 'handed_over');
    toast.success(t('preorders.handed_over_marked'));
    await Promise.all([refreshDetail(), load()]);
  } catch {
    // 409 (belum lunas) sudah ditoast global dengan pesan servernya.
  } finally {
    transitioning.value = false;
  }
}

async function submitCancel() {
  transitioning.value = true;
  try {
    await updatePreorderStatus(detail.value.id, 'cancelled', cancelReason.value || null);
    toast.success(t('preorders.preorder_cancelled'));
    showCancelForm.value = false;
    cancelReason.value = '';
    await Promise.all([refreshDetail(), load()]);
  } catch {
    // already toasted globally
  } finally {
    transitioning.value = false;
  }
}

const paymentPurpose = computed(() => (detail.value?.status === 'arrived' ? 'settlement' : 'down_payment'));

// 028-partial-split-payment — AddPaymentModal menyimpan SATU pembayaran per klik
// lewat POST /preorders/{id}/payments dan mengembalikan pre-order terbaru; kita
// pakai itu langsung (ringkasan + riwayat segar) lalu menyegarkan list dan kartu
// ringkasan di atas tabel.
function submitPreorderPayment(payload) {
  return createPreorderPayment(detail.value.id, payload);
}

async function handlePaymentSaved(result) {
  showRecordPayment.value = false;
  if (result?.payment_summary) detail.value = { ...detail.value, ...result };
  else await refreshDetail();
  await load();
  loadSummary();
}

// Tombol Tambah pembayaran: hanya selama masih ada sisa tagihan DAN pre-order
// belum ditutup (server menolak dengan 409 untuk handed_over/cancelled).
const canAddPayment = computed(
  () => !!detail.value && !['handed_over', 'cancelled'].includes(detail.value.status)
    && parseMoney(detail.value.payment_summary?.remaining ?? detail.value.outstanding) > 0,
);

function openShipmentForm() {
  // 022-preorder-invoice-crud-overhaul (US2, FR-005) — nama/telepon/alamat
  // pra-isi dari data pelanggan yang sudah tercatat saat preorder dibuat;
  // tetap bisa diedit sebelum disimpan (mis. kirim ke alamat lain / hadiah).
  // Kosong (bukan nilai keliru) kalau data pelanggan itu sendiri belum
  // lengkap (Edge Cases spec.md), sama seperti staf mengisi manual untuk
  // pelanggan walk-in.
  const customer = detail.value?.customer;
  Object.assign(shipmentForm, {
    // 021-preorder-form-updates (US3, research.md Decision 1) — pra-isi
    // dari nilai DEFAULT yang dipilih saat preorder dibuat; shipment
    // sungguhan tetap kolom sendiri, bisa diubah bebas di sini.
    courier_name: detail.value?.courier_name || COURIER_DEFAULT,
    tracking_number: '',
    recipient_name: customer?.name || '',
    recipient_phone: customer?.phone || '',
    address_line: customer?.address || '',
    province: '',
    notes: '',
  });
  showShipmentForm.value = true;
}

async function saveShipment() {
  savingShipment.value = true;
  try {
    shipment.value = await createShipment(detail.value.id, {
      ...shipmentForm,
      shipping_cost: detail.value.shipping_cost,
    });
    showShipmentForm.value = false;
    toast.success(t('preorders.shipment_saved'));
  } catch (err) {
    if (err.isValidation) toast.error(Object.values(err.errors)[0]?.[0] ?? err.message);
    // 409 (bukan fulfillment kurir / sudah ada pengiriman) sudah ditoast global.
  } finally {
    savingShipment.value = false;
  }
}

async function markPacked() {
  shipment.value = await updateShipment(shipment.value.id, { status: 'packed' });
  toast.success(t('preorders.marked_packed'));
}

async function saveTrackingAndShip() {
  if (!shipment.value.tracking_number) {
    toast.warning(t('preorders.fill_tracking_number_first'));
    return;
  }
  shipment.value = await updateShipment(shipment.value.id, { tracking_number: shipment.value.tracking_number, status: 'shipped' });
  toast.success(t('preorders.tracking_saved_shipped'));
}

async function markDelivered() {
  shipment.value = await updateShipment(shipment.value.id, { status: 'delivered' });
  toast.success(t('preorders.marked_delivered'));
}

// 024-invoice-layout-shipping-slip (US7) — unduh surat jalan (PDF)
// menggunakan html2canvas + jsPDF, format sama dengan dokumen lain.
async function downloadShippingSlip() {
  if (!detail.value || !shipment.value) return;
  try {
    await downloadElementAsPdf(shippingSlipEl.value, `surat-jalan-${detail.value.preorder_number}.pdf`);
    toast.success(t('preorders.shipping_slip_downloaded'));
  } catch {
    toast.error(t('preorders.shipping_slip_download_failed'));
  }
}

// 024-invoice-layout-shipping-slip (US6) — simpan perubahan shipment yang
// diedit langsung (courier_name, recipient, address, dll).
async function saveShipmentChanges() {
  if (!shipment.value) return;
  savingShipment.value = true;
  try {
    shipment.value = await updateShipment(shipment.value.id, {
      courier_name: shipment.value.courier_name,
      tracking_number: shipment.value.tracking_number,
      recipient_name: shipment.value.recipient_name,
      recipient_phone: shipment.value.recipient_phone,
      address_line: shipment.value.address_line,
      notes: shipment.value.notes,
    });
    toast.success(t('preorders.shipment_saved'));
  } catch (err) {
    toast.error(err.message || t('preorders.shipment_update_failed'));
  } finally {
    savingShipment.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <!-- 013-preorder-list-filters-receipt (US5, T026) — summary refetched
         through applyFilter()/onMounted alongside the list itself, using
         the same params, so it never disagrees with what's on screen. -->
    <div v-if="summary" class="flex flex-wrap items-stretch gap-2.5 rounded-card border border-line-2 bg-white p-4">
      <div class="flex min-w-[150px] flex-col gap-0.5 pr-4">
        <span class="text-[11.5px] text-muted-3">{{ t('preorders.summary_transaction_count') }}</span>
        <span class="text-[19px] font-extrabold tracking-tight">{{ summary.transaction_count }}</span>
      </div>
      <div class="flex flex-wrap items-center gap-1.5 border-l border-line-6 pl-4">
        <StatusPill v-for="s in summary.by_status" :key="s.status" :variant="STATUS_VARIANT[s.status]">
          {{ STATUS_LABEL[s.status] }} · {{ s.count }} · {{ formatIDR(s.total_amount) }}
        </StatusPill>
      </div>
      <span class="flex-1"></span>
      <div class="flex min-w-[150px] flex-col gap-0.5 border-l border-line-6 pl-4">
        <span class="text-[11.5px] text-muted-3">{{ t('preorders.summary_grand_total') }}</span>
        <span class="text-[19px] font-extrabold tracking-tight">{{ formatIDR(summary.grand_total) }}</span>
      </div>
      <div class="flex min-w-[150px] flex-col gap-0.5 border-l border-line-6 pl-4">
        <span class="text-[11.5px] text-muted-3">{{ t('preorders.summary_outstanding') }}</span>
        <span class="text-[19px] font-extrabold tracking-tight text-warn-text">{{ formatIDR(summary.total_outstanding) }}</span>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-2.5">
      <div class="relative flex min-w-[230px] items-center">
        <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[16px] text-muted-3" aria-hidden="true"></i>
        <label class="sr-only" for="preorder-customer-search">{{ t('preorders.search_customer_name') }}</label>
        <input
          id="preorder-customer-search"
          v-model="customerSearch"
          :placeholder="t('preorders.search_customer_name_placeholder')"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedCustomerSearch"
        />
      </div>
      <BaseMultiSelect
        v-model="statusFilter"
        class="w-48"
        :options="statusOptions"
        :all-label="t('preorders.all_status')"
        @update:model-value="applyPreorderFilters"
      />
      <BaseMultiSelect
        v-model="dispatchFilter"
        class="w-52"
        :options="dispatchOptions"
        :all-label="t('preorders.all_dispatch_status')"
        @update:model-value="applyPreorderFilters"
      />
      <BaseMultiSelect
        v-model="fulfillmentFilter"
        class="w-48"
        :options="fulfillmentOptions"
        :all-label="t('preorders.all_fulfillment')"
        @update:model-value="applyPreorderFilters"
      />
      <BaseMultiSelect
        v-model="artistFilter"
        class="w-48"
        :options="sellerOptions"
        :all-label="t('preorders.all_sellers')"
        @update:model-value="applyPreorderFilters"
      />
      <span class="flex-1"></span>
      <template v-if="isOwnerOrAdmin">
        <BaseButton variant="secondary" :loading="exporting" @click="doExportPreorders">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          {{ t('preorders.export_action') }}
        </BaseButton>
        <BaseButton variant="secondary" @click="triggerImportFile">
          <i class="ph-duotone ph-upload-simple text-[16px]" aria-hidden="true"></i>
          {{ t('preorders.import_action') }}
        </BaseButton>
        <button type="button" class="text-[12px] font-semibold text-muted-4 underline hover:text-brand-active" @click="doDownloadImportTemplate">
          {{ t('preorders.download_template_action') }}
        </button>
        <input ref="importFileInput" type="file" accept=".xlsx" class="hidden" @change="onImportFileSelected" />
      </template>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('preorders.new_preorder') }}
      </BaseButton>
    </div>

    <!-- 022-preorder-invoice-crud-overhaul (US5) — hanya muncul ketika ada
         baris terpilih (Edge Cases spec.md: bulk action tidak tersedia
         untuk 0 baris terpilih). -->
    <div v-if="selectedIds.size > 0" class="flex flex-wrap items-center gap-2.5 rounded-card border border-mint-border bg-mint-50 px-4 py-3">
      <span class="text-[12.5px] font-semibold text-brand-active">{{ t('preorders.selected_count', { count: selectedIds.size }) }}</span>
      <BaseSelect
        v-model="bulkDocumentType"
        class="w-48"
        :options="[{ value: 'invoice', label: t('preorders.document_invoice_title') }, { value: 'payment_invoice', label: t('preorders.payment_receipt_title') }]"
      />
      <span class="flex-1"></span>
      <BaseButton variant="secondary" size="sm" :loading="bulkDownloading" @click="doBulkDownload">
        <i class="ph-duotone ph-file-zip text-[15px]" aria-hidden="true"></i>
        {{ t('preorders.bulk_download_action') }}
      </BaseButton>
      <BaseButton variant="secondary" size="sm" :loading="bulkDownloading" @click="doBulkShippingSlips">
        <i class="ph-duotone ph-truck text-[15px]" aria-hidden="true"></i>
        {{ t('preorders.bulk_download_shipping_slip') }}
      </BaseButton>
      <BaseButton variant="secondary" size="sm" :loading="bulkEmailing" @click="doBulkEmail">
        <i class="ph-duotone ph-envelope-simple text-[15px]" aria-hidden="true"></i>
        {{ t('preorders.bulk_email_action') }}
      </BaseButton>
      <!-- 027 — salinan dibuat satu per pre-order terpilih (tidak digabung). -->
      <BaseButton variant="secondary" size="sm" :loading="duplicating" data-testid="bulk-duplicate" @click="doDuplicateSelected">
        <i class="ph-duotone ph-copy text-[15px]" aria-hidden="true"></i>
        {{ t('preorders.duplicate_selected') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable
        :columns="columns"
        :rows="items"
        :loading="loading"
        :empty-message="t('preorders.no_preorders')"
        :sort-key="sortBy"
        :sort-dir="sortDir"
        :row-class="preorderRowClass"
        @sort="handleSort"
      >
        <template #cell-select="{ row }">
          <input
            type="checkbox"
            class="h-4 w-4 rounded border-line-3"
            :checked="selectedIds.has(row.id)"
            :aria-label="t('preorders.select_row', { number: row.preorder_number })"
            @change="toggleSelected(row.id)"
          />
        </template>
        <template #cell-preorder_number="{ row }">
          <!-- 013-preorder-list-filters-receipt (US2, T019) — reuses the
               existing openDetail(row) handler (research.md R7), a second
               entry point into the row's already-existing "Detail" action. -->
          <span class="inline-flex items-center gap-1.5">
            <button
              v-if="needsShippingAttention(row)"
              type="button"
              class="flex h-5 w-5 items-center justify-center rounded hover:bg-warn-border"
              :title="t('preorders.missing_shipping_info_flag')"
              :aria-label="t('preorders.missing_shipping_info_flag')"
              @click="openShippingFlagInfo(row)"
            >
              <i class="ph-duotone ph-flag text-[14px] text-warn-text" aria-hidden="true"></i>
            </button>
            <button type="button" class="font-mono text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openDetail(row)">{{ row.preorder_number }}</button>
          </span>
        </template>
        <template #cell-customer_name="{ row }">
          <!-- Requested: clicking the customer's name opens their full
               contact/address info (name/phone/email/social/address/notes),
               fetched on demand since the list row only carries the name. -->
          <button type="button" class="text-left text-[13.5px] font-semibold text-muted-4 hover:text-brand-active hover:underline" @click="openCustomerInfo(row.customer_id)">{{ row.customer_name }}</button>
        </template>
        <template #cell-status="{ row }"><StatusPill :variant="STATUS_VARIANT[row.status]">{{ STATUS_LABEL[row.status] }}</StatusPill></template>
        <template #cell-dispatch_status="{ row }">
          <div class="flex flex-col items-start gap-1">
            <StatusPill :variant="DISPATCH_VARIANT[row.dispatch_status ?? 'pending']">{{ DISPATCH_LABEL[row.dispatch_status ?? 'pending'] }}</StatusPill>
            <!-- Tanggal ditandai, di kolom yang sama (bukan kolom baru). -->
            <span v-if="row.invoice_sent_at" class="text-[11px] leading-tight text-muted-3">{{ t('preorders.dispatch_invoice_sent_on', { date: formatDateTime(row.invoice_sent_at) }) }}</span>
            <span v-if="row.shipping_at" class="text-[11px] leading-tight text-muted-3">{{ t('preorders.dispatch_shipping_on', { date: formatDateTime(row.shipping_at) }) }}</span>
          </div>
        </template>
        <template #cell-fulfillment="{ row }">{{ FULFILLMENT_LABEL[row.fulfillment] }}</template>
        <template #cell-sellers="{ row }">{{ row.sellers?.length ? row.sellers.map((s) => s.name).join(', ') : '—' }}</template>
        <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
        <template #cell-outstanding="{ row }">{{ formatIDR(row.outstanding) }}</template>
        <template #cell-created_at="{ row }"><span class="whitespace-nowrap text-[12.5px] text-muted-4">{{ formatDateTime(row.created_at) }}</span></template>
        <template #cell-updated_at="{ row }"><span class="whitespace-nowrap text-[12.5px] text-muted-4">{{ formatDateTime(row.updated_at) }}</span></template>
        <template #cell-actions="{ row }">
          <!-- Sampai 3 aksi tampil sebagai tautan; lebih dari itu, Detail tetap
               terlihat dan sisanya masuk dropdown "Lainnya" (lihat
               PreorderRowActions.vue). Daftar aksi per baris: rowActions(). -->
          <PreorderRowActions :actions="rowActions(row)" @select="(key) => onRowAction(row, key)" />
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <!-- Create form — no mockup reference, designed fresh -->
    <BaseModal :open="showCreate" :title="editingPreorderId ? t('preorders.edit_preorder') : t('preorders.new_preorder')" max-width-class="max-w-[560px]" @close="showCreate = false">
      <div class="flex flex-col gap-4 px-6 py-5">
        <CustomerSearchDropdown v-model="createCustomer" />
        <p v-if="createErrors.customer_id" class="text-[12px] font-medium text-danger-text">{{ createErrors.customer_id }}</p>

        <!-- 021-preorder-form-updates (US3, research.md Decision 9) — form
             ini SEBELUMNYA tidak punya pemilih event sama sekali; hari
             jemput di bawah butuh event untuk menurunkan pilihan
             tanggalnya. -->
        <BaseSelect
          v-model="createEventId"
          :label="t('preorders.event_optional')"
          :options="eventOptions"
          :placeholder="t('preorders.no_event_selected')"
        />

        <div class="grid grid-cols-2 gap-2.5">
          <button
            type="button"
            class="rounded-lg border px-3.5 py-3 text-[13px] font-bold transition-colors"
            :class="createFulfillment === 'pickup' ? 'border-brand bg-mint-50 text-brand-active' : 'border-line text-muted-5'"
            @click="createFulfillment = 'pickup'"
          >
            {{ t('preorders.fulfillment_pickup') }}
          </button>
          <button
            type="button"
            class="rounded-lg border px-3.5 py-3 text-[13px] font-bold transition-colors"
            :class="createFulfillment === 'courier' ? 'border-brand bg-mint-50 text-brand-active' : 'border-line text-muted-5'"
            @click="createFulfillment = 'courier'"
          >
            {{ t('preorders.fulfillment_mail_order') }}
          </button>
        </div>

        <!-- 021-preorder-form-updates (US3) — hari jemput hanya muncul kalau
             fulfillment=pickup DAN event sudah dipilih (FR-008/FR-008a);
             tidak ada picker generik "Day 1/Day 2" tanpa event. -->
        <BaseSelect
          v-if="createFulfillment === 'pickup' && createSelectedEvent"
          v-model="createPickupDay"
          :label="t('preorders.pickup_day_label')"
          :options="createPickupDayOptions"
          :placeholder="t('preorders.select_pickup_day')"
        />
        <BaseInput v-if="createFulfillment === 'courier'" v-model="createShippingCost" type="number" min="0" :label="t('preorders.shipping_cost_rp')" />
        <!-- 021-preorder-form-updates (US3) — dropdown kurir, default JNE,
             hanya muncul untuk fulfillment=courier ("Mail Order"). -->
        <BaseSelect
          v-if="createFulfillment === 'courier'"
          v-model="createCourierName"
          :label="t('preorders.courier')"
          :options="COURIER_OPTIONS.map((c) => ({ value: c, label: c }))"
        />
        <BaseInput v-model="createDiscount" type="number" min="0" :label="t('preorders.discount_rp')" :error="createErrors.discount" />
        <BaseInput v-model="createExpectedDate" type="date" :label="t('preorders.eta_optional')" />

        <!-- Requested: a clear section label above the item rows, separate
             from the "Add item" search field below it. -->
        <span class="text-[13px] font-bold text-muted-5">{{ t('preorders.item_list_section') }}</span>
        <div v-for="(item, idx) in createItems" :key="item.variant_id" class="flex items-center gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3">
          <button
            v-if="item.image_url"
            type="button"
            class="h-10 w-10 flex-none cursor-zoom-in"
            :aria-label="t('master_data.enlarge_variant_image', { name: item.label })"
            @click="openItemLightbox(item)"
          >
            <img :src="item.image_url" :alt="item.label" class="h-10 w-10 rounded-md border border-line-2 object-cover" />
          </button>
          <!-- Requested: always show a thumbnail slot, not just when a real
               image exists — matches the "Add item" dropdown's own
               image-or-placeholder convention just below. -->
          <div v-else class="flex h-10 w-10 flex-none items-center justify-center rounded-md border border-line-2 bg-white text-muted-3">
            <i class="ph-duotone ph-image text-[16px]" aria-hidden="true"></i>
          </div>
          <div class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="text-[13px] font-semibold">{{ item.label }}<span v-if="item.category_name" class="font-normal text-muted-3"> · {{ item.category_name }}</span></span>
            <span class="flex items-baseline gap-1.5">
              <span class="font-mono text-[10.5px] text-muted-3">{{ item.sku }}</span>
              <span class="text-[13px] font-bold">{{ formatIDR(item.sell_price) }}</span>
            </span>
          </div>
          <div class="flex items-center gap-0.5 overflow-hidden rounded-lg border border-line bg-white">
            <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('preorders.decrease')" @click="bumpCreateItem(item, -1)"><i class="ph-duotone ph-minus text-[13px]" aria-hidden="true"></i></button>
            <input
              type="number"
              min="1"
              class="h-[30px] w-[44px] border-x border-line text-center text-[13px] font-bold outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
              :aria-label="t('preorders.qty_direct_entry', { name: item.label })"
              :value="item.qty"
              @change="(e) => setCreateItemQty(item, e.target.value)"
            />
            <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('preorders.increase')" @click="bumpCreateItem(item, 1)"><i class="ph-duotone ph-plus text-[13px]" aria-hidden="true"></i></button>
          </div>
          <button type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('preorders.delete_item', { name: item.label })" @click="removeCreateItem(idx)"><i class="ph-duotone ph-trash text-[13px]" aria-hidden="true"></i></button>
        </div>

        <!-- 024-invoice-layout-shipping-slip — "Add item" sekarang berperilaku
             sama dengan CustomerSearchDropdown.vue: fokus lapangan langsung
             menampilkan daftar produk yang bisa dijelajahi (bukan hanya
             setelah mengetik), lalu mengetik menyaring daftar itu. Ditutup
             lewat @blur (delay singkat supaya klik pada baris hasil sempat
             terdaftar sebelum panel hilang), meniru mekanisme click-outside
             CustomerSearchDropdown.vue tanpa perlu Teleport terpisah untuk
             penggunaan lokal ini. -->
        <div class="relative">
          <BaseInput
            v-model="createSearch"
            :label="t('preorders.add_item')"
            :placeholder="t('preorders.search_product_or_sku_placeholder')"
            @input="runCreateSearch"
            @focus="runCreateSearch"
            @blur="closeCreateResultsSoon"
          />
          <div v-if="createResults.length" class="absolute z-10 mt-1 max-h-[280px] w-full overflow-y-auto rounded-lg border border-line bg-white shadow-lg">
            <button v-for="v in createResults" :key="v.variant_id" type="button" class="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-left hover:bg-line-7" @mousedown.prevent="addCreateItem(v)">
              <img v-if="v.image_url" :src="v.image_url" :alt="v.label" class="h-9 w-9 flex-none rounded-md border border-line-2 object-cover" />
              <div v-else class="flex h-9 w-9 flex-none items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3">
                <i class="ph-duotone ph-image text-[14px]" aria-hidden="true"></i>
              </div>
              <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-[13px] font-semibold">{{ v.label }}<span v-if="v.category_name" class="font-normal text-muted-3"> · {{ v.category_name }}</span></span>
                <span class="flex items-baseline gap-1.5">
                  <span class="font-mono text-[11px] text-muted-3">{{ v.sku }}</span>
                  <span class="text-[13px] font-bold">{{ formatIDR(v.sell_price) }}</span>
                </span>
              </div>
            </button>
          </div>
        </div>

        <!-- Requested: when Mail Order is selected, show the customer's
             own stored address info right here so staff can see at a
             glance whether it's usable before even opening the shipment
             form later — pulled straight from the already-selected
             customer (CustomerSearchDropdown returns full CustomerResource
             fields), no extra request. -->
        <div v-if="createFulfillment === 'courier' && createCustomer" class="flex flex-col gap-1 rounded-lg border border-line-3 bg-surface-subtle p-3">
          <span class="text-[12px] font-bold text-muted-5">{{ t('preorders.customer_address_info') }}</span>
          <span class="text-[13px]">{{ createCustomer.address || t('preorders.address_not_filled') }}</span>
          <span v-if="createCustomer.phone" class="text-[12px] text-muted-3">{{ createCustomer.phone }}</span>
        </div>

        <BaseTextarea v-model="createNotes" :label="t('preorders.notes')" :rows="2" />

        <div class="flex items-baseline justify-between border-t border-dashed border-line-2 pt-3">
          <span class="text-[13.5px] font-bold">{{ t('preorders.estimated_total') }}</span>
          <span class="text-[20px] font-extrabold tracking-tight">{{ formatIDR(createTotal) }}</span>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showCreate = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :disabled="!canSubmitCreate" :loading="creating" @click="submitCreate">{{ editingPreorderId ? t('common.save') : t('preorders.save_preorder') }}</BaseButton>
        </div>
      </template>
    </BaseModal>
    <PreorderSplitModal :open="showSplit" :preorder="splitTarget" @close="showSplit = false" @done="onSplitDone" />
    <PreorderDuplicateResultModal
      :open="showDuplicateResults"
      :results="duplicateResults"
      @close="showDuplicateResults = false"
      @open="openDuplicateResult"
    />
    <!-- Detail drawer -->
    <BaseDrawer
      :open="showDetail"
      :title="detail?.preorder_number ?? ''"
      :subtitle="detail ? `${detail.customer_name} · ${FULFILLMENT_LABEL[detail.fulfillment]}` : ''"
      max-width-class="max-w-[880px]"
      @close="showDetail = false"
    >
      <div v-if="detailLoading" class="py-14 text-center text-[13px] text-muted-3">{{ t('preorders.loading') }}</div>
      <div v-else-if="detail" class="flex flex-col gap-[18px]">
        <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
          <div class="flex items-center justify-between gap-3">
            <span class="text-[14.5px] font-bold">{{ t('preorders.preorder_status') }}</span>
            <div class="flex items-center gap-2">
              <BaseButton variant="secondary" size="sm" :loading="duplicating" data-testid="detail-duplicate" @click="doDuplicate([detail.id])">
                <i class="ph-duotone ph-copy" aria-hidden="true"></i>
                {{ t('preorders.duplicate') }}
              </BaseButton>
              <BaseButton
                v-if="!['handed_over', 'cancelled'].includes(detail.status)"
                variant="secondary"
                size="sm"
                :disabled="(detail.payments ?? []).length > 0"
                :title="(detail.payments ?? []).length > 0 ? t('preorders.split_disabled_has_payment') : undefined"
                data-testid="detail-split"
                @click="openSplit(detail.id)"
              >
                <i class="ph-duotone ph-arrows-split" aria-hidden="true"></i>
                {{ t('preorders.split') }}
              </BaseButton>
              <PreorderPrintMenu
                :has-payment="lastPaymentId != null"
                @print-invoice="openInvoice(detail)"
                @print-payment="openPaymentReceipt(lastPaymentId)"
              />
            </div>
          </div>
          <PreorderStatusStepper :status="detail.status" />
          <div class="flex flex-wrap gap-x-6 gap-y-1 text-[12px] text-muted-3">
            <span>{{ t('preorders.detail_created_label') }}: <span class="font-semibold text-muted-4">{{ formatDateTime(detail.created_at) }}</span></span>
            <span>{{ t('preorders.detail_updated_label') }}: <span class="font-semibold text-muted-4">{{ formatDateTime(detail.updated_at) }}</span></span>
            <span v-if="detail.split_children?.length" data-testid="detail-split-children">
              {{ t('preorders.split_into') }}:
              <button
                v-for="child in detail.split_children"
                :key="child.id"
                type="button"
                class="mr-1.5 font-semibold text-brand-active underline"
                @click="openDetailById(child.id)"
              >{{ child.preorder_number }}</button>
            </span>
            <!-- 027 — asal pre-order ini. Nomor memakai snapshot, jadi tetap
                 terbaca bila pre-order sumbernya sudah dihapus (tanpa tautan). -->
            <span v-if="detail.source" data-testid="detail-source">
              {{ detail.source.type === 'split' ? t('preorders.split_from') : t('preorders.duplicated_from') }}:
              <button
                v-if="detail.source.preorder_id"
                type="button"
                class="font-semibold text-brand-active underline"
                @click="openDetailById(detail.source.preorder_id)"
              >{{ detail.source.preorder_number }}</button>
              <span v-else class="font-semibold text-muted-4">{{ detail.source.preorder_number }}</span>
            </span>
          </div>
          <div v-if="!['handed_over', 'cancelled'].includes(detail.status)" class="flex flex-wrap items-center gap-2.5 pt-1.5">
            <BaseButton v-if="detail.status === 'dp_paid'" size="sm" :loading="transitioning" @click="markArrived">{{ t('preorders.mark_arrived') }}</BaseButton>
            <BaseButton v-if="detail.status === 'settled'" size="sm" :loading="transitioning" @click="markHandedOver">{{ t('preorders.mark_handed_over') }}</BaseButton>
            <BaseButton v-if="['ordered', 'dp_paid', 'arrived'].includes(detail.status)" variant="danger" size="sm" @click="showCancelForm = true">{{ t('preorders.cancel_preorder_btn') }}</BaseButton>
            <span class="text-[11.5px] leading-relaxed text-muted-3">{{ t('preorders.handover_note') }}</span>
          </div>
        </div>

        <!-- Penanda manual invoice-terkirim / pengiriman-berjalan. Sengaja
             kartu terpisah dari stepper di atas: ini bukan bagian state
             machine stok/pembayaran, dan bisa diubah maju-mundur bebas. -->
        <div class="flex flex-col gap-3 rounded-card border border-line-2 bg-white p-5">
          <span class="text-[14.5px] font-bold">{{ t('preorders.dispatch_status_title') }}</span>
          <div role="group" :aria-label="t('preorders.dispatch_status_title')" class="flex flex-wrap gap-2">
            <button
              v-for="opt in detailDispatchOptions"
              :key="opt.value"
              type="button"
              :disabled="savingDispatch || detail.status === 'cancelled'"
              :aria-pressed="detail.dispatch_status === opt.value"
              class="h-9 rounded-lg border px-3.5 text-[12.5px] font-bold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
              :class="detail.dispatch_status === opt.value
                ? 'border-transparent bg-brand text-white'
                : 'border-line bg-white text-muted-5 hover:border-brand hover:text-brand-active'"
              @click="setDispatchStatus(opt.value)"
            >{{ opt.label }}</button>
          </div>
          <div v-if="detail.invoice_sent_at || detail.shipping_at" class="flex flex-col gap-0.5 text-[12px] text-muted-4">
            <span v-if="detail.invoice_sent_at">{{ t('preorders.dispatch_invoice_sent_on', { date: formatDateTime(detail.invoice_sent_at) }) }}</span>
            <span v-if="detail.shipping_at">{{ t('preorders.dispatch_shipping_on', { date: formatDateTime(detail.shipping_at) }) }}</span>
          </div>
          <p class="text-[11.5px] leading-relaxed text-muted-3">{{ t('preorders.dispatch_status_note') }}</p>
        </div>

        <!-- Pelanggan & fulfillment sekilas di panel detail, supaya tak perlu
             membuka modal info pelanggan atau mencari hari jemput/kurir di
             rincian total. Data pelanggan datang dari show() (CustomerResource). -->
        <div class="grid grid-cols-1 gap-4 rounded-card border border-line-2 bg-white p-5 sm:grid-cols-2" data-testid="detail-customer-fulfillment">
          <div class="flex flex-col gap-2.5" data-testid="detail-customer">
            <span class="text-[14.5px] font-bold">{{ t('preorders.customer_section') }}</span>
            <span class="text-[13.5px] font-semibold">{{ detail.customer?.name || detail.customer_name || '—' }}</span>
            <div class="flex flex-col gap-1.5 text-[12.5px]">
              <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.phone') }}</span><span class="font-medium">{{ detail.customer?.phone || '—' }}</span></div>
              <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.email') }}</span><span class="break-all text-right font-medium">{{ detail.customer?.email || '—' }}</span></div>
              <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.social_handle') }}</span><span class="font-medium">{{ detail.customer?.social_handle || '—' }}</span></div>
              <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.address') }}</span><span class="text-right font-medium">{{ detail.customer?.address || '—' }}</span></div>
            </div>
          </div>
          <div class="flex flex-col gap-2.5" data-testid="detail-fulfillment">
            <span class="text-[14.5px] font-bold">{{ t('preorders.fulfillment_section') }}</span>
            <span class="text-[13.5px] font-semibold">{{ FULFILLMENT_LABEL[detail.fulfillment] ?? '—' }}</span>
            <div class="flex flex-col gap-1.5 text-[12.5px]">
              <div v-if="detail.fulfillment === 'pickup'" class="flex justify-between gap-3"><span class="text-muted-3">{{ t('preorders.pickup_day_label') }}</span><span class="font-medium">{{ detail.pickup_day ? formatDate(detail.pickup_day) : '—' }}</span></div>
              <template v-else>
                <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('preorders.courier') }}</span><span class="font-medium">{{ detail.courier_name || '—' }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('preorders.shipping_cost') }}</span><span class="font-medium">{{ formatIDR(detail.shipping_cost) }}</span></div>
              </template>
              <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('preorders.expected_date_label') }}</span><span class="font-medium">{{ detail.expected_date ? formatDate(detail.expected_date) : '—' }}</span></div>
              <div v-if="detail.notes" class="flex flex-col gap-1 border-t border-dashed border-line-2 pt-2"><span class="text-muted-3">{{ t('preorders.notes') }}</span><span class="font-medium">{{ detail.notes }}</span></div>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1.35fr_1fr]">
          <div class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5">
            <span class="text-[14.5px] font-bold">{{ t('preorders.ordered_items') }}</span>
            <div v-for="line in detailLines" :key="line.id" class="flex items-start gap-3 border-b border-line-6 pb-3 last:border-b-0">
              <button
                v-if="line.image_url"
                type="button"
                class="h-9 w-9 flex-none cursor-zoom-in"
                :aria-label="t('master_data.enlarge_variant_image', { name: line.name_snapshot })"
                @click="openItemLightbox({ image_url: line.image_url, label: line.name_snapshot })"
              >
                <img :src="line.image_url" :alt="line.name_snapshot" class="h-9 w-9 rounded-md border border-line-2 object-cover" />
              </button>
              <div v-else class="flex h-9 w-9 flex-none items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3">
                <i class="ph-duotone ph-image text-[14px]" aria-hidden="true"></i>
              </div>
              <span class="min-w-[28px] text-[14px] font-bold text-brand-active">{{ line.qty }}×</span>
              <div class="flex flex-1 flex-col gap-0.5">
                <span class="text-[13.5px] font-semibold">{{ line.name_snapshot }}<span v-if="line.category_name" class="font-normal text-muted-3"> · {{ line.category_name }}</span></span>
                <span class="font-mono text-[11px] text-muted-3">{{ line.sku_snapshot }} · {{ formatIDR(line.sell_price) }}</span>
                <span v-if="line.artist_name" class="text-[10.5px] text-muted-3">{{ line.artist_name }}</span>
              </div>
              <span class="text-[13.5px] font-bold">{{ formatIDR(line.line_total) }}</span>
            </div>
            <div class="flex flex-col gap-2">
              <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.subtotal') }}</span><span class="font-semibold">{{ formatIDR(detail.subtotal) }}</span></div>
              <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.shipping_cost') }}</span><span class="font-semibold">{{ formatIDR(detail.shipping_cost) }}</span></div>
              <div v-if="detail.pickup_day" class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.pickup_day_label') }}</span><span class="font-semibold">{{ formatDate(detail.pickup_day) }}</span></div>
              <div v-if="detail.courier_name" class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.courier') }}</span><span class="font-semibold">{{ detail.courier_name }}</span></div>
              <div v-if="parseMoney(detail.discount) > 0" class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.discount_label') }}</span><span class="font-semibold text-danger-text">-{{ formatIDR(detail.discount) }}</span></div>
              <div class="flex items-baseline justify-between border-t border-dashed border-line-2 pt-2.5"><span class="text-[13.5px] font-bold">{{ t('preorders.total_due') }}</span><span class="text-[22px] font-extrabold tracking-tight">{{ formatIDR(detail.total_amount) }}</span></div>
              <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.already_paid') }}</span><span class="font-semibold">{{ formatIDR(detail.paid_amount) }}</span></div>
              <div v-if="parseMoney(detail.outstanding) > 0" class="flex items-center justify-between rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5"><span class="text-[12.5px] font-bold text-warn-text">{{ t('preorders.outstanding_balance') }}</span><span class="text-[17px] font-extrabold text-warn-text">{{ formatIDR(detail.outstanding) }}</span></div>
            </div>
            <span class="text-[11.5px] leading-relaxed text-muted-3">{{ t('preorders.shipping_cost_note') }}</span>
          </div>

          <div class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5">
            <span class="text-[14.5px] font-bold">{{ t('preorders.payment') }}</span>
            <PaymentSummaryCard v-if="detail.payment_summary" :summary="detail.payment_summary" />
            <p class="text-[12px] leading-relaxed text-muted-3">
              {{ t('preorders.payment_history_note') }}
            </p>

            <!-- 028-partial-split-payment (US3) — riwayat pembayaran bersama (juga dipakai
                 di Sales): tiap entri berdiri sendiri, tanpa aksi ubah; Hapus hanya
                 owner/admin dan diaudit server. -->
            <PaymentHistoryList
              :payments="detail.payments ?? []"
              :can-delete="canDeletePayments"
              @view-proof="(p) => viewPaymentProof(p.proof_id)"
              @print="(p) => openPaymentReceipt(p.id)"
              @delete="confirmDeletePayment"
            />

            <BaseButton v-if="canAddPayment" data-testid="add-payment" @click="showRecordPayment = true">
              <i class="ph-duotone ph-plus-circle text-[17px]" aria-hidden="true"></i>
              {{ t('payment_ledger.add_payment') }}
            </BaseButton>
          </div>

          <!-- 007-preorder-import-export-notify (US4) — hanya owner/admin,
               menyamai gerbang server-side isOwnerOrAdmin() (bukan pura-pura
               tersedia lalu ditolak 403, per Constitution III). -->
          <div v-if="isOwnerOrAdmin" class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-5">
            <span class="text-[14.5px] font-bold">{{ t('preorders.notification_section_title') }}</span>
            <div v-if="detail.latest_notification" class="flex items-center gap-2 text-[12.5px]">
              <i
                class="ph-duotone text-[16px]"
                :class="detail.latest_notification.status === 'sent' ? 'ph-check-circle text-brand-active' : detail.latest_notification.status === 'failed' ? 'ph-x-circle text-danger-text' : 'ph-warning-circle text-warn-text'"
                aria-hidden="true"
              ></i>
              <span>{{ t(`preorders.notification_status_${detail.latest_notification.status}`) }}</span>
            </div>
            <p v-else class="text-[12px] text-muted-3">{{ t('preorders.notification_none_yet') }}</p>
            <BaseButton variant="secondary" size="sm" :loading="resendingNotification" @click="doResendNotification">
              <i class="ph-duotone ph-paper-plane-tilt text-[15px]" aria-hidden="true"></i>
              {{ t('preorders.resend_notification_action') }}
            </BaseButton>
          </div>
        </div>

        <div v-if="detail.fulfillment === 'courier'" class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
          <div class="flex items-center justify-between gap-3">
            <span class="text-[14.5px] font-bold">{{ t('preorders.courier_shipping') }}</span>
            <StatusPill v-if="shipment" variant="warn">{{ shipment.status }}</StatusPill>
          </div>

          <div v-if="!shipment && !showShipmentForm" class="flex flex-col items-start gap-2.5">
            <EmptyState icon="ph-truck" :message="t('preorders.no_shipment_data')" />
            <BaseButton size="sm" @click="openShipmentForm">{{ t('preorders.create_shipment_data') }}</BaseButton>
          </div>

          <form v-else-if="showShipmentForm" class="grid grid-cols-2 gap-3.5" @submit.prevent="saveShipment">
            <BaseSelect v-model="shipmentForm.courier_name" :label="t('preorders.courier')" :options="COURIER_OPTIONS.map((c) => ({ value: c, label: c }))" />
            <BaseInput v-model="shipmentForm.tracking_number" :label="t('preorders.tracking_number_optional')" />
            <BaseInput v-model="shipmentForm.recipient_name" :label="t('preorders.recipient_name')" required />
            <BaseInput v-model="shipmentForm.recipient_phone" :label="t('preorders.recipient_phone')" required />
            <BaseInput v-model="shipmentForm.address_line" :label="t('preorders.address')" required class="col-span-2" />
            <div class="col-span-2 flex justify-end gap-2.5">
              <BaseButton variant="secondary" type="button" @click="showShipmentForm = false">{{ t('common.cancel') }}</BaseButton>
              <BaseButton type="submit" :loading="savingShipment">{{ t('preorders.save_shipment') }}</BaseButton>
            </div>
          </form>

          <div v-else class="flex flex-col gap-3.5">
            <div class="grid grid-cols-2 gap-3.5 text-[13px]">
              <BaseInput v-model="shipment.courier_name" :label="t('preorders.courier')" class="col-span-2" />
              <BaseInput v-model="shipment.recipient_name" :label="t('preorders.recipient_name')" />
              <BaseInput v-model="shipment.recipient_phone" :label="t('preorders.recipient_phone')" />
              <BaseInput v-model="shipment.address_line" :label="t('preorders.address')" class="col-span-2" />
            </div>
            <BaseInput v-model="shipment.tracking_number" :label="t('preorders.tracking_number')" :placeholder="t('preorders.not_filled_yet')" />
            <div class="flex justify-end gap-2.5">
              <BaseButton v-if="shipment.status === 'pending'" variant="secondary" size="sm" @click="markPacked">{{ t('preorders.mark_packed') }}</BaseButton>
              <BaseButton v-if="['pending', 'packed'].includes(shipment.status)" size="sm" @click="saveTrackingAndShip">{{ t('preorders.save_tracking_and_ship') }}</BaseButton>
              <BaseButton v-if="shipment.status === 'shipped'" size="sm" @click="markDelivered">{{ t('preorders.mark_delivered') }}</BaseButton>
              <BaseButton variant="secondary" size="sm" :loading="savingShipment" @click="saveShipmentChanges"><i class="ph-duotone ph-check text-[14px]" aria-hidden="true"></i>{{ t('preorders.save_shipment_changes') }}</BaseButton>
              <BaseButton variant="secondary" size="sm" @click="downloadShippingSlip"><i class="ph-duotone ph-file-pdf text-[14px]" aria-hidden="true"></i>{{ t('preorders.download_shipping_slip') }}</BaseButton>
            </div>
          </div>
        </div>
      </div>
    </BaseDrawer>

    <!-- 024-invoice-layout-shipping-slip (US7) — template untuk rendering
         surat jalan sebagai PDF via html2canvas + jsPDF. Posisi FIXED
         di luar viewport (bukan `hidden`/display:none) — html2canvas
         TIDAK BISA merender elemen dengan display:none (kanvas kosong
         berukuran nol, ditangkap try/catch sebagai "Failed to download"
         — ditemukan lewat verifikasi browser sungguhan). Store identity
         (logo) dan "Jenis barang" sengaja dihapus dari dokumen ini —
         surat jalan hanya butuh info pengiriman, bukan katalog barang. -->
    <div ref="shippingSlipEl" class="pointer-events-none fixed left-[-9999px] top-0 w-[420px] overflow-hidden bg-white p-6 font-[Arial,sans-serif]">
      <div class="py-3 text-center text-[13px] font-bold">{{ t('preorders.shipping_info_title') }}</div>
      <div class="grid grid-cols-2 gap-3 border-t border-dashed border-line-2 py-2.5 text-[12px]">
        <div>
          <span class="font-bold uppercase text-muted-3">{{ t('preorders.from_label') }}</span>
          <div>{{ detail?.store_identity?.name }}</div>
          <div v-if="detail?.store_identity?.address">{{ detail.store_identity.address }}</div>
          <div v-if="detail?.store_identity?.contact_phone">{{ detail.store_identity.contact_phone }}</div>
          <div v-if="detail?.store_identity?.contact_person">{{ detail.store_identity.contact_person }}</div>
        </div>
        <div>
          <span class="font-bold uppercase text-muted-3">{{ t('preorders.to_label') }}</span>
          <div>{{ shipment?.recipient_name }}</div>
          <div>{{ shipment?.recipient_phone }}</div>
          <div>{{ shipment?.address_line }}</div>
        </div>
      </div>
    </div>

    <BaseModal :open="showCancelForm" :title="t('preorders.cancel_preorder')" max-width-class="max-w-[420px]" @close="showCancelForm = false">
      <div class="flex flex-col gap-3.5 px-6 py-5">
        <BaseTextarea v-model="cancelReason" :label="t('preorders.cancel_reason')" :rows="3" />
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showCancelForm = false">{{ t('master_data.close') }}</BaseButton>
          <BaseButton variant="danger" :loading="transitioning" @click="submitCancel">{{ t('preorders.cancel_preorder') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <AddPaymentModal
      v-if="detail"
      :open="showRecordPayment"
      :remaining="detail.payment_summary?.remaining ?? detail.outstanding"
      :purpose="paymentPurpose"
      :title="detail.payment_summary?.payment_count > 0 ? t('payment_ledger.add_another_payment') : t('payment_ledger.add_payment')"
      :submit-fn="submitPreorderPayment"
      @close="showRecordPayment = false"
      @saved="handlePaymentSaved"
    />

    <PreorderInvoiceModal
      :open="showInvoiceModal"
      :preorder-id="invoicePreorderId"
      @close="showInvoiceModal = false"
    />

    <PreorderPaymentReceiptModal
      :open="showPaymentReceiptModal"
      :preorder-id="receiptPreorderId"
      :payment-id="receiptPaymentId"
      @close="showPaymentReceiptModal = false"
    />

    <ConfirmDialog
      :open="showDeleteConfirm"
      :title="t('preorders.delete_preorder')"
      :message="t('preorders.delete_preorder_confirm', { number: deleteTarget?.preorder_number })"
      :confirm-label="t('common.delete')"
      :loading="deletingPreorder"
      @close="showDeleteConfirm = false"
      @confirm="performDeletePreorder"
    />

    <ConfirmDialog
      :open="showPaymentDeleteConfirm"
      :title="t('preorders.delete_payment')"
      :message="t('preorders.delete_payment_confirm', { amount: formatIDR(paymentDeleteTarget?.amount ?? 0), number: detail?.preorder_number ?? '' })"
      :confirm-label="t('common.delete')"
      :loading="deletingPayment"
      @close="showPaymentDeleteConfirm = false"
      @confirm="performDeletePayment"
    />

    <ImageLightbox :open="!!proofLightboxSrc" :src="proofLightboxSrc" :alt="t('preorders.view_proof')" @close="closeProofLightbox" />
    <ImageLightbox :open="!!itemLightboxSrc" :src="itemLightboxSrc" :alt="itemLightboxAlt" @close="itemLightboxSrc = null" />

    <!-- Requested: clicking a customer's name in the list shows their full
         info (name/phone/email/social handle/address/notes) — a read-only
         summary, reusing CustomerResource's exact field set. -->
    <BaseModal :open="showCustomerInfo" :title="t('preorders.customer_info_title')" max-width-class="max-w-[420px]" @close="showCustomerInfo = false">
      <div class="flex flex-col gap-3 px-6 py-5">
        <div v-if="customerInfoLoading" class="py-8 text-center text-[13px] text-muted-3">{{ t('preorders.loading') }}</div>
        <div v-else-if="customerInfo" class="flex flex-col gap-3">
          <span class="text-[15px] font-bold">{{ customerInfo.name }}</span>
          <div class="flex flex-col gap-2 text-[13px]">
            <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.phone') }}</span><span class="font-medium">{{ customerInfo.phone || '—' }}</span></div>
            <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.email') }}</span><span class="font-medium">{{ customerInfo.email || '—' }}</span></div>
            <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.social_handle') }}</span><span class="font-medium">{{ customerInfo.social_handle || '—' }}</span></div>
            <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.address') }}</span><span class="text-right font-medium">{{ customerInfo.address || '—' }}</span></div>
            <div v-if="customerInfo.notes" class="flex flex-col gap-1 border-t border-dashed border-line-2 pt-2.5"><span class="text-muted-3">{{ t('events_sessions.notes') }}</span><span class="font-medium">{{ customerInfo.notes }}</span></div>
          </div>
        </div>
      </div>
    </BaseModal>

    <!-- Requested: clicking the missing-shipping-info flag shows shipping
         cost + customer address + notes right away, instead of a trip into the full detail
         drawer. -->
    <BaseModal :open="showShippingFlagInfo" :title="t('preorders.missing_shipping_info_flag')" max-width-class="max-w-[420px]" @close="showShippingFlagInfo = false">
      <div v-if="shippingFlagInfoRow" class="flex flex-col gap-3 px-6 py-5 text-[13px]">
        <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('preorders.shipping_cost') }}</span><span class="font-semibold">{{ formatIDR(shippingFlagInfoRow.shipping_cost) }}</span></div>
        <div class="flex flex-col gap-1 border-t border-dashed border-line-2 pt-2.5">
          <span class="text-muted-3">{{ t('preorders.customer_address') }}</span>
          <span class="font-medium" data-testid="flag-customer-address">{{ shippingFlagInfoRow.customer_address || '—' }}</span>
        </div>
        <div class="flex flex-col gap-1 border-t border-dashed border-line-2 pt-2.5">
          <span class="text-muted-3">{{ t('preorders.notes') }}</span>
          <span class="font-medium">{{ shippingFlagInfoRow.notes || '—' }}</span>
        </div>
      </div>
    </BaseModal>
  </div>
</template>
