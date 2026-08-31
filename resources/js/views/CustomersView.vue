<script setup>
import { reactive, ref, onMounted } from 'vue';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listCustomers, createCustomer, updateCustomer } from '../api/customers';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';

const toast = useToastStore();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listCustomers);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);
onMounted(load);

// No "Transaksi / Pre-order / Total belanja" columns here — verified
// against CustomerResource, which only ever returns identity/contact
// fields (id, name, phone, email, social_handle, notes). The mockup shows
// those aggregate columns but the API never computes them.
const columns = [
  { key: 'name', label: 'Nama' },
  { key: 'phone', label: 'Telepon' },
  { key: 'email', label: 'Email' },
  { key: 'social_handle', label: 'Media sosial' },
  { key: 'actions', label: '' },
];

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
      toast.success('Pelanggan diperbarui.');
    } else {
      await createCustomer(payload);
      toast.success('Pelanggan dibuat.');
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="relative flex min-w-[230px] flex-1 items-center">
        <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[16px] text-muted-3" aria-hidden="true"></i>
        <label class="sr-only" for="customer-search">Cari pelanggan</label>
        <input
          id="customer-search"
          v-model="search"
          placeholder="Cari nama, telepon, atau akun media sosial…"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        Pelanggan baru
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" empty-message="Belum ada pelanggan.">
        <template #cell-phone="{ row }">{{ row.phone || '—' }}</template>
        <template #cell-email="{ row }">{{ row.email || '—' }}</template>
        <template #cell-social_handle="{ row }">{{ row.social_handle || '—' }}</template>
        <template #cell-actions="{ row }">
          <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">Edit</button>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showForm" :title="editingCustomer ? 'Ubah pelanggan' : 'Pelanggan baru'" max-width-class="max-w-[460px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveCustomer">
        <BaseInput v-model="form.name" label="Nama" required maxlength="100" :error="formErrors.name" />
        <BaseInput v-model="form.phone" label="Telepon" :error="formErrors.phone" />
        <BaseInput v-model="form.email" type="email" label="Email" :error="formErrors.email" />
        <BaseInput v-model="form.social_handle" label="Media sosial" :error="formErrors.social_handle" />
        <BaseTextarea v-model="form.notes" label="Catatan" :rows="2" :error="formErrors.notes" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">Batal</BaseButton>
          <BaseButton :loading="saving" @click="saveCustomer">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
