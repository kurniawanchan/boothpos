<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { listSessions, listCashMovements } from '../../api/sessions';
import { usePaginatedList } from '../../composables/usePaginatedList';
import { formatIDR } from '../../utils/formatters';
import TabBar from '../page/TabBar.vue';
import TablePagination from '../ui/TablePagination.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import ShiftTable from './ShiftTable.vue';
import ShiftDetail from './ShiftDetail.vue';
import CashMovementsTable from './CashMovementsTable.vue';

/**
 * 065 / US1+US2 (research D1) — "Semua shift" di halaman Sesi Kasir, HANYA untuk owner/admin (pemanggil yang
 * merender; server tetap membatasi pengguna lain ke shift miliknya). Tiga tab: Terbuka · Tertutup · Kas masuk/keluar.
 * Shift dari aplikasi dan dari web tampil bersama, diberi label sumber. Detail satu shift dibuka di bawah tabel.
 */
const { t } = useI18n();
const tab = ref('open'); // open | closed | cash
const detailId = ref(null);

const SOURCES = computed(() => [
  { value: '', label: t('shifts.source_all') },
  { value: 'app', label: t('shifts.source_app') },
  { value: 'web', label: t('shifts.source_web') },
]);
const DIRECTIONS = computed(() => [
  { value: '', label: t('shifts.direction_all') },
  { value: 'in', label: t('shifts.cash_in') },
  { value: 'out', label: t('shifts.cash_out') },
]);

const shiftList = usePaginatedList((p) => listSessions(p), { status: 'open', per_page: 25 });

// Daftar kas membawa `totals` di samping `data` (jumlah SELURUH hasil yang disaring, bukan halaman ini), jadi
// dipegang sendiri alih-alih lewat usePaginatedList yang hanya menyimpan items/meta.
const cash = reactive({
  items: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 }, totals: { in: '0.00', out: '0.00' },
  params: { page: 1, per_page: 25, direction: '' }, loading: false, error: false,
});

async function loadShifts() {
  await shiftList.setFilter({ status: tab.value === 'closed' ? 'closed' : 'open' });
}
async function loadCash() {
  cash.loading = true;
  cash.error = false;
  try {
    const res = await listCashMovements({ ...cash.params });
    cash.items = res.data ?? [];
    if (res.meta) cash.meta = res.meta;
    cash.totals = res.totals ?? { in: '0.00', out: '0.00' };
  } catch {
    cash.error = true;
  } finally {
    cash.loading = false;
  }
}

async function onTab(key) {
  tab.value = key;
  detailId.value = null;
  if (key === 'cash') await loadCash();
  else await loadShifts();
}
async function setSource(source) {
  await shiftList.setFilter({ source });
}
async function setDirection(direction) {
  Object.assign(cash.params, { direction, page: 1 });
  await loadCash();
}
async function cashPage(page) {
  cash.params.page = page;
  await loadCash();
}

onMounted(loadShifts);

const tabs = computed(() => [
  { key: 'open', label: t('shifts.tab_open') },
  { key: 'closed', label: t('shifts.tab_closed') },
  { key: 'cash', label: t('shifts.tab_cash') },
]);
const errorText = (l) => (l.error.value ? t('shifts.load_failed') : '');
</script>

<template>
  <section class="mx-auto mt-6 max-w-[1180px] border-2 border-line bg-white" data-testid="all-shifts">
    <div class="px-5 pt-4"><h2 class="text-[15px] font-bold tracking-tight">{{ t('shifts.title') }}</h2><p class="text-[12.5px] text-muted-3">{{ t('shifts.subtitle') }}</p></div>
    <TabBar :tabs="tabs" :model-value="tab" :label="t('shifts.title')" @update:model-value="onTab" />

    <div v-if="tab !== 'cash'" class="flex flex-wrap items-center gap-3 px-5 py-3">
      <BaseSelect :model-value="shiftList.params.source ?? ''" :options="SOURCES" data-testid="filter-source" class="w-48" @update:model-value="setSource" />
    </div>
    <div v-else class="flex flex-wrap items-center gap-3 px-5 py-3">
      <BaseSelect :model-value="cash.params.direction" :options="DIRECTIONS" data-testid="filter-direction" class="w-48" @update:model-value="setDirection" />
      <span class="ml-auto text-[13px]" data-testid="cash-totals">{{ t('shifts.cash_in') }} <b>{{ formatIDR(cash.totals.in) }}</b> · {{ t('shifts.cash_out') }} <b>{{ formatIDR(cash.totals.out) }}</b></span>
    </div>

    <template v-if="tab !== 'cash'">
      <ShiftTable :rows="shiftList.items.value" :mode="tab" :loading="shiftList.loading.value" :error="errorText(shiftList)" :selected-id="detailId" @select="(id) => (detailId = id)" @retry="loadShifts" />
      <TablePagination v-if="shiftList.meta.value.total > shiftList.meta.value.per_page" :meta="shiftList.meta.value" @change="shiftList.setPage" />
    </template>
    <template v-else>
      <CashMovementsTable :rows="cash.items" :loading="cash.loading" :error="cash.error ? t('shifts.load_failed') : ''" @retry="loadCash" />
      <TablePagination v-if="cash.meta.total > cash.meta.per_page" :meta="cash.meta" @change="cashPage" />
    </template>

    <div v-if="detailId !== null && tab !== 'cash'" class="p-5"><ShiftDetail :session-id="detailId" @close="detailId = null" /></div>
  </section>
</template>
