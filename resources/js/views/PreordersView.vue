<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import {
  listPreorders,
  getPreorder,
  createPreorder,
  updatePreorderStatus,
  exportPreorders,
  downloadPreorderImportTemplate,
  importPreorders,
  resendPreorderNotification,
} from '../api/preorders';
import { createShipment, updateShipment } from '../api/shipments';
import { lookupVariants } from '../api/products';
import { useToastStore } from '../stores/toast';
import { useAuthStore } from '../stores/auth';
import PreorderInvoiceModal from '../components/preorder/PreorderInvoiceModal.vue';
import PreorderPaymentReceiptModal from '../components/preorder/PreorderPaymentReceiptModal.vue';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import { formatIDR, parseMoney, toMoneyString } from '../utils/money';
import { formatDate, formatDateTime } from '../utils/date';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseDrawer from '../components/ui/BaseDrawer.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import CustomerPickerModal from '../components/forms/CustomerPickerModal.vue';
import RecordPaymentModal from '../components/payment/RecordPaymentModal.vue';
import PreorderStatusStepper from '../components/preorder/PreorderStatusStepper.vue';

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
const FULFILLMENT_LABEL = computed(() => ({
  pickup: t('preorders.fulfillment_pickup'),
  courier: t('preorders.fulfillment_courier'),
}));

const { items, meta, loading, load, setPage, setFilter, params } = usePaginatedList(listPreorders);
onMounted(load);

// 007-preorder-import-export-notify (US1) — pencarian nama pelanggan,
// debounced sama seperti pola pencarian ProductsView.vue.
const customerSearch = ref('');
const debouncedCustomerSearch = useDebouncedFn(() => setFilter({ search: customerSearch.value || undefined }), 300);

const columns = computed(() => [
  { key: 'preorder_number', label: t('preorders.col_number') },
  { key: 'customer_name', label: t('preorders.col_customer') },
  { key: 'status', label: t('preorders.col_status') },
  { key: 'fulfillment', label: t('preorders.col_fulfillment') },
  { key: 'total_amount', label: t('preorders.col_total') },
  { key: 'outstanding', label: t('preorders.col_outstanding') },
  { key: 'actions', label: '' },
]);

// --- Create form (no mockup reference — designed fresh) ----------------
const showCreate = ref(false);
const showCreateCustomerPicker = ref(false);
const createCustomer = ref(null);
const createFulfillment = ref('pickup');
const createShippingCost = ref('0');
const createExpectedDate = ref('');
const createNotes = ref('');
const createItems = ref([]);
const createSearch = ref('');
const createResults = ref([]);
const creating = ref(false);
const createErrors = reactive({});

const runCreateSearch = useDebouncedFn(async () => {
  if (!createSearch.value.trim()) {
    createResults.value = [];
    return;
  }
  createResults.value = (await lookupVariants(createSearch.value.trim(), 8)).data;
}, 300);

function openCreate() {
  createCustomer.value = null;
  createFulfillment.value = 'pickup';
  createShippingCost.value = '0';
  createExpectedDate.value = '';
  createNotes.value = '';
  createItems.value = [];
  createSearch.value = '';
  createResults.value = [];
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  showCreate.value = true;
}

function addCreateItem(variant) {
  const existing = createItems.value.find((i) => i.variant_id === variant.variant_id);
  if (existing) existing.qty += 1;
  else createItems.value.push({ variant_id: variant.variant_id, sku: variant.sku, label: variant.label, sell_price: variant.sell_price, qty: 1 });
  createSearch.value = '';
  createResults.value = [];
}

function bumpCreateItem(item, step) {
  item.qty = Math.max(1, item.qty + step);
}

function removeCreateItem(idx) {
  createItems.value.splice(idx, 1);
}

const createSubtotal = computed(() => createItems.value.reduce((sum, i) => sum + parseMoney(i.sell_price) * i.qty, 0));
const createTotal = computed(() => createSubtotal.value + (createFulfillment.value === 'courier' ? Number(createShippingCost.value) || 0 : 0));
const canSubmitCreate = computed(() => !!createCustomer.value && createItems.value.length > 0);

async function submitCreate() {
  if (!canSubmitCreate.value) return;
  creating.value = true;
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  try {
    await createPreorder({
      customer_id: createCustomer.value.id,
      fulfillment: createFulfillment.value,
      shipping_cost: createFulfillment.value === 'courier' ? toMoneyString(createShippingCost.value) : undefined,
      expected_date: createExpectedDate.value || null,
      notes: createNotes.value || null,
      items: createItems.value.map((i) => ({ variant_id: i.variant_id, qty: i.qty })),
    });
    toast.success(t('preorders.preorder_created'));
    showCreate.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(createErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    creating.value = false;
  }
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
  city: '',
  province: '',
  postal_code: '',
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

function openPaymentReceipt(paymentId) {
  if (!detail.value) return;
  receiptPreorderId.value = detail.value.id;
  receiptPaymentId.value = paymentId;
  showPaymentReceiptModal.value = true;
}

function openInvoice(row) {
  invoicePreorderId.value = row.id;
  showInvoiceModal.value = true;
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
    // GET /preorders/{id} does not return customer/payments/shipment (see
    // report) — customer_name is carried over from the already-loaded
    // list row instead of being re-fetched.
    detail.value = { ...full, customer_name: row.customer_name, fulfillment: full.fulfillment ?? row.fulfillment };
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

// 010-split-payment-preorder-reports (US2/T007) — RecordPaymentModal now
// submits each split entry itself (sequential calls to the existing
// POST /preorders/{id}/payments, research.md R2) and only asks the parent
// to refresh once every entry has succeeded.
async function handlePaymentSaved() {
  showRecordPayment.value = false;
  await Promise.all([refreshDetail(), load()]);
}

function openShipmentForm() {
  Object.assign(shipmentForm, {
    courier_name: '',
    tracking_number: '',
    recipient_name: '',
    recipient_phone: '',
    address_line: '',
    city: '',
    province: '',
    postal_code: '',
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
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
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
      <BaseSelect
        class="w-48"
        :placeholder="t('preorders.all_status')"
        :options="Object.entries(STATUS_LABEL).map(([value, label]) => ({ value, label }))"
        @update:model-value="(v) => setFilter({ status: v || undefined })"
      />
      <BaseSelect
        class="w-44"
        :placeholder="t('preorders.all_fulfillment')"
        :options="[{ value: 'pickup', label: t('preorders.fulfillment_pickup') }, { value: 'courier', label: t('preorders.fulfillment_courier') }]"
        @update:model-value="(v) => setFilter({ fulfillment: v || undefined })"
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

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('preorders.no_preorders')">
        <template #cell-preorder_number="{ row }"><span class="font-mono text-[12.5px] font-semibold">{{ row.preorder_number }}</span></template>
        <template #cell-status="{ row }"><StatusPill :variant="STATUS_VARIANT[row.status]">{{ STATUS_LABEL[row.status] }}</StatusPill></template>
        <template #cell-fulfillment="{ row }">{{ FULFILLMENT_LABEL[row.fulfillment] }}</template>
        <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
        <template #cell-outstanding="{ row }">{{ formatIDR(row.outstanding) }}</template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-3">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openInvoice(row)">{{ t('preorders.print_action') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-brand-active" @click="openDetail(row)">{{ t('preorders.detail') }}</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <!-- Create form — no mockup reference, designed fresh -->
    <BaseModal :open="showCreate" :title="t('preorders.new_preorder')" max-width-class="max-w-[560px]" @close="showCreate = false">
      <div class="flex flex-col gap-4 px-6 py-5">
        <button type="button" class="flex items-center justify-between gap-3 rounded-lg border border-line px-3.5 py-3 text-left hover:border-brand" @click="showCreateCustomerPicker = true">
          <span class="text-[13.5px] font-semibold">{{ createCustomer?.name ?? t('preorders.pick_customer_ellipsis') }}</span>
          <i class="ph-duotone ph-caret-right text-[15px] text-muted-3" aria-hidden="true"></i>
        </button>
        <p v-if="createErrors.customer_id" class="text-[12px] font-medium text-danger-text">{{ createErrors.customer_id }}</p>

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
            {{ t('preorders.fulfillment_courier') }}
          </button>
        </div>

        <BaseInput v-if="createFulfillment === 'courier'" v-model="createShippingCost" type="number" min="0" :label="t('preorders.shipping_cost_rp')" />
        <BaseInput v-model="createExpectedDate" type="date" :label="t('preorders.eta_optional')" />

        <div v-for="(item, idx) in createItems" :key="item.variant_id" class="flex items-center gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3">
          <div class="flex min-w-0 flex-1 flex-col gap-0.5"><span class="text-[13px] font-semibold">{{ item.label }}</span><span class="font-mono text-[10.5px] text-muted-3">{{ item.sku }}</span></div>
          <div class="flex items-center gap-0.5 overflow-hidden rounded-lg border border-line bg-white">
            <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('preorders.decrease')" @click="bumpCreateItem(item, -1)"><i class="ph-duotone ph-minus text-[13px]" aria-hidden="true"></i></button>
            <span class="min-w-[26px] text-center text-[13px] font-bold">{{ item.qty }}</span>
            <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('preorders.increase')" @click="bumpCreateItem(item, 1)"><i class="ph-duotone ph-plus text-[13px]" aria-hidden="true"></i></button>
          </div>
          <button type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('preorders.delete_item', { name: item.label })" @click="removeCreateItem(idx)"><i class="ph-duotone ph-trash text-[13px]" aria-hidden="true"></i></button>
        </div>

        <div class="relative">
          <BaseInput v-model="createSearch" :label="t('preorders.add_item')" :placeholder="t('preorders.search_product_or_sku_placeholder')" @input="runCreateSearch" />
          <div v-if="createResults.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-line-2 bg-white shadow-lg">
            <button v-for="v in createResults" :key="v.variant_id" type="button" class="flex w-full flex-col gap-0.5 px-3.5 py-2.5 text-left hover:bg-line-7" @click="addCreateItem(v)">
              <span class="text-[13px] font-semibold">{{ v.label }}</span>
              <span class="font-mono text-[11px] text-muted-3">{{ v.sku }} · {{ formatIDR(v.sell_price) }}</span>
            </button>
          </div>
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
          <BaseButton :disabled="!canSubmitCreate" :loading="creating" @click="submitCreate">{{ t('preorders.save_preorder') }}</BaseButton>
        </div>
      </template>
    </BaseModal>
    <CustomerPickerModal :open="showCreateCustomerPicker" @close="showCreateCustomerPicker = false" @select="(c) => (createCustomer = c)" />

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
          <span class="text-[14.5px] font-bold">{{ t('preorders.preorder_status') }}</span>
          <PreorderStatusStepper :status="detail.status" />
          <div v-if="!['handed_over', 'cancelled'].includes(detail.status)" class="flex flex-wrap items-center gap-2.5 pt-1.5">
            <BaseButton v-if="detail.status === 'dp_paid'" size="sm" :loading="transitioning" @click="markArrived">{{ t('preorders.mark_arrived') }}</BaseButton>
            <BaseButton v-if="detail.status === 'settled'" size="sm" :loading="transitioning" @click="markHandedOver">{{ t('preorders.mark_handed_over') }}</BaseButton>
            <BaseButton v-if="['ordered', 'dp_paid', 'arrived'].includes(detail.status)" variant="danger" size="sm" @click="showCancelForm = true">{{ t('preorders.cancel_preorder_btn') }}</BaseButton>
            <span class="text-[11.5px] leading-relaxed text-muted-3">{{ t('preorders.handover_note') }}</span>
          </div>
        </div>

        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1.35fr_1fr]">
          <div class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5">
            <span class="text-[14.5px] font-bold">{{ t('preorders.ordered_items') }}</span>
            <div v-for="line in detailLines" :key="line.id" class="flex items-start gap-3 border-b border-line-6 pb-3 last:border-b-0">
              <span class="min-w-[28px] text-[14px] font-bold text-brand-active">{{ line.qty }}×</span>
              <div class="flex flex-1 flex-col gap-0.5"><span class="text-[13.5px] font-semibold">{{ line.name_snapshot }}</span><span class="font-mono text-[11px] text-muted-3">{{ line.sku_snapshot }} · {{ formatIDR(line.sell_price) }}</span></div>
              <span class="text-[13.5px] font-bold">{{ formatIDR(line.line_total) }}</span>
            </div>
            <div class="flex flex-col gap-2">
              <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.subtotal') }}</span><span class="font-semibold">{{ formatIDR(detail.subtotal) }}</span></div>
              <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.shipping_cost') }}</span><span class="font-semibold">{{ formatIDR(detail.shipping_cost) }}</span></div>
              <div class="flex items-baseline justify-between border-t border-dashed border-line-2 pt-2.5"><span class="text-[13.5px] font-bold">{{ t('preorders.total_due') }}</span><span class="text-[22px] font-extrabold tracking-tight">{{ formatIDR(detail.total_amount) }}</span></div>
              <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('preorders.already_paid') }}</span><span class="font-semibold">{{ formatIDR(detail.paid_amount) }}</span></div>
              <div v-if="parseMoney(detail.outstanding) > 0" class="flex items-center justify-between rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5"><span class="text-[12.5px] font-bold text-warn-text">{{ t('preorders.outstanding_balance') }}</span><span class="text-[17px] font-extrabold text-warn-text">{{ formatIDR(detail.outstanding) }}</span></div>
            </div>
            <span class="text-[11.5px] leading-relaxed text-muted-3">{{ t('preorders.shipping_cost_note') }}</span>
          </div>

          <div class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5">
            <span class="text-[14.5px] font-bold">{{ t('preorders.payment') }}</span>
            <p class="text-[12px] leading-relaxed text-muted-3">
              {{ t('preorders.payment_history_note') }}
            </p>

            <!-- 010-split-payment-preorder-reports (US4, T020) — setiap
                 entri pembayaran (termasuk split-payment, FR-005) tampil
                 satu per satu dengan tombol cetak struk per entri. -->
            <div v-if="(detail.payments ?? []).length" class="flex flex-col gap-2">
              <div
                v-for="p in detail.payments"
                :key="p.id"
                class="flex items-center justify-between gap-2 rounded-lg border border-line-2 px-3 py-2.5"
              >
                <div class="flex flex-col gap-0.5">
                  <span class="text-[12.5px] font-semibold">
                    {{ p.purpose === 'settlement' ? t('preorders.payment_event_settlement') : t('preorders.payment_event_down_payment') }}
                    · {{ formatIDR(p.amount) }}
                  </span>
                  <span class="text-[11px] text-muted-3">{{ formatDateTime(p.paid_at) }}</span>
                </div>
                <button
                  type="button"
                  class="whitespace-nowrap text-[12px] font-semibold text-muted-4 hover:text-brand-active"
                  @click="openPaymentReceipt(p.id)"
                >
                  {{ t('preorders.print_payment_receipt') }}
                </button>
              </div>
            </div>
            <p v-else class="text-[12px] text-muted-3">{{ t('preorders.payment_history_empty') }}</p>

            <BaseButton v-if="parseMoney(detail.outstanding) > 0" @click="showRecordPayment = true">
              <i class="ph-duotone ph-plus-circle text-[17px]" aria-hidden="true"></i>
              {{ t('preorders.record_settlement') }}
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
            <BaseInput v-model="shipmentForm.courier_name" :label="t('preorders.courier')" required />
            <BaseInput v-model="shipmentForm.tracking_number" :label="t('preorders.tracking_number_optional')" />
            <BaseInput v-model="shipmentForm.recipient_name" :label="t('preorders.recipient_name')" required />
            <BaseInput v-model="shipmentForm.recipient_phone" :label="t('preorders.recipient_phone')" required />
            <BaseInput v-model="shipmentForm.address_line" :label="t('preorders.address')" required class="col-span-2" />
            <BaseInput v-model="shipmentForm.city" :label="t('preorders.city')" required />
            <BaseInput v-model="shipmentForm.postal_code" :label="t('preorders.postal_code')" />
            <div class="col-span-2 flex justify-end gap-2.5">
              <BaseButton variant="secondary" type="button" @click="showShipmentForm = false">{{ t('common.cancel') }}</BaseButton>
              <BaseButton type="submit" :loading="savingShipment">{{ t('preorders.save_shipment') }}</BaseButton>
            </div>
          </form>

          <div v-else class="flex flex-col gap-3.5">
            <div class="grid grid-cols-2 gap-3.5 text-[13px]">
              <div><span class="text-muted-3">{{ t('preorders.courier') }}</span><div class="font-semibold">{{ shipment.courier_name }}</div></div>
              <div><span class="text-muted-3">{{ t('preorders.recipient') }}</span><div class="font-semibold">{{ shipment.recipient_name }} · {{ shipment.recipient_phone }}</div></div>
              <div class="col-span-2"><span class="text-muted-3">{{ t('preorders.address') }}</span><div class="font-semibold">{{ shipment.address_line }}, {{ shipment.city }}</div></div>
            </div>
            <BaseInput v-model="shipment.tracking_number" :label="t('preorders.tracking_number')" :placeholder="t('preorders.not_filled_yet')" />
            <div class="flex justify-end gap-2.5">
              <BaseButton v-if="shipment.status === 'pending'" variant="secondary" size="sm" @click="markPacked">{{ t('preorders.mark_packed') }}</BaseButton>
              <BaseButton v-if="['pending', 'packed'].includes(shipment.status)" size="sm" @click="saveTrackingAndShip">{{ t('preorders.save_tracking_and_ship') }}</BaseButton>
              <BaseButton v-if="shipment.status === 'shipped'" size="sm" @click="markDelivered">{{ t('preorders.mark_delivered') }}</BaseButton>
            </div>
          </div>
        </div>
      </div>
    </BaseDrawer>

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

    <RecordPaymentModal
      v-if="detail"
      :open="showRecordPayment"
      :preorder-id="detail.id"
      :due-amount="detail.outstanding"
      :purpose="paymentPurpose"
      :title="t('preorders.record_preorder_settlement')"
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
  </div>
</template>
