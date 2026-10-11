<script setup>
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { createShipment, updateShipment } from '../../api/shipments';
import { useToastStore } from '../../stores/toast';
import { downloadElementAsPdf } from '../../utils/pdfCapture';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import BaseSelect from '../../components/ui/BaseSelect.vue';
import EmptyState from '../../components/ui/EmptyState.vue';
import StatusPill from '../../components/ui/StatusPill.vue';
import { COURIER_DEFAULT, courierSelectOptions } from './preorderMeta';

/**
 * Kartu pengiriman kurir (Mail Order) di panel detail: data pengiriman, form buat, ubah, dan unduh surat jalan.
 * Memiliki sendiri `shipment` (awalnya dari `initialShipment`, yaitu `full.shipment` milik show()) dan
 * seluruh panggilan API shipment. Dipecah dari PreordersView.vue (041) tanpa perubahan perilaku.
 */
const props = defineProps({
  detail: { type: Object, required: true },
  initialShipment: { type: Object, default: null },
});
const toast = useToastStore();
const { t } = useI18n();

const showShipmentForm = ref(false);
const shipment = ref(props.initialShipment);
watch(() => props.initialShipment, (v) => { shipment.value = v; showShipmentForm.value = false; });
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
// 024-invoice-layout-shipping-slip (US7) — template tersembunyi untuk
// rendering surat jalan sebagai PDF via html2canvas + jsPDF.
const shippingSlipEl = ref(null);

function openShipmentForm() {
  // 022-preorder-invoice-crud-overhaul (US2, FR-005) — nama/telepon/alamat
  // pra-isi dari data pelanggan yang sudah tercatat saat preorder dibuat;
  // tetap bisa diedit sebelum disimpan (mis. kirim ke alamat lain / hadiah).
  // Kosong (bukan nilai keliru) kalau data pelanggan itu sendiri belum
  // lengkap (Edge Cases spec.md), sama seperti staf mengisi manual untuk
  // pelanggan walk-in.
  const customer = props.detail?.customer;
  Object.assign(shipmentForm, {
    // 021-preorder-form-updates (US3, research.md Decision 1) — pra-isi
    // dari nilai DEFAULT yang dipilih saat preorder dibuat; shipment
    // sungguhan tetap kolom sendiri, bisa diubah bebas di sini.
    courier_name: props.detail?.courier_name || COURIER_DEFAULT,
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
    shipment.value = await createShipment(props.detail.id, {
      ...shipmentForm,
      shipping_cost: props.detail.shipping_cost,
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
  if (!props.detail || !shipment.value) return;
  try {
    await downloadElementAsPdf(shippingSlipEl.value, `surat-jalan-${props.detail.preorder_number}.pdf`);
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
  <div v-if="detail.fulfillment === 'courier'">
    <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
      <div class="flex items-center justify-between gap-3">
        <span class="text-[14.5px] font-bold">{{ t('preorders.courier_shipping') }}</span>
        <StatusPill v-if="shipment" variant="warn">{{ shipment.status }}</StatusPill>
      </div>

      <div v-if="!shipment && !showShipmentForm" class="flex flex-col items-start gap-2.5">
        <EmptyState icon="ph-truck" :message="t('preorders.no_shipment_data')" />
        <BaseButton size="sm" @click="openShipmentForm">{{ t('preorders.create_shipment_data') }}</BaseButton>
      </div>

      <form v-else-if="showShipmentForm" class="grid grid-cols-2 gap-3.5" @submit.prevent="saveShipment">
        <BaseSelect v-model="shipmentForm.courier_name" :label="t('preorders.courier')" :options="courierSelectOptions" />
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
  </div>
</template>
