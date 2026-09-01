<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { listEvents } from '../api/events';
import { salesReport, artistSettlements, profitReport, recordSettlementPayment, exportReport } from '../api/reports';
import { formatIDR, parseMoney, toMoneyString } from '../utils/money';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import DataTable from '../components/ui/DataTable.vue';

const auth = useAuthStore();
const toast = useToastStore();

const events = ref([]);
const eventId = ref('');
const activeTab = ref('sales');
const groupBy = ref('product');

const sales = ref(null);
const settlements = ref(null);
const profit = ref(null);
const loading = ref(false);

const tabs = computed(() => {
  const t = [{ key: 'sales', label: 'Penjualan' }];
  if (auth.isOwnerOrAdmin) t.push({ key: 'settlement', label: 'Rekap Artist' }, { key: 'profit', label: 'Modal & Untung' });
  return t;
});

onMounted(async () => {
  events.value = (await listEvents({ per_page: 100 })).data;
  const active = events.value.find((e) => e.status === 'active');
  eventId.value = active?.id ?? events.value[0]?.id ?? '';
  await loadActiveTab();
});

watch([eventId, activeTab, groupBy], loadActiveTab);

async function loadActiveTab() {
  loading.value = true;
  try {
    if (activeTab.value === 'sales') {
      sales.value = await salesReport({ event_id: eventId.value || undefined, group_by: groupBy.value });
    } else if (activeTab.value === 'settlement' && eventId.value) {
      const res = await artistSettlements(eventId.value);
      settlements.value = res.data;
    } else if (activeTab.value === 'profit' && eventId.value) {
      profit.value = await profitReport(eventId.value);
    }
  } finally {
    loading.value = false;
  }
}

async function doExport(report) {
  try {
    await exportReport(report, { event_id: eventId.value || undefined });
  } catch {
    toast.error('Gagal mengekspor laporan.');
  }
}

const showSettlementPay = ref(false);
const settlementTarget = ref(null);
const settlementAmount = ref('0');
const settlementNotes = ref('');
const payingSettlement = ref(false);

function openSettlementPay(row) {
  settlementTarget.value = row;
  settlementAmount.value = row.outstanding;
  settlementNotes.value = '';
  showSettlementPay.value = true;
}

async function submitSettlementPayment() {
  payingSettlement.value = true;
  try {
    await recordSettlementPayment(settlementTarget.value.id, {
      amount: toMoneyString(settlementAmount.value),
      notes: settlementNotes.value || null,
    });
    toast.success('Pembayaran ke artist tercatat.');
    showSettlementPay.value = false;
    await loadActiveTab();
  } catch (err) {
    if (err.isValidation) toast.error(Object.values(err.errors)[0]?.[0] ?? err.message);
  } finally {
    payingSettlement.value = false;
  }
}

const salesColumns = computed(() => [{ key: 'label', label: groupBy.value === 'day' ? 'Tanggal' : 'Label' }, { key: 'unit_count', label: 'Unit' }, { key: 'amount', label: 'Jumlah' }]);
</script>

<template>
  <div class="flex flex-col gap-4 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="flex gap-1.5 rounded-lg bg-track p-1">
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          class="rounded-md px-3.5 py-2 text-[13px] font-bold transition-colors"
          :class="activeTab === t.key ? 'bg-white text-brand-active shadow-sm' : 'text-muted-4'"
          @click="activeTab = t.key"
        >
          {{ t.label }}
        </button>
      </div>
      <BaseSelect class="w-56" v-model="eventId" placeholder="Semua event" :options="events.map((e) => ({ value: e.id, label: e.name }))" />
      <BaseSelect
        v-if="activeTab === 'sales'"
        class="w-40"
        v-model="groupBy"
        :options="[
          { value: 'product', label: 'Per produk' },
          { value: 'category', label: 'Per kategori' },
          { value: 'artist', label: 'Per artist' },
          { value: 'day', label: 'Per hari' },
        ]"
      />
      <span class="flex-1"></span>
      <BaseButton v-if="activeTab === 'sales'" variant="secondary" @click="doExport('sales')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        Ekspor .xlsx
      </BaseButton>
      <BaseButton v-if="activeTab === 'settlement'" variant="secondary" @click="doExport('artist-settlements')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        Ekspor .xlsx
      </BaseButton>
      <BaseButton v-if="activeTab === 'profit'" variant="secondary" @click="doExport('profit')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        Ekspor .xlsx
      </BaseButton>
    </div>

    <!-- Sales tab -->
    <template v-if="activeTab === 'sales'">
      <div class="grid grid-cols-2 gap-3.5 sm:grid-cols-4">
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Transaksi</span><span class="text-[23px] font-extrabold tracking-tight">{{ sales?.totals?.order_count ?? 0 }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Unit terjual</span><span class="text-[23px] font-extrabold tracking-tight">{{ sales?.totals?.unit_count ?? 0 }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Penjualan kotor</span><span class="text-[23px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.gross_sales ?? 0) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Penjualan bersih</span><span class="text-[23px] font-extrabold tracking-tight">{{ formatIDR(sales?.totals?.net_sales ?? 0) }}</span></div>
      </div>
      <div class="overflow-hidden rounded-card border border-line-2 bg-white">
        <DataTable :columns="salesColumns" :rows="sales?.rows ?? []" :loading="loading" empty-message="Belum ada data penjualan.">
          <template #cell-amount="{ row }">{{ formatIDR(row.amount) }}</template>
        </DataTable>
      </div>
    </template>

    <!-- Settlement tab (owner/admin only) -->
    <template v-else-if="activeTab === 'settlement'">
      <EmptyState v-if="!eventId" icon="ph-calendar-dots" message="Pilih event untuk melihat rekap artist." />
      <div v-else class="overflow-hidden rounded-card border border-line-2 bg-white">
        <DataTable
          :columns="[
            { key: 'artist_name', label: 'Artist' },
            { key: 'total_units', label: 'Unit' },
            { key: 'total_sales', label: 'Penjualan' },
            { key: 'payable_amount', label: 'Wajib dibayar' },
            { key: 'paid_amount', label: 'Sudah dibayar' },
            { key: 'outstanding', label: 'Sisa' },
            { key: 'status', label: 'Status' },
            { key: 'actions', label: '' },
          ]"
          :rows="settlements ?? []"
          :loading="loading"
          row-key="artist_id"
          empty-message="Belum ada penjualan untuk event ini."
        >
          <template #cell-total_sales="{ row }">{{ formatIDR(row.total_sales) }}</template>
          <template #cell-payable_amount="{ row }">{{ formatIDR(row.payable_amount) }}</template>
          <template #cell-paid_amount="{ row }">{{ formatIDR(row.paid_amount) }}</template>
          <template #cell-outstanding="{ row }">{{ formatIDR(row.outstanding) }}</template>
          <template #cell-status="{ row }">
            <span class="text-[12px] font-semibold capitalize" :class="row.status === 'paid' ? 'text-brand-active' : 'text-warn-text'">{{ row.status }}</span>
          </template>
          <template #cell-actions="{ row }">
            <button v-if="parseMoney(row.outstanding) > 0" type="button" class="text-[12.5px] font-semibold text-brand-active" @click="openSettlementPay(row)">Catat bayar</button>
          </template>
        </DataTable>
      </div>
    </template>

    <!-- Profit tab (owner/admin only — hidden entirely for cashier/inventory) -->
    <template v-else-if="activeTab === 'profit'">
      <EmptyState v-if="!eventId" icon="ph-calendar-dots" message="Pilih event untuk melihat laporan modal & keuntungan." />
      <div v-else-if="profit" class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Pendapatan</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.revenue) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Harga pokok</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.cost_of_goods) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Laba kotor</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.gross_profit) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">Biaya event</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.event_cost) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-mint-border bg-mint-50 p-4"><span class="text-[11.5px] font-semibold text-brand-active">Laba bersih</span><span class="text-[21px] font-extrabold tracking-tight text-brand-active">{{ formatIDR(profit.net_profit) }}</span></div>
      </div>
      <p class="text-[11.5px] leading-relaxed text-muted-3">Laba kotor memakai nilai snapshot pada order_items, bukan harga master saat laporan dibuka.</p>
    </template>

    <BaseModal :open="showSettlementPay" title="Catat pembayaran ke artist" max-width-class="max-w-[400px]" @close="showSettlementPay = false">
      <div class="flex flex-col gap-3.5 px-6 py-5">
        <p class="text-[13px] text-muted-4">{{ settlementTarget?.artist_name }} · sisa {{ formatIDR(settlementTarget?.outstanding) }}</p>
        <BaseInput v-model="settlementAmount" type="number" min="0" label="Jumlah dibayar (Rp)" />
        <BaseInput v-model="settlementNotes" label="Catatan (opsional)" />
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showSettlementPay = false">Batal</BaseButton>
          <BaseButton :loading="payingSettlement" @click="submitSettlementPayment">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
