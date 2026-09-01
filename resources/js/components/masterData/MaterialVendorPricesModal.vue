<script setup>
import { reactive, ref, watch } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import StatusPill from '../ui/StatusPill.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import { getMaterial, addVendorPrice, updateVendorPrice, deleteVendorPrice } from '../../api/materials';
import { listVendors } from '../../api/vendors';
import { formatIDR, toMoneyString } from '../../utils/money';
import { useToastStore } from '../../stores/toast';

/**
 * Kelola daftar harga vendor untuk satu bahan — dibuka dari aksi baris
 * "Harga vendor" di MaterialsView. Konsep "preferred" ditonjolkan lewat
 * badge karena itulah yang secara diam-diam menentukan bom_cost varian
 * mana pun yang memakai bahan ini (lihat App\Services\BomCostCalculator).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  materialId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close', 'changed']);

const toast = useToastStore();
const material = ref(null);
const vendors = ref([]);
const loading = ref(false);

const showForm = ref(false);
const editingPrice = ref(null);
const form = reactive({ vendor_id: '', price: '0', is_preferred: false, notes: '' });
const formErrors = reactive({});
const saving = ref(false);

function resetForm() {
  Object.assign(form, { vendor_id: '', price: '0', is_preferred: false, notes: '' });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
}

async function reload() {
  if (!props.materialId) return;
  loading.value = true;
  try {
    [material.value, vendors.value] = await Promise.all([
      getMaterial(props.materialId),
      listVendors({ per_page: 100, is_active: 1 }).then((r) => r.data),
    ]);
  } catch (err) {
    toast.error(err.message || 'Gagal memuat harga vendor.');
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.open, props.materialId],
  ([open]) => {
    if (open) reload();
    else {
      material.value = null;
      resetForm();
    }
  },
  { immediate: true }
);

const vendorOptions = ref([]);
watch(vendors, (v) => {
  vendorOptions.value = v.map((x) => ({ value: x.id, label: x.name }));
});

function openCreate() {
  editingPrice.value = null;
  resetForm();
  showForm.value = true;
}

function openEdit(price) {
  editingPrice.value = price;
  Object.assign(form, {
    vendor_id: price.vendor_id,
    price: price.price,
    is_preferred: price.is_preferred,
    notes: price.notes ?? '',
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function savePrice() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    price: toMoneyString(form.price),
    is_preferred: form.is_preferred,
    notes: form.notes || null,
  };
  try {
    if (editingPrice.value) {
      await updateVendorPrice(editingPrice.value.id, payload);
      toast.success('Harga vendor diperbarui.');
    } else {
      await addVendorPrice(props.materialId, { vendor_id: Number(form.vendor_id), ...payload });
      toast.success('Harga vendor ditambahkan.');
    }
    showForm.value = false;
    await reload();
    emit('changed');
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function confirmDelete(price) {
  deleteTarget.value = price;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteVendorPrice(deleteTarget.value.id);
    toast.success('Harga vendor dihapus.');
    showDelete.value = false;
    await reload();
    emit('changed');
  } catch {
    // error umum sudah ditoast interceptor bersama.
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="material ? `Harga vendor — ${material.name}` : 'Harga vendor'" max-width-class="max-w-[560px]" @close="emit('close')">
    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">Memuat…</div>
    <div v-else class="flex flex-col gap-3.5 px-6 py-5">
      <p class="text-[12.5px] leading-relaxed text-muted-4">
        Vendor yang ditandai <span class="font-bold text-brand-active">Preferred</span> menjadi acuan modal (bom_cost) bahan
        ini. Jika tidak ada yang preferred, harga <span class="font-bold">termurah</span> dipakai sebagai estimasi.
      </p>

      <div v-if="!material?.vendor_prices?.length" class="rounded-lg border border-dashed border-disabled-2 px-4 py-8 text-center text-[13px] text-muted-3">
        Belum ada harga vendor untuk bahan ini.
      </div>
      <div v-else class="flex flex-col gap-2">
        <div
          v-for="p in material.vendor_prices"
          :key="p.id"
          class="flex items-center gap-3 rounded-lg border border-line-3 bg-surface-subtle px-3.5 py-3"
        >
          <div class="flex flex-1 flex-col gap-0.5">
            <div class="flex items-center gap-2">
              <span class="text-[13px] font-bold">{{ p.vendor_name }}</span>
              <StatusPill v-if="p.is_preferred" variant="mint">Preferred</StatusPill>
            </div>
            <span class="text-[12px] text-muted-3">{{ p.notes || '' }}</span>
          </div>
          <span class="text-[14px] font-bold tracking-tight">{{ formatIDR(p.price) }}</span>
          <div class="flex gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(p)">Edit</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text" @click="confirmDelete(p)">Hapus</button>
          </div>
        </div>
      </div>

      <BaseButton variant="secondary" @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        Tambah harga vendor
      </BaseButton>
    </div>

    <BaseModal :open="showForm" :title="editingPrice ? 'Ubah harga vendor' : 'Harga vendor baru'" max-width-class="max-w-[420px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="savePrice">
        <BaseSelect
          v-if="!editingPrice"
          v-model="form.vendor_id"
          label="Vendor"
          required
          :options="vendorOptions"
          :error="formErrors.vendor_id"
        />
        <div v-else class="flex flex-col gap-1.5">
          <span class="text-[12.5px] font-semibold text-muted-4">Vendor</span>
          <span class="text-[13.5px] font-bold">{{ editingPrice.vendor_name }}</span>
        </div>
        <BaseInput v-model="form.price" type="number" min="0" label="Harga per unit" required :error="formErrors.price" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_preferred" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          Jadikan vendor preferred untuk bahan ini
        </label>
        <BaseInput v-model="form.notes" label="Catatan" :error="formErrors.notes" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">Batal</BaseButton>
          <BaseButton :loading="saving" @click="savePrice">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      title="Hapus harga vendor"
      :message="`Hapus harga dari ${deleteTarget?.vendor_name}?`"
      confirm-label="Ya, hapus"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </BaseModal>
</template>
