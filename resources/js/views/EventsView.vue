<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listEvents, createEvent, updateEvent, updateEventStatus, deleteEvent } from '../api/events';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { formatIDR, toMoneyString } from '../utils/money';
import { formatDate, toDateInputValue } from '../utils/date';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';

const auth = useAuthStore();
const { t } = useI18n();
const toast = useToastStore();

// Event delete is owner/admin only server-side (EventPolicy::delete) —
// menu_keys("settings") is broader (also gates create/edit/status), so
// mirror PreordersView's isOwnerOrAdmin computed rather than reusing
// canAccessMenu('settings') for this specific control.
const isOwnerOrAdmin = computed(() => ['owner', 'admin'].includes((auth.role || '').toLowerCase()));

const { items, meta, params, loading, load, setPage, setFilter } = usePaginatedList(listEvents);
onMounted(load);

const STATUS_VARIANT = { draft: 'neutral', active: 'mint', closed: 'dark', cancelled: 'danger' };
const STATUS_LABEL = computed(() => ({
  draft: t('events_sessions.status_draft'),
  active: t('events_sessions.status_active'),
  closed: t('events_sessions.status_closed'),
  cancelled: t('events_sessions.status_cancelled'),
}));

const columns = computed(() => [
  { key: 'name', label: t('events_sessions.col_event_name') },
  { key: 'location', label: t('events_sessions.col_location') },
  { key: 'dates', label: t('events_sessions.col_dates') },
  { key: 'event_cost', label: t('events_sessions.col_event_cost') },
  { key: 'status', label: t('events_sessions.col_status') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editingEvent = ref(null);
const form = reactive({ name: '', location: '', start_date: '', end_date: '', event_cost: '0', notes: '' });
const formErrors = reactive({});
const saving = ref(false);

function openCreate() {
  editingEvent.value = null;
  Object.assign(form, { name: '', location: '', start_date: '', end_date: '', event_cost: '0', notes: '' });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(event) {
  editingEvent.value = event;
  Object.assign(form, {
    name: event.name,
    location: event.location ?? '',
    start_date: toDateInputValue(event.start_date),
    end_date: toDateInputValue(event.end_date),
    event_cost: event.event_cost,
    notes: event.notes ?? '',
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveEvent() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    name: form.name,
    location: form.location || null,
    start_date: form.start_date,
    end_date: form.end_date,
    event_cost: toMoneyString(form.event_cost || 0),
    notes: form.notes || null,
  };
  try {
    if (editingEvent.value) {
      await updateEvent(editingEvent.value.id, payload);
      toast.success(t('events_sessions.event_updated'));
    } else {
      await createEvent(payload);
      toast.success(t('events_sessions.event_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

async function transition(event, status) {
  try {
    await updateEventStatus(event.id, status);
    toast.success(t('events_sessions.event_status_updated'));
    await load();
  } catch {
    // 409 (masih ada sesi terbuka / transisi tidak valid) sudah ditoast
    // oleh interceptor bersama — pesan servernya sudah cukup jelas.
  }
}

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function confirmDelete(event) {
  deleteTarget.value = event;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteEvent(deleteTarget.value.id);
    toast.success(t('events_sessions.event_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // 409 (masih ada transaksi/pre-order) sudah ditoast oleh interceptor
    // bersama — pesan servernya sudah cukup jelas.
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <BaseSelect
        class="w-52"
        :model-value="params.status ?? ''"
        :placeholder="t('events_sessions.all_status')"
        :options="[
          { value: 'draft', label: t('events_sessions.status_draft') },
          { value: 'active', label: t('events_sessions.status_active') },
          { value: 'closed', label: t('events_sessions.status_closed') },
          { value: 'cancelled', label: t('events_sessions.status_cancelled') },
        ]"
        @update:model-value="(v) => setFilter({ status: v || undefined })"
      />
      <span class="flex-1"></span>
      <BaseButton v-if="auth.canAccessMenu('settings')" @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('events_sessions.new_event') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('events_sessions.no_events')">
        <template #cell-location="{ row }">{{ row.location || '—' }}</template>
        <template #cell-dates="{ row }">{{ formatDate(row.start_date) }} – {{ formatDate(row.end_date) }}</template>
        <template #cell-event_cost="{ row }">{{ formatIDR(row.event_cost) }}</template>
        <template #cell-status="{ row }">
          <StatusPill :variant="STATUS_VARIANT[row.status]">{{ STATUS_LABEL[row.status] }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div v-if="auth.canAccessMenu('settings')" class="flex justify-end gap-1.5">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button v-if="row.status === 'draft'" type="button" class="text-[12.5px] font-semibold text-brand-active" @click="transition(row, 'active')">{{ t('events_sessions.activate') }}</button>
            <button v-if="row.status === 'draft'" type="button" class="text-[12.5px] font-semibold text-danger-text" @click="transition(row, 'cancelled')">{{ t('events_sessions.cancel_event') }}</button>
            <button v-if="row.status === 'active'" type="button" class="text-[12.5px] font-semibold text-brand-active" @click="transition(row, 'closed')">{{ t('events_sessions.close_event') }}</button>
            <button v-if="isOwnerOrAdmin" type="button" class="text-[12.5px] font-semibold text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showForm" :title="editingEvent ? t('events_sessions.edit_event') : t('events_sessions.new_event')" max-width-class="max-w-[520px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveEvent">
        <BaseInput v-model="form.name" :label="t('events_sessions.event_name')" required maxlength="150" :error="formErrors.name" />
        <BaseInput v-model="form.location" :label="t('events_sessions.location')" :error="formErrors.location" />
        <div class="grid grid-cols-2 gap-3">
          <BaseInput v-model="form.start_date" type="date" :label="t('events_sessions.start_date')" required :error="formErrors.start_date" />
          <BaseInput v-model="form.end_date" type="date" :label="t('events_sessions.end_date')" required :error="formErrors.end_date" />
        </div>
        <BaseInput v-model="form.event_cost" type="number" min="0" :label="t('events_sessions.event_cost_rp')" :error="formErrors.event_cost" />
        <BaseTextarea v-model="form.notes" :label="t('events_sessions.notes')" :rows="3" :error="formErrors.notes" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="saveEvent">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      :title="t('events_sessions.delete_event')"
      :message="t('events_sessions.delete_event_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
