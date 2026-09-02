<script setup>
import { reactive, ref, onMounted } from 'vue';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listEvents, createEvent, updateEvent, updateEventStatus } from '../api/events';
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

const auth = useAuthStore();
const toast = useToastStore();

const { items, meta, params, loading, load, setPage, setFilter } = usePaginatedList(listEvents);
onMounted(load);

const STATUS_VARIANT = { draft: 'neutral', active: 'mint', closed: 'dark', cancelled: 'danger' };
const STATUS_LABEL = { draft: 'Draf', active: 'Aktif', closed: 'Selesai', cancelled: 'Dibatalkan' };

const columns = [
  { key: 'name', label: 'Nama event' },
  { key: 'location', label: 'Lokasi' },
  { key: 'dates', label: 'Tanggal' },
  { key: 'event_cost', label: 'Biaya event' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '' },
];

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
      toast.success('Event diperbarui.');
    } else {
      await createEvent(payload);
      toast.success('Event dibuat.');
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
    toast.success('Status event diperbarui.');
    await load();
  } catch {
    // 409 (masih ada sesi terbuka / transisi tidak valid) sudah ditoast
    // oleh interceptor bersama — pesan servernya sudah cukup jelas.
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <BaseSelect
        class="w-52"
        :model-value="params.status ?? ''"
        placeholder="Semua status"
        :options="[
          { value: 'draft', label: 'Draf' },
          { value: 'active', label: 'Aktif' },
          { value: 'closed', label: 'Selesai' },
          { value: 'cancelled', label: 'Dibatalkan' },
        ]"
        @update:model-value="(v) => setFilter({ status: v || undefined })"
      />
      <span class="flex-1"></span>
      <BaseButton v-if="auth.canAccessMenu('settings')" @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        Event baru
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" empty-message="Belum ada event.">
        <template #cell-location="{ row }">{{ row.location || '—' }}</template>
        <template #cell-dates="{ row }">{{ formatDate(row.start_date) }} – {{ formatDate(row.end_date) }}</template>
        <template #cell-event_cost="{ row }">{{ formatIDR(row.event_cost) }}</template>
        <template #cell-status="{ row }">
          <StatusPill :variant="STATUS_VARIANT[row.status]">{{ STATUS_LABEL[row.status] }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div v-if="auth.canAccessMenu('settings')" class="flex justify-end gap-1.5">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">Edit</button>
            <button v-if="row.status === 'draft'" type="button" class="text-[12.5px] font-semibold text-brand-active" @click="transition(row, 'active')">Aktifkan</button>
            <button v-if="row.status === 'draft'" type="button" class="text-[12.5px] font-semibold text-danger-text" @click="transition(row, 'cancelled')">Batalkan</button>
            <button v-if="row.status === 'active'" type="button" class="text-[12.5px] font-semibold text-brand-active" @click="transition(row, 'closed')">Tutup</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showForm" :title="editingEvent ? 'Ubah event' : 'Event baru'" max-width-class="max-w-[520px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveEvent">
        <BaseInput v-model="form.name" label="Nama event" required maxlength="150" :error="formErrors.name" />
        <BaseInput v-model="form.location" label="Lokasi" :error="formErrors.location" />
        <div class="grid grid-cols-2 gap-3">
          <BaseInput v-model="form.start_date" type="date" label="Tanggal mulai" required :error="formErrors.start_date" />
          <BaseInput v-model="form.end_date" type="date" label="Tanggal selesai" required :error="formErrors.end_date" />
        </div>
        <BaseInput v-model="form.event_cost" type="number" min="0" label="Biaya event (Rp)" :error="formErrors.event_cost" />
        <BaseTextarea v-model="form.notes" label="Catatan" :rows="3" :error="formErrors.notes" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">Batal</BaseButton>
          <BaseButton :loading="saving" @click="saveEvent">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
