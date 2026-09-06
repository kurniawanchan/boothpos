<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listCompanies } from '../api/companies';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import CompanyOnboardingModal from '../components/companies/CompanyOnboardingModal.vue';
import CompanyActivationModal from '../components/companies/CompanyActivationModal.vue';

const { t } = useI18n();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listCompanies);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

onMounted(load);

const columns = computed(() => [
  { key: 'name', label: t('companies.company_name') },
  { key: 'business_type', label: t('companies.business_type') },
  { key: 'package', label: t('companies.package') },
  { key: 'contact', label: t('master_data.col_contact') },
  { key: 'status', label: t('master_data.col_status') },
  { key: 'actions', label: '' },
]);

const showOnboarding = ref(false);
const showActivation = ref(false);
const activationTarget = ref(null);

function openActivate(company) {
  activationTarget.value = company;
  showActivation.value = true;
}

async function afterOnboarded() {
  await load();
}

async function afterActivated() {
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
        <template #cell-package="{ row }">{{ row.package?.name ?? '—' }}</template>
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
              v-if="row.status !== 'active'"
              type="button"
              class="text-[12.5px] font-semibold text-brand-active hover:underline"
              @click="openActivate(row)"
            >
              {{ t('companies.activate_btn') }}
            </button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <CompanyOnboardingModal :open="showOnboarding" @close="showOnboarding = false" @onboarded="afterOnboarded" />
    <CompanyActivationModal :open="showActivation" :company="activationTarget" @close="showActivation = false" @activated="afterActivated" />
  </div>
</template>
