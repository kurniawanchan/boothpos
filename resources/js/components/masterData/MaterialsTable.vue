<script setup>
import { reactive, ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../../composables/usePaginatedList';
import { listMaterials, createMaterial, updateMaterial, deleteMaterial } from '../../api/materials';
import { useToastStore } from '../../stores/toast';
import { useDebouncedFn } from '../../composables/useDebouncedFn';
import DataTable from '../ui/DataTable.vue';
import TablePagination from '../ui/TablePagination.vue';
import StatusPill from '../ui/StatusPill.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import FilterBar from '../page/FilterBar.vue';
import MaterialVendorPricesModal from './MaterialVendorPricesModal.vue';

/**
 * Daftar bahan baku (pencarian, tabel, formulir, hapus, harga vendor) — dipecah UTUH dari MaterialsView (perilaku
 * identik) supaya dipakai di dua tempat: layar Bahan Baku sendiri dan tab Bahan baku / Setengah jadi / Kemasan di
 * halaman Produk (043). `kind` (opsional) membatasi daftar ke satu jenis dan memberi bawaan jenis saat membuat bahan;
 * tanpa `kind` semua jenis tampil dengan kolom "Jenis". Pemanggil membuka formulir tambah lewat `openCreate()` dan
 * memuat ulang lewat `reload()` (mis. setelah impor); `changed` memberi tahu bahwa isi berubah (hitungan tab).
 */
const props = defineProps({
  kind: { type: String, default: null }, // ingredient | semi_finished | packaging
  // Tertanam di bingkai halaman lain (tab Produk) tidak boleh membuat bingkai bertingkat: tanpa margin dan garis sendiri.
  bare: { type: Boolean, default: false },
});
const emit = defineEmits(['changed']);

const { t } = useI18n();
const toast = useToastStore();

const { items, meta, loading, error, load, setPage, setFilter } = usePaginatedList(listMaterials, props.kind ? { kind: props.kind } : {});
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

// FilterBar memancarkan nilai kolom cari; pencarian tetap di-debounce 300 ms dan dikirim sebagai `search`
// ke server persis seperti sebelumnya. "Atur ulang" langsung memuat ulang tanpa menunggu debounce.
function onSearchInput(value) {
  search.value = value;
  debouncedSearch();
}

function resetSearch() {
  search.value = '';
  setFilter({ search: undefined });
}

onMounted(load);

// Berpindah tab jenis memakai komponen yang sama: filter jenis diganti dan halaman kembali ke 1.
watch(
  () => props.kind,
  (kind) => {
    search.value = '';
    setFilter({ kind: kind || undefined, search: undefined });
  }
);

const KINDS = ['ingredient', 'semi_finished', 'packaging'];
const kindOptions = computed(() => KINDS.map((k) => ({ value: k, label: t(`vendors_materials.kind_${k}`) })));

const columns = computed(() => [
  { key: 'code', label: t('master_data.col_code') },
  { key: 'name', label: t('master_data.col_name') },
  ...(props.kind ? [] : [{ key: 'kind', label: t('vendors_materials.col_kind') }]),
  { key: 'unit', label: t('vendors_materials.col_unit') },
  { key: 'vendor_price_count', label: t('vendors_materials.col_vendor_count') },
  { key: 'is_active', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editingMaterial = ref(null);
const blankForm = () => ({ code: '', name: '', unit: '', notes: '', is_active: true, kind: props.kind || 'ingredient', standard_cost: '' });
const form = reactive(blankForm());
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

const showPrices = ref(false);
const pricesMaterialId = ref(null);

function openCreate() {
  editingMaterial.value = null;
  Object.assign(form, blankForm());
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(material) {
  editingMaterial.value = material;
  Object.assign(form, {
    code: material.code,
    name: material.name,
    unit: material.unit,
    notes: material.notes ?? '',
    is_active: material.is_active,
    kind: material.kind ?? 'ingredient',
    standard_cost: material.standard_cost ?? '',
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openPrices(material) {
  pricesMaterialId.value = material.id;
  showPrices.value = true;
}

async function saveMaterial() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    code: form.code.toUpperCase(),
    name: form.name,
    unit: form.unit,
    notes: form.notes || null,
    is_active: form.is_active,
    kind: form.kind,
  };
  // Biaya standar hanya bermakna untuk bahan setengah jadi tanpa harga vendor (resep memakainya sebagai cadangan);
  // untuk jenis lain field-nya disembunyikan dan nilainya tidak dikirim, supaya tidak menimpa diam-diam.
  if (form.kind === 'semi_finished') payload.standard_cost = form.standard_cost === '' ? null : Number(form.standard_cost);
  try {
    if (editingMaterial.value) {
      await updateMaterial(editingMaterial.value.id, payload);
      toast.success(t('vendors_materials.material_updated'));
    } else {
      await createMaterial(payload);
      toast.success(t('vendors_materials.material_created'));
    }
    showForm.value = false;
    await load();
    emit('changed');
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

function confirmDelete(material) {
  deleteTarget.value = material;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteMaterial(deleteTarget.value.id);
    toast.success(t('vendors_materials.material_deleted'));
    showDelete.value = false;
    await load();
    emit('changed');
  } catch {
    // 409 (masih dipakai harga vendor/BOM) sudah ditoast interceptor bersama.
  } finally {
    deleting.value = false;
  }
}

async function onPricesChanged() {
  await load();
  emit('changed');
}

defineExpose({ openCreate, reload: load });
</script>

<template>
  <section :class="bare ? '' : 'mx-[26px] border-2 border-line bg-white'">
    <FilterBar
      :search="search"
      :placeholder="t('vendors_materials.search_material_placeholder')"
      :search-label="t('vendors_materials.search_material')"
      :count="loading || error ? null : meta.total"
      :active="!!search"
      @update:search="onSearchInput"
      @reset="resetSearch"
    />

    <DataTable
      :columns="columns"
      :rows="items"
      :loading="loading"
      :error="error ? t('page.load_failed') : ''"
      :empty-message="t('vendors_materials.no_materials')"
      @retry="load"
    >
      <template #cell-code="{ row }"><span class="font-mono text-[12.5px] font-bold text-brand-active">{{ row.code }}</span></template>
      <template #cell-kind="{ row }"><StatusPill variant="neutral">{{ t(`vendors_materials.kind_${row.kind ?? 'ingredient'}`) }}</StatusPill></template>
      <template #cell-vendor_price_count="{ row }">{{ row.vendor_price_count ?? 0 }}</template>
      <template #cell-is_active="{ row }">
        <StatusPill :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openPrices(row)">{{ t('vendors_materials.vendor_prices_action') }}</button>
          <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
          <button type="button" class="text-[12.5px] font-semibold text-danger-text hover:text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
        </div>
      </template>
    </DataTable>
    <TablePagination :meta="meta" @change="setPage" />
  </section>

  <BaseModal :open="showForm" :title="editingMaterial ? t('vendors_materials.edit_material') : t('vendors_materials.new_material')" max-width-class="max-w-[480px]" @close="showForm = false">
    <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveMaterial">
      <BaseInput
        v-model="form.code"
        :label="t('vendors_materials.material_code')"
        maxlength="20"
        required
        :disabled="!!editingMaterial"
        :hint="t('master_data.permanent_after_creation')"
        :error="formErrors.code"
      />
      <BaseInput v-model="form.name" :label="t('vendors_materials.material_name')" required maxlength="150" :error="formErrors.name" />
      <BaseSelect v-model="form.kind" :label="t('vendors_materials.material_kind')" :options="kindOptions" :error="formErrors.kind" />
      <BaseInput v-model="form.unit" :label="t('vendors_materials.unit_with_examples')" required maxlength="20" :error="formErrors.unit" />
      <BaseInput
        v-if="form.kind === 'semi_finished'"
        v-model="form.standard_cost"
        type="number"
        min="0"
        step="any"
        :label="t('vendors_materials.standard_cost')"
        :hint="t('vendors_materials.standard_cost_hint')"
        :error="formErrors.standard_cost"
      />
      <BaseTextarea v-model="form.notes" :label="t('master_data.notes')" :rows="2" :error="formErrors.notes" />
      <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
        {{ t('common.active') }}
      </label>
    </form>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="saving" @click="saveMaterial">{{ t('common.save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>

  <ConfirmDialog
    :open="showDelete"
    :title="t('vendors_materials.delete_material')"
    :message="t('vendors_materials.delete_material_confirm', { name: deleteTarget?.name })"
    :confirm-label="t('vendors_materials.yes_delete')"
    :loading="deleting"
    @close="showDelete = false"
    @confirm="performDelete"
  />

  <MaterialVendorPricesModal :open="showPrices" :material-id="pricesMaterialId" @close="showPrices = false" @changed="onPricesChanged" />
</template>
