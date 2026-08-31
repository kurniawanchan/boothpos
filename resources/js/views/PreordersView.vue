<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listPreorders, getPreorder, createPreorder, updatePreorderStatus, createPreorderPayment } from '../api/preorders';
import { createShipment, updateShipment } from '../api/shipments';
import { lookupVariants } from '../api/products';
import { useToastStore } from '../stores/toast';
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

const STATUS_LABEL = { ordered: 'Dipesan', dp_paid: 'DP dibayar', arrived: 'Barang tiba', settled: 'Lunas', handed_over: 'Diserahkan', cancelled: 'Dibatalkan' };
const STATUS_VARIANT = { ordered: 'neutral', dp_paid: 'warn', arrived: 'mint', settled: 'mint', handed_over: 'dark', cancelled: 'danger' };
const FULFILLMENT_LABEL = { pickup: 'Ambil sendiri', courier: 'Kurir' };

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listPreorders);
onMounted(load);

const columns = [
  { key: 'preorder_number', label: 'Nomor' },
  { key: 'customer_name', label: 'Pelanggan' },
  { key: 'status', label: 'Status' },
  { key: 'fulfillment', label: 'Pemenuhan' },
  { key: 'total_amount', label: 'Total' },
  { key: 'outstanding', label: 'Sisa' },
  { key: 'actions', label: '' },
];

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
    toast.success('Pre-order dibuat.');
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
const recordingPayment = ref(false);

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
    toast.success('Ditandai barang tiba — stok bertambah.');
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
    toast.success('Ditandai diserahkan ke pelanggan — stok berkurang.');
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
    toast.success('Pre-order dibatalkan.');
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

async function submitPayment(payload) {
  recordingPayment.value = true;
  try {
    await createPreorderPayment(detail.value.id, payload);
    toast.success('Pembayaran tersimpan.');
    showRecordPayment.value = false;
    await Promise.all([refreshDetail(), load()]);
  } catch (err) {
    if (err.isValidation) toast.error(Object.values(err.errors)[0]?.[0] ?? err.message);
  } finally {
    recordingPayment.value = false;
  }
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
    toast.success('Data pengiriman tersimpan.');
  } catch (err) {
    if (err.isValidation) toast.error(Object.values(err.errors)[0]?.[0] ?? err.message);
    // 409 (bukan fulfillment kurir / sudah ada pengiriman) sudah ditoast global.
  } finally {
    savingShipment.value = false;
  }
}

async function markPacked() {
  shipment.value = await updateShipment(shipment.value.id, { status: 'packed' });
  toast.success('Ditandai dikemas.');
}

async function saveTrackingAndShip() {
  if (!shipment.value.tracking_number) {
    toast.warning('Isi nomor resi terlebih dahulu.');
    return;
  }
  shipment.value = await updateShipment(shipment.value.id, { tracking_number: shipment.value.tracking_number, status: 'shipped' });
  toast.success('Resi tersimpan, status dikirim.');
}

async function markDelivered() {
  shipment.value = await updateShipment(shipment.value.id, { status: 'delivered' });
  toast.success('Ditandai diterima pelanggan.');
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <BaseSelect
        class="w-48"
        placeholder="Semua status"
        :options="Object.entries(STATUS_LABEL).map(([value, label]) => ({ value, label }))"
        @update:model-value="(v) => setFilter({ status: v || undefined })"
      />
      <BaseSelect
        class="w-44"
        placeholder="Semua pemenuhan"
        :options="[{ value: 'pickup', label: 'Ambil sendiri' }, { value: 'courier', label: 'Kurir' }]"
        @update:model-value="(v) => setFilter({ fulfillment: v || undefined })"
      />
      <span class="flex-1"></span>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        Pre-order baru
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" empty-message="Belum ada pre-order.">
        <template #cell-preorder_number="{ row }"><span class="font-mono text-[12.5px] font-semibold">{{ row.preorder_number }}</span></template>
        <template #cell-status="{ row }"><StatusPill :variant="STATUS_VARIANT[row.status]">{{ STATUS_LABEL[row.status] }}</StatusPill></template>
        <template #cell-fulfillment="{ row }">{{ FULFILLMENT_LABEL[row.fulfillment] }}</template>
        <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
        <template #cell-outstanding="{ row }">{{ formatIDR(row.outstanding) }}</template>
        <template #cell-actions="{ row }">
          <button type="button" class="text-[12.5px] font-semibold text-brand-active" @click="openDetail(row)">Detail</button>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <!-- Create form — no mockup reference, designed fresh -->
    <BaseModal :open="showCreate" title="Pre-order baru" max-width-class="max-w-[560px]" @close="showCreate = false">
      <div class="flex flex-col gap-4 px-6 py-5">
        <button type="button" class="flex items-center justify-between gap-3 rounded-lg border border-line px-3.5 py-3 text-left hover:border-brand" @click="showCreateCustomerPicker = true">
          <span class="text-[13.5px] font-semibold">{{ createCustomer?.name ?? 'Pilih pelanggan…' }}</span>
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
            Ambil sendiri
          </button>
          <button
            type="button"
            class="rounded-lg border px-3.5 py-3 text-[13px] font-bold transition-colors"
            :class="createFulfillment === 'courier' ? 'border-brand bg-mint-50 text-brand-active' : 'border-line text-muted-5'"
            @click="createFulfillment = 'courier'"
          >
            Kurir
          </button>
        </div>

        <BaseInput v-if="createFulfillment === 'courier'" v-model="createShippingCost" type="number" min="0" label="Ongkos kirim (Rp)" />
        <BaseInput v-model="createExpectedDate" type="date" label="Estimasi tiba (opsional)" />

        <div v-for="(item, idx) in createItems" :key="item.variant_id" class="flex items-center gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3">
          <div class="flex min-w-0 flex-1 flex-col gap-0.5"><span class="text-[13px] font-semibold">{{ item.label }}</span><span class="font-mono text-[10.5px] text-muted-3">{{ item.sku }}</span></div>
          <div class="flex items-center gap-0.5 overflow-hidden rounded-lg border border-line bg-white">
            <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" aria-label="Kurangi" @click="bumpCreateItem(item, -1)"><i class="ph-duotone ph-minus text-[13px]" aria-hidden="true"></i></button>
            <span class="min-w-[26px] text-center text-[13px] font-bold">{{ item.qty }}</span>
            <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" aria-label="Tambah" @click="bumpCreateItem(item, 1)"><i class="ph-duotone ph-plus text-[13px]" aria-hidden="true"></i></button>
          </div>
          <button type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="`Hapus ${item.label}`" @click="removeCreateItem(idx)"><i class="ph-duotone ph-trash text-[13px]" aria-hidden="true"></i></button>
        </div>

        <div class="relative">
          <BaseInput v-model="createSearch" label="Tambah item" placeholder="Cari nama produk atau SKU…" @input="runCreateSearch" />
          <div v-if="createResults.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-line-2 bg-white shadow-lg">
            <button v-for="v in createResults" :key="v.variant_id" type="button" class="flex w-full flex-col gap-0.5 px-3.5 py-2.5 text-left hover:bg-line-7" @click="addCreateItem(v)">
              <span class="text-[13px] font-semibold">{{ v.label }}</span>
              <span class="font-mono text-[11px] text-muted-3">{{ v.sku }} · {{ formatIDR(v.sell_price) }}</span>
            </button>
          </div>
        </div>

        <BaseTextarea v-model="createNotes" label="Catatan" :rows="2" />

        <div class="flex items-baseline justify-between border-t border-dashed border-line-2 pt-3">
          <span class="text-[13.5px] font-bold">Total estimasi</span>
          <span class="text-[20px] font-extrabold tracking-tight">{{ formatIDR(createTotal) }}</span>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showCreate = false">Batal</BaseButton>
          <BaseButton :disabled="!canSubmitCreate" :loading="creating" @click="submitCreate">Simpan pre-order</BaseButton>
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
      <div v-if="detailLoading" class="py-14 text-center text-[13px] text-muted-3">Memuat…</div>
      <div v-else-if="detail" class="flex flex-col gap-[18px]">
        <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
          <span class="text-[14.5px] font-bold">Status pre-order</span>
          <PreorderStatusStepper :status="detail.status" />
          <div v-if="!['handed_over', 'cancelled'].includes(detail.status)" class="flex flex-wrap items-center gap-2.5 pt-1.5">
            <BaseButton v-if="detail.status === 'dp_paid'" size="sm" :loading="transitioning" @click="markArrived">Tandai barang tiba</BaseButton>
            <BaseButton v-if="detail.status === 'settled'" size="sm" :loading="transitioning" @click="markHandedOver">Tandai diserahkan</BaseButton>
            <BaseButton v-if="['ordered', 'dp_paid', 'arrived'].includes(detail.status)" variant="danger" size="sm" @click="showCancelForm = true">Batalkan</BaseButton>
            <span class="text-[11.5px] leading-relaxed text-muted-3">Diserahkan hanya diizinkan bila pelunasan sudah menutup total.</span>
          </div>
        </div>

        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1.35fr_1fr]">
          <div class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5">
            <span class="text-[14.5px] font-bold">Item dipesan</span>
            <div v-for="line in detailLines" :key="line.id" class="flex items-start gap-3 border-b border-line-6 pb-3 last:border-b-0">
              <span class="min-w-[28px] text-[14px] font-bold text-brand-active">{{ line.qty }}×</span>
              <div class="flex flex-1 flex-col gap-0.5"><span class="text-[13.5px] font-semibold">{{ line.name_snapshot }}</span><span class="font-mono text-[11px] text-muted-3">{{ line.sku_snapshot }} · {{ formatIDR(line.sell_price) }}</span></div>
              <span class="text-[13.5px] font-bold">{{ formatIDR(line.line_total) }}</span>
            </div>
            <div class="flex flex-col gap-2">
              <div class="flex justify-between text-[12.5px]"><span class="text-muted">Subtotal</span><span class="font-semibold">{{ formatIDR(detail.subtotal) }}</span></div>
              <div class="flex justify-between text-[12.5px]"><span class="text-muted">Ongkos kirim</span><span class="font-semibold">{{ formatIDR(detail.shipping_cost) }}</span></div>
              <div class="flex items-baseline justify-between border-t border-dashed border-line-2 pt-2.5"><span class="text-[13.5px] font-bold">Total tagihan</span><span class="text-[22px] font-extrabold tracking-tight">{{ formatIDR(detail.total_amount) }}</span></div>
              <div class="flex justify-between text-[12.5px]"><span class="text-muted">Sudah dibayar</span><span class="font-semibold">{{ formatIDR(detail.paid_amount) }}</span></div>
              <div v-if="parseMoney(detail.outstanding) > 0" class="flex items-center justify-between rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5"><span class="text-[12.5px] font-bold text-warn-text">Sisa tagihan</span><span class="text-[17px] font-extrabold text-warn-text">{{ formatIDR(detail.outstanding) }}</span></div>
            </div>
            <span class="text-[11.5px] leading-relaxed text-muted-3">Ongkos kirim masuk ke total tagihan, tidak terhitung sebagai pendapatan penjualan produk.</span>
          </div>

          <div class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-5">
            <span class="text-[14.5px] font-bold">Pembayaran</span>
            <p class="text-[12px] leading-relaxed text-muted-3">
              Riwayat rinci per pembayaran belum disediakan API saat ini — lihat catatan pada laporan sesi. Total dibayar dan sisa tagihan di atas tetap akurat.
            </p>
            <BaseButton v-if="parseMoney(detail.outstanding) > 0" @click="showRecordPayment = true">
              <i class="ph-duotone ph-plus-circle text-[17px]" aria-hidden="true"></i>
              Catat pelunasan
            </BaseButton>
          </div>
        </div>

        <div v-if="detail.fulfillment === 'courier'" class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
          <div class="flex items-center justify-between gap-3">
            <span class="text-[14.5px] font-bold">Pengiriman kurir</span>
            <StatusPill v-if="shipment" variant="warn">{{ shipment.status }}</StatusPill>
          </div>

          <div v-if="!shipment && !showShipmentForm" class="flex flex-col items-start gap-2.5">
            <EmptyState icon="ph-truck" message="Belum ada data pengiriman untuk pre-order ini." />
            <BaseButton size="sm" @click="openShipmentForm">Buat data pengiriman</BaseButton>
          </div>

          <form v-else-if="showShipmentForm" class="grid grid-cols-2 gap-3.5" @submit.prevent="saveShipment">
            <BaseInput v-model="shipmentForm.courier_name" label="Kurir" required />
            <BaseInput v-model="shipmentForm.tracking_number" label="Nomor resi (opsional)" />
            <BaseInput v-model="shipmentForm.recipient_name" label="Nama penerima" required />
            <BaseInput v-model="shipmentForm.recipient_phone" label="Telepon penerima" required />
            <BaseInput v-model="shipmentForm.address_line" label="Alamat" required class="col-span-2" />
            <BaseInput v-model="shipmentForm.city" label="Kota" required />
            <BaseInput v-model="shipmentForm.postal_code" label="Kode pos" />
            <div class="col-span-2 flex justify-end gap-2.5">
              <BaseButton variant="secondary" type="button" @click="showShipmentForm = false">Batal</BaseButton>
              <BaseButton type="submit" :loading="savingShipment">Simpan pengiriman</BaseButton>
            </div>
          </form>

          <div v-else class="flex flex-col gap-3.5">
            <div class="grid grid-cols-2 gap-3.5 text-[13px]">
              <div><span class="text-muted-3">Kurir</span><div class="font-semibold">{{ shipment.courier_name }}</div></div>
              <div><span class="text-muted-3">Penerima</span><div class="font-semibold">{{ shipment.recipient_name }} · {{ shipment.recipient_phone }}</div></div>
              <div class="col-span-2"><span class="text-muted-3">Alamat</span><div class="font-semibold">{{ shipment.address_line }}, {{ shipment.city }}</div></div>
            </div>
            <BaseInput v-model="shipment.tracking_number" label="Nomor resi" placeholder="Belum diisi" />
            <div class="flex justify-end gap-2.5">
              <BaseButton v-if="shipment.status === 'pending'" variant="secondary" size="sm" @click="markPacked">Tandai dikemas</BaseButton>
              <BaseButton v-if="['pending', 'packed'].includes(shipment.status)" size="sm" @click="saveTrackingAndShip">Simpan resi &amp; kirim</BaseButton>
              <BaseButton v-if="shipment.status === 'shipped'" size="sm" @click="markDelivered">Tandai diterima</BaseButton>
            </div>
          </div>
        </div>
      </div>
    </BaseDrawer>

    <BaseModal :open="showCancelForm" title="Batalkan pre-order" max-width-class="max-w-[420px]" @close="showCancelForm = false">
      <div class="flex flex-col gap-3.5 px-6 py-5">
        <BaseTextarea v-model="cancelReason" label="Alasan pembatalan" :rows="3" />
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showCancelForm = false">Tutup</BaseButton>
          <BaseButton variant="danger" :loading="transitioning" @click="submitCancel">Batalkan pre-order</BaseButton>
        </div>
      </template>
    </BaseModal>

    <RecordPaymentModal
      v-if="detail"
      :open="showRecordPayment"
      :due-amount="detail.outstanding"
      :purpose="paymentPurpose"
      :submitting="recordingPayment"
      title="Catat pelunasan pre-order"
      @close="showRecordPayment = false"
      @submit="submitPayment"
    />
  </div>
</template>
