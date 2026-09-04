<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { listEvents } from '../api/events';
import {
  artistSettlements,
  profitReport,
  artistProfitReport,
  purchasesReport,
  stockByArtistReport,
  preorderReport,
  recordSettlementPayment,
  exportReport,
} from '../api/reports';
import { formatIDR, parseMoney, toMoneyString } from '../utils/money';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import DataTable from '../components/ui/DataTable.vue';
import ArtistTransactionsModal from '../components/report/ArtistTransactionsModal.vue';
import StockByArtistDetailModal from '../components/report/StockByArtistDetailModal.vue';

// Tab "Penjualan" dikeluarkan menjadi halaman/menu tersendiri (SalesView.vue)
// — laporan penjualan terbuka untuk semua peran, sedangkan ketiga tab yang
// tersisa di sini (Rekap Artist, Modal & Untung, Modal Artist) sengaja
// dibatasi owner/admin saja (PRD 7.13: kasir tidak boleh mengakses laporan
// modal/keuntungan). Karena SEMUA tab di sini kini owner/admin-only, rute
// halaman ini sendiri digerbang lewat meta.roles di router — bukan cuma
// tab-nya — supaya kasir/inventory tidak pernah sampai ke halaman kosong.
const auth = useAuthStore();
const { t } = useI18n();
const toast = useToastStore();

const events = ref([]);
const eventId = ref('');
const activeTab = ref('settlement');

const settlements = ref(null);
const profit = ref(null);
const artistProfit = ref(null);
const purchases = ref(null);
const stockByArtist = ref(null);
const preorderStats = ref(null);
const artists = ref([]);
const purchasesStatusFilter = ref('');
const loading = ref(false);

// Digerbang di sini JUGA (bukan cuma meta.roles di router) — pola
// pertahanan berlapis yang sudah dipakai di seluruh aplikasi ini (mis.
// license gate, nav item lain yang punya `roles`). Router mencegah
// navigasi ke sini untuk peran lain, tapi komponennya sendiri tetap
// gagal aman kalau suatu saat ter-render lewat jalur lain.
const tabs = computed(() =>
  auth.canAccessMenu('reports')
    ? [
        { key: 'settlement', label: t('reports.tab_settlement') },
        { key: 'profit', label: t('reports.tab_profit') },
        // F9.5 — laporan modal & laba kotor PER ARTIST, tab terpisah dari
        // "Modal & Untung" (yang berskala event) karena angkanya sengaja
        // TIDAK dikurangi biaya event dan akan salah dibaca kalau ditumpuk
        // di tab yang sama tanpa pemisahan visual yang jelas.
        { key: 'artist-profit', label: t('reports.tab_artist_profit') },
        // 006-purchase-order-and-ops (US9/US10) — dua tab baru ini SENGAJA
        // tidak diskop event (lihat komentar di api/reports.js): pembelian
        // dan stok gudang bukan konsep per-event seperti tiga tab di atas.
        { key: 'purchases', label: t('reports.tab_purchases') },
        { key: 'stock-by-artist', label: t('reports.tab_stock_by_artist') },
        // 010-split-payment-preorder-reports (US6) — laporan pre-order baru,
        // event_id-nya OPSIONAL di backend (bukan wajib seperti tiga tab
        // event-scoped di atas), jadi tab ini sengaja TIDAK dimasukkan ke
        // EVENT_SCOPED_TABS (yang menahan pemuatan sampai eventId terisi) —
        // filter event di sini hanya mempersempit, bukan prasyarat.
        { key: 'preorder', label: t('reports.tab_preorder') },
      ]
    : []
);

// Tab yang butuh eventId dipilih sebelum bisa dimuat — dipakai watch()
// di bawah supaya tab tanpa event (purchases/stock-by-artist) tidak ikut
// menunggu eventId ter-set lebih dulu.
const EVENT_SCOPED_TABS = ['settlement', 'profit', 'artist-profit'];

// 010-split-payment-preorder-reports (US6, T036) — REUSE PreordersView.vue's
// exact status-label keys (preorders.step_* + events_sessions.status_cancelled)
// instead of inventing new wording, per the constraint to keep status copy
// consistent across the app.
const PREORDER_STATUS_LABEL = computed(() => ({
  ordered: t('preorders.step_ordered'),
  dp_paid: t('preorders.step_dp_paid'),
  arrived: t('preorders.step_arrived'),
  settled: t('preorders.step_settled'),
  handed_over: t('preorders.step_handed_over'),
  cancelled: t('events_sessions.status_cancelled'),
}));
const PAYMENT_COMPLETENESS_LABEL = computed(() => ({
  unpaid: t('reports.preorder_completeness_unpaid'),
  partial: t('reports.preorder_completeness_partial'),
  paid: t('reports.preorder_completeness_paid'),
}));

onMounted(async () => {
  events.value = (await listEvents({ per_page: 100 })).data;
  const active = events.value.find((e) => e.status === 'active');
  eventId.value = active?.id ?? events.value[0]?.id ?? '';
  await loadActiveTab();
});

watch([eventId, activeTab], loadActiveTab);
watch([purchasesStatusFilter], () => { if (activeTab.value === 'purchases') loadActiveTab(); });

async function loadActiveTab() {
  if (EVENT_SCOPED_TABS.includes(activeTab.value) && !eventId.value) return;
  loading.value = true;
  try {
    if (activeTab.value === 'settlement') {
      const res = await artistSettlements(eventId.value);
      settlements.value = res.data;
    } else if (activeTab.value === 'profit') {
      profit.value = await profitReport(eventId.value);
    } else if (activeTab.value === 'artist-profit') {
      const res = await artistProfitReport(eventId.value);
      artistProfit.value = res.data;
    } else if (activeTab.value === 'purchases') {
      purchases.value = await purchasesReport({ status: purchasesStatusFilter.value || undefined });
    } else if (activeTab.value === 'stock-by-artist') {
      // Tanpa artist_id — respons ringkasan (array per-penjual) tidak
      // berubah, per research.md R9. Detail per-varian hanya diambil saat
      // baris diklik (lihat openStockDetail / StockByArtistDetailModal),
      // BUKAN dieksekusi eagerly untuk semua penjual sekaligus.
      const res = await stockByArtistReport();
      stockByArtist.value = res.data;
    } else if (activeTab.value === 'preorder') {
      const res = await preorderReport({ event_id: eventId.value || undefined });
      // BUG YANG DITEMUKAN & DIPERBAIKI (010-split-payment-preorder-reports, verifikasi browser):
      // baris laporan ini tidak punya field `id`, sedangkan DataTable.vue memakai
      // `row[rowKey]` (default 'id') sebagai :key v-for — tanpa id, setiap baris
      // ber-key `undefined` dan Vue salah mengenali baris sebagai satu node yang
      // sama, sehingga sebagian baris (mis. status dp_paid) tidak tampil dan baris
      // lain menampilkan data yang salah. Diperbaiki dengan composite key sintetis.
      preorderStats.value = res.rows.map((row) => ({ ...row, id: `${row.status}__${row.payment_completeness}` }));
    }
  } finally {
    loading.value = false;
  }
}

async function doExport(report) {
  try {
    await exportReport(report, { event_id: eventId.value || undefined });
  } catch {
    toast.error(t('reports.export_report_failed'));
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
    toast.success(t('reports.payment_to_artist_recorded'));
    showSettlementPay.value = false;
    await loadActiveTab();
  } catch (err) {
    if (err.isValidation) toast.error(Object.values(err.errors)[0]?.[0] ?? err.message);
  } finally {
    payingSettlement.value = false;
  }
}

// F11.6 — drill-down transaksi per artist dari tab Rekap Artist.
const showArtistTransactions = ref(false);
const artistTransactionsTarget = ref(null);

function openArtistTransactions(row) {
  artistTransactionsTarget.value = row;
  showArtistTransactions.value = true;
}

// US7 (009-ui-ux-refinements) — drill-down varian per penjual dari tab
// "Stok per Penjual", dipicu HANYA saat baris diklik (lihat
// StockByArtistDetailModal untuk pemanggilan endpoint on-demand-nya).
const showStockDetail = ref(false);
const stockDetailTarget = ref(null);

function openStockDetail(row) {
  stockDetailTarget.value = row;
  showStockDetail.value = true;
}
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
      <!-- BUG YANG DITEMUKAN & DIPERBAIKI (010-split-payment-preorder-reports,
           verifikasi browser): BaseSelect's `:placeholder` hanya teks tampilan
           saat kosong, BUKAN opsi yang bisa dipilih ulang untuk mengosongkan
           `eventId` — semua tab EVENT_SCOPED_TABS lain memang mewajibkan satu
           event (ada EmptyState kalau eventId kosong), jadi celah ini baru
           kelihatan di tab Pre-order, satu-satunya tab yang boleh eventId
           kosong (preorder boleh tidak terikat event). Tanpa opsi "All events"
           eksplisit, pengguna tidak bisa kembali ke tampilan semua-event
           setelah memilih satu event. -->
      <BaseSelect
        v-if="EVENT_SCOPED_TABS.includes(activeTab) || activeTab === 'preorder'"
        class="w-56"
        v-model="eventId"
        :placeholder="t('reports.all_events')"
        :options="
          activeTab === 'preorder'
            ? [{ value: '', label: t('reports.all_events') }, ...events.map((e) => ({ value: e.id, label: e.name }))]
            : events.map((e) => ({ value: e.id, label: e.name }))
        "
      />
      <BaseSelect
        v-else-if="activeTab === 'purchases'"
        class="w-56"
        v-model="purchasesStatusFilter"
        :placeholder="t('reports.purchases_all_status')"
        :options="['draft', 'ordered', 'received', 'paid', 'cancelled'].map((s) => ({ value: s, label: t(`purchase_orders.status_${s}`) }))"
      />
      <span class="flex-1"></span>
      <BaseButton v-if="activeTab === 'settlement'" variant="secondary" @click="doExport('artist-settlements')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        {{ t('common.export_xlsx') }}
      </BaseButton>
      <BaseButton v-if="activeTab === 'profit'" variant="secondary" @click="doExport('profit')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        {{ t('common.export_xlsx') }}
      </BaseButton>
      <BaseButton v-if="activeTab === 'artist-profit'" variant="secondary" @click="doExport('artist-profit')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        {{ t('common.export_xlsx') }}
      </BaseButton>
    </div>

    <!-- Settlement tab -->
    <template v-if="activeTab === 'settlement'">
      <EmptyState v-if="!eventId" icon="ph-calendar-dots" :message="t('reports.pick_event_for_settlement')" />
      <div v-else class="overflow-hidden rounded-card border border-line-2 bg-white">
        <DataTable
          :columns="[
            { key: 'artist_name', label: t('reports.col_artist') },
            { key: 'total_units', label: t('reports.col_unit') },
            { key: 'total_sales', label: t('reports.col_sales') },
            { key: 'payable_amount', label: t('reports.col_payable') },
            { key: 'paid_amount', label: t('reports.col_paid') },
            { key: 'outstanding', label: t('reports.col_outstanding') },
            { key: 'status', label: t('reports.col_status') },
            { key: 'actions', label: '' },
          ]"
          :rows="settlements ?? []"
          :loading="loading"
          row-key="artist_id"
          :empty-message="t('reports.no_active_artists_settlement')"
        >
          <template #cell-total_sales="{ row }">{{ formatIDR(row.total_sales) }}</template>
          <template #cell-payable_amount="{ row }">{{ formatIDR(row.payable_amount) }}</template>
          <template #cell-paid_amount="{ row }">{{ formatIDR(row.paid_amount) }}</template>
          <template #cell-outstanding="{ row }">{{ formatIDR(row.outstanding) }}</template>
          <template #cell-status="{ row }">
            <span class="text-[12px] font-semibold capitalize" :class="row.status === 'paid' ? 'text-brand-active' : 'text-warn-text'">{{ row.status }}</span>
          </template>
          <template #cell-actions="{ row }">
            <div class="flex items-center justify-end gap-3">
              <!-- F11.6 — drill-down tersedia untuk artist manapun di rekap
                   ini (memakai artist_id, yang selalu ada — lihat komentar
                   row-key di atas), bukan hanya yang sudah punya baris
                   settlement, supaya "belum ada penjualan" juga bisa
                   diverifikasi langsung sebagai daftar kosong, bukan
                   kontrol yang hilang begitu saja. -->
              <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openArtistTransactions(row)">{{ t('reports.transaction_detail') }}</button>
              <!-- id is null until a real settlement row exists (an artist
                   with zero sales this event) — there is nothing to record a
                   payment against yet, so the action must stay hidden rather
                   than firing a request the backend can't resolve. -->
              <button v-if="row.id !== null && parseMoney(row.outstanding) > 0" type="button" class="text-[12.5px] font-semibold text-brand-active" @click="openSettlementPay(row)">{{ t('reports.record_payment_action') }}</button>
            </div>
          </template>
        </DataTable>
      </div>
    </template>

    <!-- Profit tab (owner/admin only — hidden entirely for cashier/inventory) -->
    <template v-else-if="activeTab === 'profit'">
      <EmptyState v-if="!eventId" icon="ph-calendar-dots" :message="t('reports.pick_event_for_profit')" />
      <div v-else-if="profit" class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.revenue') }}</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.revenue) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.cost_of_goods') }}</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.cost_of_goods) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.gross_profit') }}</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.gross_profit) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.event_cost') }}</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(profit.event_cost) }}</span></div>
        <div class="flex flex-col gap-1.5 rounded-card border border-mint-border bg-mint-50 p-4"><span class="text-[11.5px] font-semibold text-brand-active">{{ t('reports.net_profit') }}</span><span class="text-[21px] font-extrabold tracking-tight text-brand-active">{{ formatIDR(profit.net_profit) }}</span></div>
      </div>
      <p class="text-[11.5px] leading-relaxed text-muted-3">{{ t('reports.profit_snapshot_note') }}</p>
    </template>

    <!-- Artist profit tab (F9.5, owner/admin only) -->
    <template v-else-if="activeTab === 'artist-profit'">
      <EmptyState v-if="!eventId" icon="ph-calendar-dots" :message="t('reports.pick_event_for_artist_profit')" />
      <template v-else>
        <!-- Penjelasan ini WAJIB ada — angka di sini sengaja tidak dikurangi
             event_cost (lihat kriteria penerimaan F9.5), dan tanpa catatan
             ini pengguna gampang membacanya sebagai versi turunan/salah
             dari "Modal & Untung" tingkat event, padahal itu laporan lain. -->
        <div class="flex items-start gap-2.5 rounded-lg border border-line-2 bg-surface-subtle px-4 py-3">
          <i class="ph-duotone ph-info text-[16px] text-muted-3" aria-hidden="true"></i>
          <p class="text-[12px] leading-relaxed text-muted-3">
            <i18n-t keypath="reports.artist_profit_note" tag="span">
              <template #bold><span class="font-semibold text-muted-4">{{ t('reports.artist_profit_note_bold') }}</span></template>
            </i18n-t>
          </p>
        </div>
        <div class="overflow-hidden rounded-card border border-line-2 bg-white">
          <DataTable
            :columns="[
              { key: 'artist_name', label: t('reports.col_artist') },
              { key: 'total_sales', label: t('reports.col_sales') },
              { key: 'modal', label: t('reports.col_modal') },
              { key: 'gross_profit', label: t('reports.col_gross_profit') },
            ]"
            :rows="artistProfit ?? []"
            :loading="loading"
            row-key="artist_id"
            :empty-message="t('reports.no_artist_sales')"
          >
            <template #cell-total_sales="{ row }">{{ formatIDR(row.total_sales) }}</template>
            <template #cell-modal="{ row }">{{ formatIDR(row.modal) }}</template>
            <template #cell-gross_profit="{ row }"><span class="font-semibold text-brand-active">{{ formatIDR(row.gross_profit) }}</span></template>
          </DataTable>
        </div>
      </template>
    </template>

    <!-- Purchases tab (006-purchase-order-and-ops US9) -->
    <template v-else-if="activeTab === 'purchases'">
      <div v-if="purchases" class="flex flex-col gap-3.5">
        <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
          <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.purchases_po_count') }}</span><span class="text-[21px] font-extrabold tracking-tight">{{ purchases.totals.po_count }}</span></div>
          <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.purchases_total_amount') }}</span><span class="text-[21px] font-extrabold tracking-tight">{{ formatIDR(purchases.totals.total_amount) }}</span></div>
        </div>
        <div class="overflow-hidden rounded-card border border-line-2 bg-white">
          <DataTable
            :columns="[
              { key: 'po_number', label: t('purchase_orders.col_number') },
              { key: 'vendor_name', label: t('purchase_orders.col_vendor') },
              { key: 'status', label: t('purchase_orders.col_status') },
              { key: 'created_at', label: t('reports.col_date') },
              { key: 'total_amount', label: t('purchase_orders.col_total') },
            ]"
            :rows="purchases.rows ?? []"
            :loading="loading"
            :empty-message="t('reports.no_purchases')"
          >
            <template #cell-status="{ row }"><span class="text-[12px] font-semibold capitalize">{{ t(`purchase_orders.status_${row.status}`) }}</span></template>
            <template #cell-created_at="{ row }">{{ new Date(row.created_at).toLocaleDateString('id-ID') }}</template>
            <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
          </DataTable>
        </div>
      </div>
    </template>

    <!-- Stock-by-artist tab (006-purchase-order-and-ops US10) -->
    <template v-else-if="activeTab === 'stock-by-artist'">
      <div class="overflow-hidden rounded-card border border-line-2 bg-white">
        <DataTable
          :columns="[
            { key: 'artist_name', label: t('reports.col_artist') },
            { key: 'variant_count', label: t('reports.stock_col_variant_count') },
            { key: 'total_stock', label: t('reports.stock_col_total_stock') },
            { key: 'actions', label: '' },
          ]"
          :rows="stockByArtist ?? []"
          :loading="loading"
          row-key="artist_id"
          :empty-message="t('reports.no_stock_data')"
        >
          <template #cell-actions="{ row }">
            <div class="flex justify-end">
              <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openStockDetail(row)">{{ t('reports.stock_detail_action') }}</button>
            </div>
          </template>
        </DataTable>
      </div>
    </template>

    <!-- Preorder tab (010-split-payment-preorder-reports US6) — satu baris
         per kombinasi status × payment_completeness, persis seperti bentuk
         agregasi yang dikembalikan API (lihat research.md R6). -->
    <template v-else-if="activeTab === 'preorder'">
      <div class="overflow-hidden rounded-card border border-line-2 bg-white">
        <DataTable
          :columns="[
            { key: 'status', label: t('reports.col_status') },
            { key: 'payment_completeness', label: t('reports.preorder_col_payment_completeness') },
            { key: 'preorder_count', label: t('reports.preorder_col_count') },
            { key: 'total_order_value', label: t('reports.preorder_col_order_value') },
            { key: 'total_collected', label: t('reports.preorder_col_collected') },
            { key: 'total_outstanding', label: t('reports.preorder_col_outstanding') },
          ]"
          :rows="preorderStats ?? []"
          :loading="loading"
          :empty-message="t('reports.no_preorder_report_data')"
        >
          <template #cell-status="{ row }">{{ PREORDER_STATUS_LABEL[row.status] ?? row.status }}</template>
          <template #cell-payment_completeness="{ row }">{{ PAYMENT_COMPLETENESS_LABEL[row.payment_completeness] ?? row.payment_completeness }}</template>
          <template #cell-total_order_value="{ row }">{{ formatIDR(row.total_order_value) }}</template>
          <template #cell-total_collected="{ row }">{{ formatIDR(row.total_collected) }}</template>
          <template #cell-total_outstanding="{ row }">{{ formatIDR(row.total_outstanding) }}</template>
        </DataTable>
      </div>
    </template>

    <BaseModal :open="showSettlementPay" :title="t('reports.record_payment_to_artist')" max-width-class="max-w-[400px]" @close="showSettlementPay = false">
      <div class="flex flex-col gap-3.5 px-6 py-5">
        <p class="text-[13px] text-muted-4">{{ t('reports.remaining_amount', { artist: settlementTarget?.artist_name, amount: formatIDR(settlementTarget?.outstanding) }) }}</p>
        <BaseInput v-model="settlementAmount" type="number" min="0" :label="t('reports.amount_paid_rp')" />
        <BaseInput v-model="settlementNotes" :label="t('reports.notes_optional')" />
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showSettlementPay = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="payingSettlement" @click="submitSettlementPayment">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ArtistTransactionsModal
      :open="showArtistTransactions"
      :artist-id="artistTransactionsTarget?.artist_id"
      :artist-name="artistTransactionsTarget?.artist_name"
      :event-id="eventId"
      @close="showArtistTransactions = false"
    />

    <StockByArtistDetailModal
      :open="showStockDetail"
      :artist-id="stockDetailTarget?.artist_id"
      :artist-name="stockDetailTarget?.artist_name"
      @close="showStockDetail = false"
    />
  </div>
</template>
