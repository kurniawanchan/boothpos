<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listActivityLogs } from '../api/activityLog';
import { formatDateTime } from '../utils/date';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import PageHeader from '../components/page/PageHeader.vue';
import FilterBar from '../components/page/FilterBar.vue';

/**
 * 006-purchase-order-and-ops (US8) — the backend endpoint already existed
 * (F13.4) and is already gated by canAccessMenu('reports'); this is the
 * frontend screen it never had, per research.md R7.
 */
const { t } = useI18n();

const { items, meta, loading, error, load, setPage, setFilter } = usePaginatedList(listActivityLogs);
const filters = reactive({ action: '', entity_type: '', date_from: '', date_to: '' });

onMounted(load);

function applyFilters() {
  setFilter({
    action: filters.action || undefined,
    entity_type: filters.entity_type || undefined,
    date_from: filters.date_from || undefined,
    date_to: filters.date_to || undefined,
  });
}

// 041 — "Aksi" memakai kolom cari FilterBar (tetap penyaring teks `action`); semua penyaring baru diterapkan saat
// `change` (kolom kehilangan fokus / tanggal dipilih), BUKAN tiap ketukan — sama seperti sebelumnya. Peristiwa
// `change` naik (bubble) dari input mana pun ke akar FilterBar, jadi satu pendengar cukup untuk keempat penyaring.
const filtersActive = computed(() => Object.values(filters).some((v) => v));
function resetFilters() {
  Object.assign(filters, { action: '', entity_type: '', date_from: '', date_to: '' });
  applyFilters();
}

const columns = computed(() => [
  { key: 'created_at', label: t('activity_log.col_when') },
  { key: 'user_name', label: t('activity_log.col_who') },
  { key: 'action', label: t('activity_log.col_action') },
  { key: 'entity_type', label: t('activity_log.col_entity') },
  { key: 'description', label: t('activity_log.col_description') },
]);
</script>

<template>
  <div class="pb-10">
    <PageHeader :title="t('nav.activity_log')" :description="t('nav.activity_log_subtitle')" />

    <div class="mx-[26px] border-2 border-line bg-white">
      <FilterBar
        v-model:search="filters.action"
        :search-label="t('activity_log.filter_action')"
        :placeholder="t('activity_log.filter_action')"
        :count="loading || error ? null : meta.total"
        :active="filtersActive"
        @change="applyFilters"
        @reset="resetFilters"
      >
        <BaseInput v-model="filters.entity_type" :label="t('activity_log.filter_entity_type')" />
        <BaseInput v-model="filters.date_from" type="date" :label="t('activity_log.filter_date_from')" />
        <BaseInput v-model="filters.date_to" type="date" :label="t('activity_log.filter_date_to')" />
      </FilterBar>

      <DataTable
        :columns="columns"
        :rows="items"
        :loading="loading"
        :error="error ? t('page.load_failed') : ''"
        :empty-message="t('activity_log.no_logs')"
        @retry="load"
      >
        <template #cell-created_at="{ row }"><span class="whitespace-nowrap text-[12px] text-muted-3">{{ formatDateTime(row.created_at) }}</span></template>
        <template #cell-user_name="{ row }">{{ row.user_name || '—' }}</template>
        <template #cell-action="{ row }"><span class="font-mono text-[12px] font-bold text-brand-active">{{ row.action }}</span></template>
        <template #cell-entity_type="{ row }">{{ row.entity_type }}<span v-if="row.entity_id" class="text-muted-3"> #{{ row.entity_id }}</span></template>
        <template #cell-description="{ row }"><span class="text-[12.5px]">{{ row.description }}</span></template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>
  </div>
</template>
