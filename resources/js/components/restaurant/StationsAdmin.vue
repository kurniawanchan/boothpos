<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { listStations, createStation, updateStation, deleteStation } from '../../api/stations';
import { useToastStore } from '../../stores/toast';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import { useOutletStore } from '../../stores/outlet';
import ConfirmDialog from '../ui/ConfirmDialog.vue';

/**
 * 028 US4 — tab "Stasiun" pada Pengaturan Restoran: Dapur, Bar, dll. Penugasan ke menu dilakukan
 * di form Kategori/Produk. Galat 409 (hapus stasiun yang masih dipakai) sudah ditoast global.
 */
const { t } = useI18n();
const toast = useToastStore();

const rows = ref([]);
const loading = ref(false);

async function load() {
  loading.value = true;
  try {
    rows.value = (await listStations()).data;
  } finally {
    loading.value = false;
  }
}
onMounted(load);

const columns = computed(() => [
  { key: 'name', label: t('dining_setup.st_col_name') },
  { key: 'display_order', label: t('dining_setup.st_col_order') },
  { key: 'is_active', label: t('dining_setup.col_status') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editing = ref(null);
const outletStore = useOutletStore();
const form = reactive({ name: '', display_order: 0, is_active: true, outlet_id: '' });
const formErrors = reactive({});
const saving = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

const clearErrors = () => Object.keys(formErrors).forEach((k) => delete formErrors[k]);

function openCreate() {
  editing.value = null;
  Object.assign(form, { name: '', display_order: rows.value.length, is_active: true, outlet_id: outletStore.selected ?? '' });
  clearErrors();
  showForm.value = true;
}

function openEdit(station) {
  editing.value = station;
  Object.assign(form, { name: station.name, display_order: station.display_order, is_active: station.is_active });
  clearErrors();
  showForm.value = true;
}

async function save() {
  saving.value = true;
  clearErrors();
  const payload = {
    name: form.name,
    display_order: Number(form.display_order) || 0,
    is_active: form.is_active,
    // 045 Stage 2 — outlet hanya dikirim saat membuat.
    ...(!editing.value && form.outlet_id ? { outlet_id: Number(form.outlet_id) } : {}),
  };
  try {
    if (editing.value) {
      await updateStation(editing.value.id, payload);
      toast.success(t('dining_setup.st_updated'));
    } else {
      await createStation(payload);
      toast.success(t('dining_setup.st_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteStation(deleteTarget.value.id);
    toast.success(t('dining_setup.st_deleted'));
    await load();
  } catch {
    // 409 sudah ditoast global.
  } finally {
    deleteTarget.value = null;
    deleting.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5">
    <div class="flex items-center justify-between gap-3">
      <p class="text-[12.5px] text-muted-3">{{ t('dining_setup.st_hint') }}</p>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('dining_setup.st_new') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="rows" :loading="loading" :empty-message="t('dining_setup.st_none')">
        <template #cell-name="{ row }"><span class="font-bold text-ink">{{ row.name }}</span></template>
        <template #cell-is_active="{ row }"><StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</StatusPill></template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text" @click="deleteTarget = row">{{ t('common.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal :open="showForm" :title="editing ? t('dining_setup.st_edit') : t('dining_setup.st_new')" max-width-class="max-w-[420px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="save">
        <BaseSelect
          v-if="outletStore.multi && !editing"
          v-model="form.outlet_id"
          :label="t('frame.outlet_label')"
          required
          :options="outletStore.outlets.map((o) => ({ value: o.id, label: o.name }))"
          :error="formErrors.outlet_id"
        />
        <BaseInput v-model="form.name" :label="t('dining_setup.st_name')" required maxlength="60" :error="formErrors.name" />
        <BaseInput v-model="form.display_order" type="number" :label="t('dining_setup.st_order')" :error="formErrors.display_order" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          {{ t('common.active') }}
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="!!deleteTarget"
      :title="t('dining_setup.st_delete')"
      :message="t('dining_setup.st_delete_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @close="deleteTarget = null"
      @confirm="performDelete"
    />
  </div>
</template>
