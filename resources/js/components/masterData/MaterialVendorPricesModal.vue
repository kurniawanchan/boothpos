<script setup>
import { reactive, ref, watch, computed } from 'vue';
import { useI18n } from 'vue-i18n';
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
const { t } = useI18n();
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
    toast.error(err.message || t('vendors_materials.load_vendor_prices_failed'));
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

const vendorOptions = computed(() => vendors.value.map((x) => ({ value: x.id, label: x.name })));

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
      toast.success(t('vendors_materials.vendor_price_updated'));
    } else {
      await addVendorPrice(props.materialId, { vendor_id: Number(form.vendor_id), ...payload });
      toast.success(t('vendors_materials.vendor_price_added'));
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
    toast.success(t('vendors_materials.vendor_price_deleted'));
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
  <BaseModal :open="open" :title="material ? t('vendors_materials.vendor_price_title', { material: material.name }) : t('vendors_materials.vendor_price')" max-width-class="max-w-[560px]" @close="emit('close')">
    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">{{ t('vendors_materials.loading') }}</div>
    <div v-else class="flex flex-col gap-3.5 px-6 py-5">
      <p class="text-[12.5px] leading-relaxed text-muted-4">
        {{ t('vendors_materials.preferred_explanation') }}
      </p>

      <div v-if="!material?.vendor_prices?.length" class="rounded-lg border border-dashed border-disabled-2 px-4 py-8 text-center text-[13px] text-muted-3">
        {{ t('vendors_materials.no_vendor_prices_yet') }}
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
              <StatusPill v-if="p.is_preferred" variant="mint">{{ t('vendors_materials.preferred') }}</StatusPill>
            </div>
            <span class="text-[12px] text-muted-3">{{ p.notes || '' }}</span>
          </div>
          <span class="text-[14px] font-bold tracking-tight">{{ formatIDR(p.price) }}</span>
          <div class="flex gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(p)">{{ t('common.edit') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text" @click="confirmDelete(p)">{{ t('common.delete') }}</button>
          </div>
        </div>
      </div>

      <BaseButton variant="secondary" @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('vendors_materials.add_vendor_price') }}
      </BaseButton>
    </div>

    <BaseModal :open="showForm" :title="editingPrice ? t('vendors_materials.edit_vendor_price') : t('vendors_materials.new_vendor_price')" max-width-class="max-w-[420px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="savePrice">
        <BaseSelect
          v-if="!editingPrice"
          v-model="form.vendor_id"
          :label="t('vendors_materials.vendor')"
          required
          :options="vendorOptions"
          :error="formErrors.vendor_id"
        />
        <div v-else class="flex flex-col gap-1.5">
          <span class="text-[12.5px] font-semibold text-muted-4">{{ t('vendors_materials.vendor') }}</span>
          <span class="text-[13.5px] font-bold">{{ editingPrice.vendor_name }}</span>
        </div>
        <BaseInput v-model="form.price" type="number" min="0" :label="t('vendors_materials.price_per_unit')" required :error="formErrors.price" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_preferred" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          {{ t('vendors_materials.make_preferred_for_material') }}
        </label>
        <BaseInput v-model="form.notes" :label="t('master_data.notes')" :error="formErrors.notes" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="savePrice">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      :title="t('vendors_materials.delete_vendor_price')"
      :message="t('vendors_materials.delete_vendor_price_confirm', { vendor: deleteTarget?.vendor_name })"
      :confirm-label="t('vendors_materials.yes_delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </BaseModal>
</template>
