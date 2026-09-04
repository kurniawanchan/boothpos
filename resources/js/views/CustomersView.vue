<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listCustomers, createCustomer, updateCustomer, deleteCustomer } from '../api/customers';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import CustomerTransactionsModal from '../components/customer/CustomerTransactionsModal.vue';

const auth = useAuthStore();
const toast = useToastStore();
const { t } = useI18n();

// Customer delete is owner/admin only server-side (CustomerPolicy::delete)
// — mirrors PreordersView's/EventsView's isOwnerOrAdmin computed since
// this view has no canAccessMenu gate today (create/edit are open to all
// roles that can reach the screen).
const isOwnerOrAdmin = computed(() => ['owner', 'admin'].includes((auth.role || '').toLowerCase()));

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listCustomers);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);
onMounted(load);

// No "Transaksi / Pre-order / Total belanja" columns here — verified
// against CustomerResource, which only ever returns identity/contact
// fields (id, name, phone, email, social_handle, notes). The mockup shows
// those aggregate columns but the API never computes them.
const columns = computed(() => [
  { key: 'name', label: t('events_sessions.col_name') },
  { key: 'phone', label: t('events_sessions.col_phone') },
  { key: 'email', label: t('events_sessions.col_email') },
  { key: 'social_handle', label: t('events_sessions.col_social') },
  { key: 'actions', label: '' },
]);

const showForm = ref(false);
const editingCustomer = ref(null);
const form = reactive({ name: '', phone: '', email: '', social_handle: '', notes: '' });
const formErrors = reactive({});
const saving = ref(false);

function openCreate() {
  editingCustomer.value = null;
  Object.assign(form, { name: '', phone: '', email: '', social_handle: '', notes: '' });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(customer) {
  editingCustomer.value = customer;
  Object.assign(form, {
    name: customer.name,
    phone: customer.phone ?? '',
    email: customer.email ?? '',
    social_handle: customer.social_handle ?? '',
    notes: customer.notes ?? '',
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveCustomer() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    name: form.name,
    phone: form.phone || null,
    email: form.email || null,
    social_handle: form.social_handle || null,
    notes: form.notes || null,
  };
  try {
    if (editingCustomer.value) {
      await updateCustomer(editingCustomer.value.id, payload);
      toast.success(t('events_sessions.customer_updated'));
    } else {
      await createCustomer(payload);
      toast.success(t('events_sessions.customer_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function confirmDelete(customer) {
  deleteTarget.value = customer;
  showDelete.value = true;
}

// Read-only, so visible to every role that can reach this screen at all
// (no isOwnerOrAdmin gate) — same visibility as the existing "edit" action.
const showTransactions = ref(false);
const transactionsCustomerId = ref(null);

function openTransactions(customer) {
  transactionsCustomerId.value = customer.id;
  showTransactions.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteCustomer(deleteTarget.value.id);
    toast.success(t('events_sessions.customer_deleted'));
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
      <div class="relative flex min-w-[230px] flex-1 items-center">
        <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[16px] text-muted-3" aria-hidden="true"></i>
        <label class="sr-only" for="customer-search">{{ t('events_sessions.search_customer') }}</label>
        <input
          id="customer-search"
          v-model="search"
          :placeholder="t('events_sessions.search_customer_placeholder')"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('events_sessions.new_customer') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('events_sessions.no_customers')">
        <template #cell-phone="{ row }">{{ row.phone || '—' }}</template>
        <template #cell-email="{ row }">{{ row.email || '—' }}</template>
        <template #cell-social_handle="{ row }">{{ row.social_handle || '—' }}</template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openTransactions(row)">{{ t('events_sessions.view_transactions') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button v-if="isOwnerOrAdmin" type="button" class="text-[12.5px] font-semibold text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showForm" :title="editingCustomer ? t('events_sessions.edit_customer') : t('events_sessions.new_customer')" max-width-class="max-w-[460px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveCustomer">
        <BaseInput v-model="form.name" :label="t('events_sessions.name')" required maxlength="100" :error="formErrors.name" />
        <BaseInput v-model="form.phone" :label="t('events_sessions.phone')" :error="formErrors.phone" />
        <BaseInput v-model="form.email" type="email" :label="t('events_sessions.email')" :error="formErrors.email" />
        <BaseInput v-model="form.social_handle" :label="t('events_sessions.social_handle')" :error="formErrors.social_handle" />
        <BaseTextarea v-model="form.notes" :label="t('events_sessions.notes')" :rows="2" :error="formErrors.notes" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="saveCustomer">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <CustomerTransactionsModal :open="showTransactions" :customer-id="transactionsCustomerId" @close="showTransactions = false" />

    <ConfirmDialog
      :open="showDelete"
      :title="t('events_sessions.delete_customer')"
      :message="t('events_sessions.delete_customer_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
