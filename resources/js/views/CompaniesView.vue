<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listCompanies, deleteCompany, activateCompany, deactivateCompany } from '../api/companies';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import { useToastStore } from '../stores/toast';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import CompanyOnboardingModal from '../components/companies/CompanyOnboardingModal.vue';
import CompanyEditModal from '../components/companies/CompanyEditModal.vue';

const { t } = useI18n();
const toast = useToastStore();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listCompanies);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

onMounted(load);

const columns = computed(() => [
  { key: 'name', label: t('companies.company_name') },
  { key: 'business_type', label: t('companies.business_type') },
  { key: 'license', label: t('companies.license') },
  { key: 'contact', label: t('master_data.col_contact') },
  { key: 'status', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

const showOnboarding = ref(false);
const showActivate = ref(false);
const activateTarget = ref(null);
const activating = ref(false);
const showDeactivate = ref(false);
const deactivateTarget = ref(null);
const deactivating = ref(false);
const showEdit = ref(false);
const editTarget = ref(null);
const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function confirmActivate(company) {
  activateTarget.value = company;
  showActivate.value = true;
}

function confirmDeactivate(company) {
  deactivateTarget.value = company;
  showDeactivate.value = true;
}

// 019-billing-system (third expansion) — kebalikan activate(); tidak
// menghapus company/invoice-nya sama sekali, hanya mengunci login owner
// dan mengembalikan status ke pending_activation.
async function performDeactivate() {
  deactivating.value = true;
  try {
    await deactivateCompany(deactivateTarget.value.id);
    toast.success(t('companies.deactivated_success'));
    showDeactivate.value = false;
    await load();
  } catch {
    // 409 (belum aktif) sudah ditoast oleh interceptor bersama (client.js).
  } finally {
    deactivating.value = false;
  }
}

// 019-billing-system (third expansion, research.md R14) — tidak lagi
// butuh kode dari client; activate() sudah cukup dipanggil langsung,
// backend yang menolak (409) bila belum ada Invoice 'paid'.
async function performActivate() {
  activating.value = true;
  try {
    await activateCompany(activateTarget.value.id);
    toast.success(t('companies.activated_success'));
    showActivate.value = false;
    await load();
  } catch {
    // 409 (belum ada invoice lunas / sudah aktif) sudah ditoast oleh
    // interceptor bersama (client.js).
  } finally {
    activating.value = false;
  }
}

function openEdit(company) {
  editTarget.value = company;
  showEdit.value = true;
}

function confirmDelete(company) {
  deleteTarget.value = company;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteCompany(deleteTarget.value.id);
    toast.success(t('companies.company_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // 409 (masih punya invoice) sudah ditoast oleh interceptor bersama (client.js).
  } finally {
    deleting.value = false;
  }
}

async function afterOnboarded() {
  await load();
}

async function afterUpdated() {
  await load();
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="relative flex min-w-[230px] flex-1 items-center">
        <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[16px] text-muted-3" aria-hidden="true"></i>
        <label class="sr-only" for="company-search">{{ t('companies.search_company') }}</label>
        <input
          id="company-search"
          v-model="search"
          :placeholder="t('companies.search_company_placeholder')"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <BaseButton @click="showOnboarding = true">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('companies.onboard_company') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('companies.no_companies')">
        <template #cell-business_type="{ row }">{{ row.business_type?.name ?? '—' }}</template>
        <template #cell-license="{ row }">{{ row.license?.name ?? '—' }}</template>
        <template #cell-contact="{ row }">
          <div class="flex flex-col gap-0.5 text-[12.5px] text-muted-4">
            <span>{{ row.contact_name }}</span>
            <span class="text-muted-3">{{ row.contact_email }}</span>
          </div>
        </template>
        <template #cell-status="{ row }">
          <StatusPill :variant="row.status === 'active' ? 'mint' : 'neutral'">
            {{ row.status === 'active' ? t('companies.status_active') : t('companies.status_pending') }}
          </StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button
              v-if="row.can_activate"
              type="button"
              class="text-[12.5px] font-semibold text-brand-active hover:underline"
              @click="confirmActivate(row)"
            >
              {{ t('companies.activate_btn') }}
            </button>
            <button
              v-if="row.status === 'active'"
              type="button"
              class="text-[12.5px] font-semibold text-muted-4 hover:text-danger-text"
              @click="confirmDeactivate(row)"
            >
              {{ t('companies.deactivate_btn') }}
            </button>
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">
              {{ t('common.edit') }}
            </button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text hover:text-danger-text" @click="confirmDelete(row)">
              {{ t('common.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <CompanyOnboardingModal :open="showOnboarding" @close="showOnboarding = false" @onboarded="afterOnboarded" />
    <CompanyEditModal :open="showEdit" :company="editTarget" @close="showEdit = false" @updated="afterUpdated" />

    <ConfirmDialog
      :open="showActivate"
      :title="t('companies.activate_company')"
      :message="t('companies.activate_company_confirm', { name: activateTarget?.name })"
      :confirm-label="t('companies.activate_btn')"
      :loading="activating"
      @close="showActivate = false"
      @confirm="performActivate"
    />

    <ConfirmDialog
      :open="showDeactivate"
      :title="t('companies.deactivate_company')"
      :message="t('companies.deactivate_company_confirm', { name: deactivateTarget?.name })"
      :confirm-label="t('companies.deactivate_btn')"
      :loading="deactivating"
      @close="showDeactivate = false"
      @confirm="performDeactivate"
    />

    <ConfirmDialog
      :open="showDelete"
      :title="t('companies.delete_company')"
      :message="t('companies.delete_company_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('vendors_materials.yes_delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
