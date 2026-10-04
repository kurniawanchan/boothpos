<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import AddBomItemModal from './AddBomItemModal.vue';
import BomCopyMenu from './BomCopyMenu.vue';
import { listBomLines, updateBomLine, deleteBomLine, completeBom, reopenBom, replaceBomSource } from '../../api/materials';
import { formatIDR } from '../../utils/money';
import { useToastStore } from '../../stores/toast';
import { useAuthStore } from '../../stores/auth';

/**
 * 034-seller-po-bom — BOM satu varian sebagai TABEL baris purchase order
 * (Item, Tipe, Purchase Order, Vendor, Biaya Satuan, Jumlah, Total Biaya,
 * Aksi) dengan ringkasan biaya bahan/jasa/total. Dibuka dari baris varian
 * di ProductDetailModal. Biaya satuan, vendor, dan nomor PO adalah
 * snapshot dari server dan hanya-baca di sini; yang bisa diubah pengguna
 * hanya jumlah per SATU unit produk jadi. Pengguna tanpa hak mengubah
 * (butuh menu products DAN purchase_orders) hanya melihat tabelnya —
 * kontrol disembunyikan, bukan dinonaktifkan (Constitution III).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  variantId: { type: [Number, String, null], default: null },
  variantSku: { type: String, default: '' },
  variantName: { type: String, default: '' },
  // Varian satu produk (untuk menu "Salin BOM"); kosong = menu tidak ditampilkan.
  siblings: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'changed']);

const toast = useToastStore();
const auth = useAuthStore();
const { t } = useI18n();

const loading = ref(false);
const rows = ref([]);
const summary = ref(null);
const showAdd = ref(false);

const canEdit = computed(() => auth.canAccessMenu('products') && auth.canAccessMenu('purchase_orders'));

function apply(payload) {
  rows.value = payload.data;
  summary.value = payload.summary;
  qtyDrafts.value = {};
  qtyErrors.value = {};
}

async function reload() {
  if (!props.variantId) return;
  loading.value = true;
  try {
    apply(await listBomLines(props.variantId));
  } catch (err) {
    toast.error(err.message || t('master_data.bom_load_failed'));
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.open, props.variantId],
  ([open]) => {
    if (open) reload();
    else {
      rows.value = [];
      summary.value = null;
    }
  },
  { immediate: true },
);

// --- jumlah per unit (diedit di tempat) --------------------------------
const qtyDrafts = ref({});
const qtyErrors = ref({});

function draftFor(row) {
  return qtyDrafts.value[row.id] ?? row.qty_needed;
}

function validQty(value) {
  const text = String(value).trim();
  return /^\d+(\.\d{1,4})?$/.test(text) && Number(text) > 0;
}

async function commitQty(row) {
  const draft = draftFor(row);
  if (Number(draft) === Number(row.qty_needed)) {
    delete qtyErrors.value[row.id];
    return;
  }
  if (!validQty(draft)) {
    qtyErrors.value = { ...qtyErrors.value, [row.id]: t('master_data.bom_qty_invalid') };
    return;
  }
  try {
    apply(await updateBomLine(row.id, { qty_needed: String(draft).trim() }));
    toast.success(t('master_data.bom_updated'));
    emit('changed');
  } catch (err) {
    if (err.isValidation) qtyErrors.value = { ...qtyErrors.value, [row.id]: Object.values(err.errors)[0]?.[0] ?? t('master_data.bom_qty_invalid') };
  }
}

// --- hapus -------------------------------------------------------------
const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function confirmDelete(row) {
  deleteTarget.value = row;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    const payload = await deleteBomLine(deleteTarget.value.id);
    apply(payload);
    toast.success(t('master_data.bom_removed'));
    if (payload.summary?.reopened) toast.push(t('master_data.bom_auto_reopened'));
    showDelete.value = false;
    emit('changed');
  } catch {
    // error umum sudah ditoast interceptor bersama.
  } finally {
    deleting.value = false;
  }
}

function onAdded(payload) {
  apply(payload);
  emit('changed');
}

async function onCopied() {
  await reload();
  emit('changed');
}

// --- harga lebih baru: ganti sumber secara EKSPLISIT ---------------------------
// Isyarat dari server (newer_price / source_cancelled) tidak pernah mengubah
// baris sendiri; hanya konfirmasi pengguna yang memanggil replace-source.
const showReplace = ref(false);
const replaceTarget = ref(null);
// Baris LEGACY: pilih baris PO pengganti lewat selector (mode 'replace').
const legacyTarget = ref(null);
const showPicker = ref(false);

function askLegacyReplace(row) {
  legacyTarget.value = row;
  showPicker.value = true;
}

async function replaceLegacyWith(purchaseOrderItemId) {
  try {
    apply(await replaceBomSource(legacyTarget.value.id, purchaseOrderItemId));
    toast.success(t('master_data.bom_source_replaced'));
    emit('changed');
  } catch {
    // error umum sudah ditoast interceptor bersama.
  }
}
const replacing = ref(false);

function askReplace(row) {
  replaceTarget.value = row;
  showReplace.value = true;
}

async function performReplace() {
  replacing.value = true;
  try {
    apply(await replaceBomSource(replaceTarget.value.id, replaceTarget.value.newer_price.purchase_order_item_id));
    toast.success(t('master_data.bom_source_replaced'));
    showReplace.value = false;
    emit('changed');
  } catch {
    // error umum sudah ditoast interceptor bersama.
  } finally {
    replacing.value = false;
  }
}

// --- selesai / buka kembali -----------------------------------------------
// Selesai: harga modal varian mengikuti biaya BOM dan dikunci terhadap edit
// manual. Server menolak (409 + pesan) bila BOM kosong, masih punya baris
// legacy, atau ada baris tidak valid — pesan itu sudah ditoast interceptor
// bersama, jadi di sini cukup tidak mengubah tampilan.
const showComplete = ref(false);
const completing = ref(false);
const reopening = ref(false);

async function performComplete() {
  completing.value = true;
  try {
    apply(await completeBom(props.variantId));
    toast.success(t('master_data.bom_completed_toast'));
    showComplete.value = false;
    emit('changed');
  } catch {
    showComplete.value = false;
  } finally {
    completing.value = false;
  }
}

async function performReopen() {
  reopening.value = true;
  try {
    apply(await reopenBom(props.variantId));
    toast.success(t('master_data.bom_reopened_toast'));
    emit('changed');
  } catch {
    // error umum sudah ditoast interceptor bersama.
  } finally {
    reopening.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('master_data.bom_title', { variant: variantSku || variantName })" max-width-class="max-w-[980px]" @close="emit('close')">
    <div v-if="loading && !summary" class="px-6 py-14 text-center text-[13px] text-muted-3">{{ t('master_data.bom_loading') }}</div>
    <div v-else class="flex flex-col gap-4 px-6 py-5">
      <div v-if="summary" class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="flex flex-col gap-0.5 rounded-lg border border-line-2 bg-surface-subtle px-4 py-3">
          <span class="text-[11px] font-bold uppercase tracking-wide text-muted-3">{{ t('master_data.bom_material_cost') }}</span>
          <span class="text-[16px] font-extrabold tracking-tight">{{ formatIDR(summary.material_cost) }}</span>
        </div>
        <div class="flex flex-col gap-0.5 rounded-lg border border-line-2 bg-surface-subtle px-4 py-3">
          <span class="text-[11px] font-bold uppercase tracking-wide text-muted-3">{{ t('master_data.bom_service_cost') }}</span>
          <span class="text-[16px] font-extrabold tracking-tight">{{ formatIDR(summary.service_cost) }}</span>
        </div>
        <div class="flex flex-col gap-0.5 rounded-lg border border-mint-border bg-mint-50 px-4 py-3">
          <span class="text-[11px] font-bold uppercase tracking-wide text-brand-active">{{ t('master_data.bom_total') }}</span>
          <span class="text-[16px] font-extrabold tracking-tight text-brand-active">{{ formatIDR(summary.bom_cost) }}</span>
        </div>
        <div class="flex flex-col gap-0.5 rounded-lg border border-line-2 bg-white px-4 py-3">
          <span class="text-[11px] font-bold uppercase tracking-wide text-muted-3">{{ summary.bom_complete ? t('master_data.bom_cost_price_from_bom') : t('master_data.bom_cost_price') }}</span>
          <span class="text-[16px] font-extrabold tracking-tight">{{ formatIDR(summary.cost_price) }}</span>
        </div>
      </div>

      <div v-if="summary && (summary.bom_complete || rows.length)" class="flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3" :class="summary.bom_complete ? 'border-mint-border bg-mint-50' : 'border-line-2 bg-white'">
        <template v-if="summary.bom_complete">
          <StatusPill variant="mint">{{ t('master_data.bom_complete_badge') }}</StatusPill>
          <span class="flex-1 text-[12.5px] text-muted-4">{{ t('master_data.bom_complete_note') }}</span>
          <BaseButton v-if="canEdit" variant="secondary" size="sm" :loading="reopening" @click="performReopen">{{ t('master_data.bom_reopen') }}</BaseButton>
        </template>
        <template v-else>
          <span class="flex-1 text-[12.5px] text-muted-3">{{ summary.has_legacy ? t('master_data.bom_has_legacy_hint') : t('master_data.bom_complete_prompt') }}</span>
          <BaseButton v-if="canEdit" size="sm" @click="showComplete = true">{{ t('master_data.bom_mark_complete') }}</BaseButton>
        </template>
      </div>

      <div v-if="!rows.length" class="rounded-lg border border-dashed border-disabled-2 px-4 py-8 text-center text-[13px] text-muted-3">
        {{ t('master_data.bom_empty') }}
      </div>
      <div v-else class="overflow-x-auto rounded-lg border border-line-2">
        <table class="w-full border-collapse text-[13px]">
          <thead>
            <tr class="bg-surface-subtle text-left">
              <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.bom_col_item') }}</th>
              <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.bom_col_type') }}</th>
              <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.bom_col_po') }}</th>
              <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.bom_col_vendor') }}</th>
              <th class="px-3 py-2 text-right font-bold text-muted-2">{{ t('master_data.bom_col_unit_cost') }}</th>
              <th class="px-3 py-2 text-right font-bold text-muted-2">{{ t('master_data.bom_col_qty') }}</th>
              <th class="px-3 py-2 text-right font-bold text-muted-2">{{ t('master_data.bom_col_total') }}</th>
              <th v-if="canEdit" class="px-3 py-2 text-right font-bold text-muted-2">{{ t('master_data.bom_col_action') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id" class="border-t border-line-5 align-top transition-colors hover:bg-line-7">
              <td class="px-3 py-2 font-semibold">
                {{ row.item_name }}
                <StatusPill v-if="row.is_legacy" variant="warn" class="ml-1.5" :title="t('master_data.bom_legacy_hint')">{{ t('master_data.bom_legacy') }}</StatusPill>
                <StatusPill v-if="row.source_cancelled" variant="danger" class="ml-1.5" :title="t('master_data.bom_source_cancelled_hint')">{{ t('master_data.bom_source_cancelled') }}</StatusPill>
                <template v-if="row.newer_price">
                  <button
                    v-if="canEdit"
                    type="button"
                    class="ml-1.5 inline-flex items-center rounded-full border border-warn-text px-2 py-0.5 text-[11px] font-bold text-warn-text hover:bg-warn-bg"
                    :title="t('master_data.bom_newer_price_hint', { po: row.newer_price.po_number })"
                    @click="askReplace(row)"
                  >{{ t('master_data.bom_newer_price', { price: formatIDR(row.newer_price.unit_price) }) }}</button>
                  <StatusPill v-else variant="warn" class="ml-1.5" :title="t('master_data.bom_newer_price_hint', { po: row.newer_price.po_number })">{{ t('master_data.bom_newer_price', { price: formatIDR(row.newer_price.unit_price) }) }}</StatusPill>
                </template>
              </td>
              <td class="px-3 py-2">{{ row.line_type === 'service' ? t('master_data.bom_type_service') : t('master_data.bom_type_material') }}</td>
              <td class="px-3 py-2">
                <span v-if="row.po_number" class="font-mono text-[12px] font-bold text-brand-active">{{ row.po_number }}</span>
                <span v-else class="text-muted-3">—</span>
                <span v-if="row.po_qty !== null" class="block text-[11.5px] text-muted-3">{{ t('master_data.bom_po_qty', { qty: row.po_qty }) }}</span>
              </td>
              <td class="px-3 py-2">{{ row.vendor_name || '—' }}</td>
              <td class="px-3 py-2 text-right">{{ formatIDR(row.unit_cost) }}</td>
              <td class="px-3 py-2 text-right">
                <template v-if="canEdit">
                  <input
                    :value="draftFor(row)"
                    type="text"
                    inputmode="decimal"
                    class="w-[88px] rounded-md border bg-white px-2 py-1 text-right text-[13px] outline-none focus:border-brand"
                    :class="qtyErrors[row.id] ? 'border-danger-text' : 'border-line'"
                    :aria-label="`${t('master_data.bom_col_qty')} ${row.item_name}`"
                    :aria-invalid="qtyErrors[row.id] ? 'true' : 'false'"
                    :title="t('master_data.bom_qty_hint')"
                    @input="qtyDrafts = { ...qtyDrafts, [row.id]: $event.target.value }"
                    @blur="commitQty(row)"
                    @keydown.enter.prevent="$event.target.blur()"
                  />
                  <span v-if="qtyErrors[row.id]" class="mt-0.5 block text-[11px] text-danger-text">{{ qtyErrors[row.id] }}</span>
                </template>
                <template v-else>{{ row.qty_needed }}</template>
              </td>
              <td class="px-3 py-2 text-right font-semibold">{{ formatIDR(row.item_cost) }}</td>
              <td v-if="canEdit" class="px-3 py-2 text-right">
                <div class="flex flex-col items-end gap-1">
                  <button v-if="row.is_legacy" type="button" class="text-[12.5px] font-semibold text-brand-active hover:underline" @click="askLegacyReplace(row)">{{ t('master_data.bom_replace_with_po') }}</button>
                  <button type="button" class="text-[12.5px] font-semibold text-danger-text hover:underline" @click="confirmDelete(row)">{{ t('master_data.bom_remove') }}</button>
                </div>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-line-2 bg-surface-subtle font-bold">
              <td class="px-3 py-2" :colspan="6">{{ t('master_data.bom_total') }}</td>
              <td class="px-3 py-2 text-right">{{ formatIDR(summary?.bom_cost) }}</td>
              <td v-if="canEdit" class="px-3 py-2"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div v-if="canEdit" class="flex flex-col gap-3">
        <div>
          <BaseButton variant="secondary" @click="showAdd = true">
            <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
            {{ t('master_data.add_bom_item') }}
          </BaseButton>
        </div>
        <BomCopyMenu v-if="variantId && siblings.length" :variant-id="variantId" :siblings="siblings" :has-rows="rows.length > 0" @copied="onCopied" />
      </div>
    </div>

    <AddBomItemModal :open="showAdd" :variant-id="variantId" @close="showAdd = false" @added="onAdded" />
    <AddBomItemModal :open="showPicker" :variant-id="variantId" mode="replace" @close="showPicker = false" @picked="replaceLegacyWith" />

    <ConfirmDialog
      :open="showReplace"
      :title="t('master_data.bom_use_newer_title')"
      :message="t('master_data.bom_use_newer_confirm', { item: replaceTarget?.item_name, old: formatIDR(replaceTarget?.unit_cost), new: formatIDR(replaceTarget?.newer_price?.unit_price), po: replaceTarget?.newer_price?.po_number })"
      :confirm-label="t('master_data.bom_use_newer')"
      :loading="replacing"
      @close="showReplace = false"
      @confirm="performReplace"
    />

    <ConfirmDialog
      :open="showComplete"
      :title="t('master_data.bom_complete_title')"
      :message="t('master_data.bom_complete_confirm', { cost: formatIDR(summary?.bom_cost) })"
      :confirm-label="t('master_data.bom_mark_complete')"
      :loading="completing"
      @close="showComplete = false"
      @confirm="performComplete"
    />

    <ConfirmDialog
      :open="showDelete"
      :title="t('master_data.bom_remove_title')"
      :message="t('master_data.bom_remove_confirm', { item: deleteTarget?.item_name })"
      :confirm-label="t('master_data.bom_remove')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </BaseModal>
</template>
