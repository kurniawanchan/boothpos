<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { listDiningTables, createDiningTable, updateDiningTable, deleteDiningTable } from '../../api/diningTables';
import { useToastStore } from '../../stores/toast';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';

/**
 * 028 US1 — daftar + form meja (tab "Meja" pada Pengaturan Restoran). Layar ini
 * sendiri sudah digerbang rute `dining_setup`; server tetap memeriksa ulang.
 * Galat 409 (hapus meja yang pernah dipakai, nonaktifkan meja bertab terbuka)
 * sudah ditoast oleh penangan galat pusat.
 */
const { t } = useI18n();
const toast = useToastStore();

const rows = ref([]);
const loading = ref(false);

async function load() {
  loading.value = true;
  try {
    rows.value = (await listDiningTables()).data;
  } finally {
    loading.value = false;
  }
}
onMounted(load);

const columns = computed(() => [
  { key: 'name', label: t('dining_setup.col_name') },
  { key: 'area', label: t('dining_setup.col_area') },
  { key: 'seats', label: t('dining_setup.col_seats') },
  { key: 'is_active', label: t('dining_setup.col_status') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editing = ref(null);
const form = reactive({ name: '', area: '', seats: 4, display_order: 0, is_active: true });
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function clearErrors() {
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
}

function openCreate() {
  editing.value = null;
  Object.assign(form, { name: '', area: '', seats: 4, display_order: 0, is_active: true });
  clearErrors();
  showForm.value = true;
}

function openEdit(table) {
  editing.value = table;
  Object.assign(form, { name: table.name, area: table.area, seats: table.seats, display_order: table.display_order, is_active: table.is_active });
  clearErrors();
  showForm.value = true;
}

async function save() {
  saving.value = true;
  clearErrors();
  const payload = {
    name: form.name,
    area: form.area || null,
    seats: Number(form.seats) || 1,
    display_order: Number(form.display_order) || 0,
    is_active: form.is_active,
  };
  try {
    if (editing.value) {
      await updateDiningTable(editing.value.id, payload);
      toast.success(t('dining_setup.table_updated'));
    } else {
      await createDiningTable(payload);
      toast.success(t('dining_setup.table_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

function confirmDelete(table) {
  deleteTarget.value = table;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteDiningTable(deleteTarget.value.id);
    toast.success(t('dining_setup.table_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // 409 sudah ditoast global; dialog tetap tertutup agar daftar bisa dibaca ulang.
    showDelete.value = false;
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5">
    <div class="flex justify-end">
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('dining_setup.new_table') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="rows" :loading="loading" :empty-message="t('dining_setup.no_tables')">
        <template #cell-name="{ row }"><span class="font-bold text-ink">{{ row.name }}</span></template>
        <template #cell-area="{ row }">{{ row.area || '—' }}</template>
        <template #cell-is_active="{ row }">
          <div class="flex flex-wrap gap-1.5">
            <StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
            <StatusPill v-if="row.has_open_tab" variant="warn">{{ t('dining_setup.has_open_tab') }}</StatusPill>
          </div>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal :open="showForm" :title="editing ? t('dining_setup.edit_table') : t('dining_setup.new_table')" max-width-class="max-w-[460px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="save">
        <BaseInput v-model="form.name" :label="t('dining_setup.table_name')" required maxlength="60" :error="formErrors.name" />
        <BaseInput v-model="form.area" :label="t('dining_setup.table_area')" maxlength="60" :hint="t('dining_setup.table_area_hint')" :error="formErrors.area" />
        <BaseInput v-model="form.seats" type="number" min="1" max="99" :label="t('dining_setup.table_seats')" :error="formErrors.seats" />
        <BaseInput v-model="form.display_order" type="number" :label="t('dining_setup.table_display_order')" :error="formErrors.display_order" />
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
      :open="showDelete"
      :title="t('dining_setup.delete_table')"
      :message="t('dining_setup.delete_table_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
