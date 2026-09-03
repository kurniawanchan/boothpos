<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import {
  listPurchaseOrders, createPurchaseOrder, updatePurchaseOrder, deletePurchaseOrder,
  updatePurchaseOrderStatus,
} from '../api/purchaseOrders';
import { listVendors } from '../api/vendors';
import { listMaterials } from '../api/materials';
import { listProducts } from '../api/products';
import { useToastStore } from '../stores/toast';
import { formatIDR } from '../utils/money';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseDrawer from '../components/ui/BaseDrawer.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import PurchaseOrderDetailModal from '../components/purchaseOrders/PurchaseOrderDetailModal.vue';

const { t } = useI18n();
const toast = useToastStore();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listPurchaseOrders);
const vendors = ref([]);
const materials = ref([]);
const products = ref([]);
const statusFilter = ref('');

const STATUSES = ['draft', 'ordered', 'received', 'paid', 'cancelled'];
const STATUS_VARIANT = { draft: 'neutral', ordered: 'warn', received: 'mint', paid: 'dark', cancelled: 'danger' };
// Transisi maju yang diizinkan dari status saat ini — persis
// PurchaseOrder::ALLOWED_TRANSITIONS di backend, disalin di sini murni
// untuk menampilkan tombol aksi yang relevan (gerbang sungguhan tetap di
// server, lihat PurchaseOrderPolicy/Service — tampilan ini kosmetik).
const NEXT_STATUSES = { draft: ['ordered', 'cancelled'], ordered: ['received', 'cancelled'], received: ['paid'], paid: [], cancelled: [] };

onMounted(async () => {
  await load();
  vendors.value = (await listVendors({ per_page: 100 })).data;
  materials.value = (await listMaterials({ per_page: 100 })).data;
  products.value = (await listProducts({ per_page: 100 })).data;
});

function applyStatusFilter(status) {
  statusFilter.value = status;
  setFilter({ status: status || undefined });
}

const vendorOptions = computed(() => vendors.value.map((v) => ({ value: v.id, label: v.name })));
const materialOptions = computed(() => materials.value.map((m) => ({ value: m.id, label: m.name })));
const productOptions = computed(() => products.value.map((p) => ({ value: p.id, label: p.name })));

const columns = computed(() => [
  { key: 'po_number', label: t('purchase_orders.col_number') },
  { key: 'vendor_name', label: t('purchase_orders.col_vendor') },
  { key: 'status', label: t('purchase_orders.col_status') },
  { key: 'total_amount', label: t('purchase_orders.col_total') },
  { key: 'actions', label: '' },
]);

// --- Create/edit drawer -----------------------------------------------
const showForm = ref(false);
const editingPo = ref(null);
const form = reactive({ vendor_id: '', notes: '' });
const itemRows = ref([]);
const formErrors = reactive({});
const saving = ref(false);

function blankRow() {
  return { line_type: 'material', material_id: '', product_id: '', description: '', qty: 1, unit_price: 0 };
}

function openCreate() {
  editingPo.value = null;
  Object.assign(form, { vendor_id: '', notes: '' });
  itemRows.value = [blankRow()];
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(po) {
  editingPo.value = po;
  Object.assign(form, { vendor_id: po.vendor_id, notes: po.notes ?? '' });
  itemRows.value = po.items.map((i) => ({
    line_type: i.line_type, material_id: i.material_id ?? '', product_id: i.product_id ?? '',
    description: i.description ?? '', qty: Number(i.qty), unit_price: Number(i.unit_price),
  }));
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function addRow() {
  itemRows.value.push(blankRow());
}
function removeRow(idx) {
  itemRows.value.splice(idx, 1);
}

const rowsLocked = computed(() => editingPo.value && editingPo.value.status !== 'draft');

const formTotal = computed(() =>
  itemRows.value.reduce((sum, r) => sum + (Number(r.qty) || 0) * (Number(r.unit_price) || 0), 0)
);

async function savePo() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    vendor_id: Number(form.vendor_id),
    notes: form.notes || null,
    ...(rowsLocked.value ? {} : {
      items: itemRows.value.map((r) => ({
        line_type: r.line_type,
        material_id: r.line_type === 'material' ? Number(r.material_id) : null,
        product_id: r.product_id ? Number(r.product_id) : null,
        description: r.line_type === 'service' ? r.description : null,
        qty: Number(r.qty),
        unit_price: Number(r.unit_price),
      })),
    }),
  };
  try {
    if (editingPo.value) {
      await updatePurchaseOrder(editingPo.value.id, payload);
      toast.success(t('purchase_orders.po_updated'));
    } else {
      await createPurchaseOrder(payload);
      toast.success(t('purchase_orders.po_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) {
      Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
    } else {
      toast.error(err.message);
    }
  } finally {
    saving.value = false;
  }
}

// --- Status transitions --------------------------------------------------
const transitioning = ref(false);
async function transition(po, status) {
  if (status === 'cancelled') {
    const reason = window.prompt(t('purchase_orders.cancel_reason_prompt'));
    if (reason === null) return;
    await doTransition(po, status, reason);
    return;
  }
  await doTransition(po, status, null);
}
async function doTransition(po, status, cancelReason) {
  transitioning.value = true;
  try {
    await updatePurchaseOrderStatus(po.id, status, cancelReason);
    toast.success(t('purchase_orders.status_updated'));
    await load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    transitioning.value = false;
  }
}

// --- Delete ------------------------------------------------------------
const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);
function confirmDelete(po) {
  deleteTarget.value = po;
  showDelete.value = true;
}
async function performDelete() {
  deleting.value = true;
  try {
    await deletePurchaseOrder(deleteTarget.value.id);
    toast.success(t('purchase_orders.po_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // 409 (bukan draft) sudah ditoast oleh interceptor bersama.
  } finally {
    deleting.value = false;
  }
}

// --- Detail / invoice ----------------------------------------------------
const showDetail = ref(false);
const detailPoId = ref(null);
function openDetail(po) {
  detailPoId.value = po.id;
  showDetail.value = true;
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="flex flex-1 flex-wrap gap-1.5">
        <button
          type="button"
          class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
          :class="!statusFilter ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
          @click="applyStatusFilter('')"
        >
          {{ t('common.all') }}
        </button>
        <button
          v-for="s in STATUSES"
          :key="s"
          type="button"
          class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold capitalize transition-colors"
          :class="statusFilter === s ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
          @click="applyStatusFilter(s)"
        >
          {{ t(`purchase_orders.status_${s}`) }}
        </button>
      </div>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('purchase_orders.new_po') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('purchase_orders.no_purchase_orders')">
        <template #cell-po_number="{ row }">
          <button type="button" class="font-mono text-[12.5px] font-bold text-brand-active hover:underline" @click="openDetail(row)">{{ row.po_number }}</button>
        </template>
        <template #cell-status="{ row }">
          <StatusPill :variant="STATUS_VARIANT[row.status]">{{ t(`purchase_orders.status_${row.status}`) }}</StatusPill>
        </template>
        <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button
              v-for="next in NEXT_STATUSES[row.status]"
              :key="next"
              type="button"
              class="text-[12.5px] font-semibold text-brand-active hover:underline"
              :disabled="transitioning"
              @click="transition(row, next)"
            >
              {{ t(`purchase_orders.action_${next}`) }}
            </button>
            <button v-if="row.status === 'draft'" type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button v-if="row.status === 'draft'" type="button" class="text-[12.5px] font-semibold text-danger-text hover:text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseDrawer :open="showForm" :title="editingPo ? t('purchase_orders.edit_po') : t('purchase_orders.new_po')" @close="showForm = false">
      <form class="flex flex-col gap-4 px-6 py-5" @submit.prevent="savePo">
        <BaseSelect v-model="form.vendor_id" :label="t('purchase_orders.vendor')" required :options="vendorOptions" :error="formErrors.vendor_id" />
        <BaseTextarea v-model="form.notes" :label="t('master_data.notes')" :rows="2" :error="formErrors.notes" />

        <div class="flex items-center justify-between">
          <span class="text-[12.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('purchase_orders.line_items') }}</span>
          <BaseButton v-if="!rowsLocked" type="button" variant="secondary" size="sm" @click="addRow">
            <i class="ph-duotone ph-plus text-[14px]" aria-hidden="true"></i>
            {{ t('purchase_orders.add_line') }}
          </BaseButton>
        </div>
        <p v-if="rowsLocked" class="text-[12px] text-muted-3">{{ t('purchase_orders.items_locked_hint') }}</p>

        <div v-for="(row, idx) in itemRows" :key="idx" class="flex flex-col gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
          <div class="flex items-center justify-between">
            <div class="flex gap-1.5">
              <button
                type="button"
                :disabled="rowsLocked"
                class="rounded-full border px-3 py-1 text-[11.5px] font-semibold"
                :class="row.line_type === 'material' ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4'"
                @click="row.line_type = 'material'"
              >{{ t('purchase_orders.line_type_material') }}</button>
              <button
                type="button"
                :disabled="rowsLocked"
                class="rounded-full border px-3 py-1 text-[11.5px] font-semibold"
                :class="row.line_type === 'service' ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4'"
                @click="row.line_type = 'service'"
              >{{ t('purchase_orders.line_type_service') }}</button>
            </div>
            <button v-if="!rowsLocked && itemRows.length > 1" type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('common.delete')" @click="removeRow(idx)">
              <i class="ph-duotone ph-trash text-[15px]" aria-hidden="true"></i>
            </button>
          </div>

          <BaseSelect v-if="row.line_type === 'material'" v-model="row.material_id" :label="t('purchase_orders.material')" required :disabled="rowsLocked" :options="materialOptions" :error="formErrors[`items.${idx}.material_id`]" />
          <BaseInput v-else v-model="row.description" :label="t('purchase_orders.description')" required :disabled="rowsLocked" :error="formErrors[`items.${idx}.description`]" />

          <BaseSelect v-model="row.product_id" :label="t('purchase_orders.linked_product')" :disabled="rowsLocked" :options="productOptions" :placeholder="t('purchase_orders.no_product_link')" />

          <div class="grid grid-cols-2 gap-3">
            <BaseInput v-model.number="row.qty" type="number" step="0.001" min="0.001" :label="t('purchase_orders.qty')" required :disabled="rowsLocked" />
            <BaseInput v-model.number="row.unit_price" type="number" step="1" min="0" :label="t('purchase_orders.unit_price')" required :disabled="rowsLocked" />
          </div>
        </div>

        <div class="flex items-center justify-between border-t border-dashed border-line-2 pt-3">
          <span class="text-[13.5px] font-bold">{{ t('purchase_orders.total') }}</span>
          <span class="text-[18px] font-extrabold tracking-tight">{{ formatIDR(formTotal) }}</span>
        </div>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="savePo">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseDrawer>

    <ConfirmDialog
      :open="showDelete"
      :title="t('purchase_orders.delete_po')"
      :message="t('purchase_orders.delete_po_confirm', { number: deleteTarget?.po_number })"
      :confirm-label="t('vendors_materials.yes_delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />

    <PurchaseOrderDetailModal :open="showDetail" :purchase-order-id="detailPoId" @close="showDetail = false" @changed="load" />
  </div>
</template>
