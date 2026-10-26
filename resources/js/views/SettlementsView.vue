<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listSettlements, previewSettlement, createSettlement } from '../api/settlements';
import { listEvents } from '../api/events';
import { listArtists } from '../api/artists';
import { useToastStore } from '../stores/toast';
import { formatIDRExact } from '../utils/money';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import SettlementStatusPill from '../components/settlement/SettlementStatusPill.vue';

// 076-settlement-tab-session-branding — daftar settlement seller + dialog
// "Settlement baru". Daftar, filter, dan TOTAL semuanya dari server (total
// mengikuti baris terfilter); layar ini tidak menghitung uang sendiri.
const { t } = useI18n();
const router = useRouter();
const toast = useToastStore();

const totals = ref({ revenue_total: '0.00', net_amount: '0.00', paid_amount: '0.00', balance: '0.00' });

const events = ref([]);
const sellers = ref([]);
const eventFilter = ref('');
const sellerFilter = ref('');
const statusFilter = ref('');

const STATUSES = ['draft', 'finalized', 'acknowledged', 'partially_paid', 'settled'];

// usePaginatedList membuang `totals` dari respons; bungkus fetcher-nya di sini.
async function fetchAndKeepTotals(params) {
  const res = await listSettlements(params);
  if (res.totals) totals.value = res.totals;
  return res;
}
const list = usePaginatedList(fetchAndKeepTotals);
const { items: rows, meta: listMeta, loading: listLoading, error: listError } = list;

onMounted(async () => {
  list.load();
  try {
    events.value = (await listEvents({ per_page: 100 })).data;
    sellers.value = (await listArtists({ per_page: 100 })).data;
  } catch {
    // Filter tetap bisa dipakai tanpa opsi; galatnya sudah ditangani handler pusat.
  }
});

const eventOptions = computed(() => [{ value: '', label: t('settlements.all_events') }, ...events.value.map((e) => ({ value: e.id, label: e.name }))]);
const sellerOptions = computed(() => [{ value: '', label: t('settlements.all_sellers') }, ...sellers.value.map((a) => ({ value: a.id, label: a.name }))]);
const statusOptions = computed(() => [{ value: '', label: t('settlements.all_statuses') }, ...STATUSES.map((s) => ({ value: s, label: t(`settlements.status_${s}`) }))]);

function applyFilters() {
  list.setFilter({
    event_id: eventFilter.value || undefined,
    artist_id: sellerFilter.value || undefined,
    status: statusFilter.value || undefined,
  });
}

const columns = computed(() => [
  { key: 'number', label: t('settlements.col_number') },
  { key: 'seller', label: t('settlements.col_seller') },
  { key: 'event', label: t('settlements.col_event') },
  { key: 'display_status', label: t('settlements.col_status') },
  { key: 'revenue_total', label: t('settlements.col_revenue') },
  { key: 'net_amount', label: t('settlements.col_net') },
  { key: 'paid_amount', label: t('settlements.col_paid') },
  { key: 'balance', label: t('settlements.col_balance') },
]);

const totalCards = computed(() => [
  { key: 'revenue_total', label: t('settlements.totals_revenue') },
  { key: 'net_amount', label: t('settlements.totals_net') },
  { key: 'paid_amount', label: t('settlements.totals_paid') },
  { key: 'balance', label: t('settlements.totals_balance') },
]);

// --- Dialog settlement baru ---------------------------------------------
const showNew = ref(false);
const newEvent = ref('');
const newSeller = ref('');
const preview = ref(null);
const previewLoading = ref(false);
const creating = ref(false);

function openNew() {
  newEvent.value = '';
  newSeller.value = '';
  preview.value = null;
  showNew.value = true;
}

// Pratinjau diambil setiap event & penjual sama-sama terpilih; respons yang
// datang terlambat dari pilihan sebelumnya dibuang (token urutan).
let previewSeq = 0;
watch([newEvent, newSeller], async ([eventId, sellerId]) => {
  preview.value = null;
  if (!eventId || !sellerId) return;
  const seq = ++previewSeq;
  previewLoading.value = true;
  try {
    const res = await previewSettlement(eventId, sellerId);
    if (seq === previewSeq) preview.value = res.data;
  } catch {
    // handler pusat sudah menampilkan galatnya
  } finally {
    if (seq === previewSeq) previewLoading.value = false;
  }
});

const newEventOptions = computed(() => events.value.map((e) => ({ value: e.id, label: e.name })));
const newSellerOptions = computed(() => sellers.value.map((a) => ({ value: a.id, label: a.name })));

function openDetail(id) {
  router.push({ name: 'settlement-detail', params: { id } });
}

async function createDraft() {
  creating.value = true;
  try {
    const res = await createSettlement(newEvent.value, newSeller.value);
    showNew.value = false;
    openDetail(res.data.id);
  } catch (err) {
    if (err.status === 409 && err.data?.existing_id) {
      preview.value = { ...(preview.value ?? {}), existing_settlement_id: err.data.existing_id };
    } else if (!err.isConflict) {
      toast.error(err.message);
    }
  } finally {
    creating.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="grid flex-1 grid-cols-1 gap-2.5 sm:grid-cols-3">
        <BaseSelect v-model="eventFilter" :options="eventOptions" :aria-label="t('settlements.event')" @update:model-value="applyFilters" />
        <BaseSelect v-model="sellerFilter" :options="sellerOptions" :aria-label="t('settlements.seller')" @update:model-value="applyFilters" />
        <BaseSelect v-model="statusFilter" :options="statusOptions" :aria-label="t('settlements.col_status')" @update:model-value="applyFilters" />
      </div>
      <BaseButton @click="openNew">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('settlements.new') }}
      </BaseButton>
    </div>

    <div class="grid grid-cols-2 gap-2.5 lg:grid-cols-4" data-testid="settlement-totals">
      <div v-for="card in totalCards" :key="card.key" class="rounded-card border border-line-2 bg-white px-4 py-3">
        <div class="text-[11.5px] font-bold uppercase tracking-wider text-muted-3">{{ card.label }}</div>
        <div class="mt-1 text-[19px] font-extrabold tracking-tight tabular-nums">{{ formatIDRExact(totals[card.key]) }}</div>
      </div>
    </div>

    <p v-if="listError" role="alert" class="rounded-lg bg-danger-bg px-3.5 py-2.5 text-[13px] font-medium text-danger-text">
      {{ t('settlements.load_failed') }}
      <button type="button" class="ml-2 font-bold underline" @click="list.load()">{{ t('common.retry') }}</button>
    </p>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="rows" :loading="listLoading" :empty-message="t('settlements.empty')">
        <template #cell-number="{ row }">
          <button type="button" class="font-mono text-[12.5px] font-bold text-brand-active hover:underline" @click="openDetail(row.id)">
            {{ row.number ?? t('settlements.no_number') }}
          </button>
        </template>
        <template #cell-seller="{ row }">{{ row.seller.name }}</template>
        <template #cell-event="{ row }">{{ row.event.name }}</template>
        <template #cell-display_status="{ row }"><SettlementStatusPill :status="row.display_status" /></template>
        <template #cell-revenue_total="{ row }"><span class="tabular-nums">{{ formatIDRExact(row.revenue_total) }}</span></template>
        <template #cell-net_amount="{ row }">
          <span class="font-semibold tabular-nums" :class="Number(row.net_amount) < 0 ? 'text-danger-text' : ''">{{ formatIDRExact(row.net_amount) }}</span>
        </template>
        <template #cell-paid_amount="{ row }"><span class="tabular-nums">{{ formatIDRExact(row.paid_amount) }}</span></template>
        <template #cell-balance="{ row }"><span class="tabular-nums">{{ formatIDRExact(row.balance) }}</span></template>
      </DataTable>
      <TablePagination :meta="listMeta" @change="list.setPage" />
    </div>

    <BaseModal :open="showNew" :title="t('settlements.new_title')" max-width-class="max-w-[640px]" @close="showNew = false">
      <div class="flex flex-col gap-4 px-6 py-5">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <BaseSelect v-model="newEvent" :label="t('settlements.event')" :options="newEventOptions" :placeholder="t('settlements.select_event')" searchable />
          <BaseSelect v-model="newSeller" :label="t('settlements.seller')" :options="newSellerOptions" :placeholder="t('settlements.select_seller')" searchable />
        </div>

        <p v-if="previewLoading" class="text-[13px] text-muted-3">{{ t('common.loading_data') }}</p>

        <div v-else-if="preview" class="flex flex-col gap-2 rounded-lg border border-line-3 bg-surface-subtle p-3.5" data-testid="settlement-preview">
          <p class="text-[12px] text-muted-3">{{ t('settlements.preview_hint') }}</p>
          <div class="flex items-center justify-between text-[13px]">
            <span>{{ t('settlements.revenue_pos') }} · {{ t('settlements.units_n', { count: preview.pos_units }) }}</span>
            <span class="font-semibold tabular-nums">{{ formatIDRExact(preview.pos_revenue) }}</span>
          </div>
          <div class="flex items-center justify-between text-[13px]">
            <span>{{ t('settlements.revenue_preorder') }} · {{ t('settlements.units_n', { count: preview.preorder_units }) }}</span>
            <span class="font-semibold tabular-nums">{{ formatIDRExact(preview.preorder_revenue) }}</span>
          </div>
          <div class="flex items-center justify-between border-t border-dashed border-line-2 pt-2 text-[14px] font-bold">
            <span>{{ t('settlements.revenue_total') }}</span>
            <span class="tabular-nums">{{ formatIDRExact(preview.revenue_total) }}</span>
          </div>
          <p v-if="!preview.sources.length" class="text-[12.5px] text-muted-3">{{ t('settlements.preview_empty') }}</p>
          <p v-if="preview.existing_settlement_id" role="alert" class="flex items-center justify-between gap-2 text-[12.5px] font-semibold text-warn-text">
            {{ t('settlements.existing_warning') }}
            <button type="button" class="underline" @click="openDetail(preview.existing_settlement_id)">{{ t('settlements.open_existing') }}</button>
          </p>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showNew = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :disabled="!preview || !!preview.existing_settlement_id" :loading="creating" @click="createDraft">
            {{ t('settlements.create_draft') }}
          </BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
