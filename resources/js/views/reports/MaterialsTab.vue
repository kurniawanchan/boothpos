<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { createMaterialPurchaseDrafts, getMaterialPosition } from '../../api/reports';
import { uuid } from '../../utils/uuid';
import BaseButton from '../../components/ui/BaseButton.vue';
import { useAuthStore } from '../../stores/auth';
import { useOutletStore } from '../../stores/outlet';
import { useOutletPageFilter } from '../../composables/useOutletPageFilter';
import BaseMultiSelect from '../../components/ui/BaseMultiSelect.vue';
import { formatIDR } from '../../utils/money';
import { STATUSES, daysLeftText, loadPrefs, savePrefs, statusTone } from '../../utils/materialReport';
import StatusPill from '../../components/ui/StatusPill.vue';
import BaseSelect from '../../components/ui/BaseSelect.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import MaterialUsagePanel from '../../components/report/MaterialUsagePanel.vue';

/**
 * 088 — tab Laporan → Bahan. Memuat datanya sendiri (parameter per pengguna + outlet navbar), karena tab ini tidak
 * memakai periode/filter outlet bersama milik ReportsView. Semua angka dari server (MaterialReport); klien hanya
 * menampilkan. Kartu ringkasan = saringan status (klik), dihitung server SEBELUM saringan status sehingga tetap lengkap.
 */
const { t } = useI18n();
const route = useRoute();
const auth = useAuthStore();
const outletStore = useOutletStore();
// Filter Outlet halaman: terisi dari outlet navbar dan mengikutinya, tetapi boleh memilih outlet lain / semua (pola 071).
const outletFilter = useOutletPageFilter();
const outletModel = computed({
  get: () => outletFilter.ids.value,
  set: (ids) => outletFilter.set(ids),
});

const prefs = reactive(loadPrefs(auth.user?.id));
const sub = ref('position');
const initialStatus = route?.query?.status;
const filters = reactive({
  status: STATUSES.includes(initialStatus) ? [initialStatus] : [],
  kind: '',
  search: '',
});

const data = ref(null);
const loading = ref(false);
const failed = ref(false);
let seq = 0;

async function load() {
  const mine = ++seq;
  loading.value = true;
  failed.value = false;
  try {
    const res = await getMaterialPosition(exportParams());
    if (mine === seq) data.value = res;
  } catch {
    if (mine === seq) failed.value = true;
  } finally {
    if (mine === seq) loading.value = false;
  }
}

onMounted(load);
watch(() => outletFilter.ids.value.join(','), load);
watch(() => [filters.status.join(','), filters.kind], load);
let searchTimer = null;
watch(() => filters.search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(load, 300); });

// Parameter angka: hanya bilangan bulat dalam batas server yang dikirim dan diingat; isian setengah jadi diabaikan.
const LIMITS = { window_days: [1, 90], risk_days: [1, 60], target_days: [1, 90] };
function setPref(key, raw) {
  const n = Number(raw);
  const [min, max] = LIMITS[key];
  if (!Number.isInteger(n) || n < min || n > max || prefs[key] === n) return;
  prefs[key] = n;
  savePrefs(auth.user?.id, { ...prefs });
  load();
}

function toggleStatus(s) {
  filters.status = filters.status.length === 1 && filters.status[0] === s ? [] : [s];
}

const rows = computed(() => data.value?.data ?? []);
const summary = computed(() => data.value?.summary ?? null);
// Kolom Outlet tampil bila baris bisa berasal dari lebih dari satu outlet (semua outlet, atau beberapa dipilih).
const showOutlet = computed(() => outletStore.multi && outletFilter.ids.value.length !== 1);
const kindOptions = computed(() => [
  { value: '', label: t('vendors_materials.filter_kind_all') },
  ...['ingredient', 'semi_finished', 'packaging'].map((k) => ({ value: k, label: t(`vendors_materials.kind_${k}`) })),
]);

// --- 088 US2: saran pembelian → draf PO per vendor -----------------------------------------------------------------
// Tombol dan centang TERSEMBUNYI tanpa izin membuat PO (Konstitusi III); server tetap menegakkan (403).
const canDraft = computed(() => auth.canAccessMenu('purchase_orders'));
const picks = reactive({}); // material_id → qty (string, bisa diubah)
const pickable = (r) => canDraft.value && r.suggestion && r.suggestion.vendor_id !== null;
const pickKey = (r) => `${r.material_id}:${r.outlet_id}`;
function togglePick(r) {
  if (picks[pickKey(r)] !== undefined) delete picks[pickKey(r)];
  else picks[pickKey(r)] = String(Number(r.suggestion.qty));
}
const pickedCount = computed(() => Object.keys(picks).length);
const needOutlet = ref(false);
const drafting = ref(false);
const draftResult = ref(null);
const materialName = (id) => rows.value.find((r) => r.material_id === id)?.name ?? `#${id}`;

async function createDrafts() {
  // Draf PO butuh SATU outlet (server memakai header, bukan body): hanya bila filter tepat satu outlet.
  if (outletStore.multi && outletFilter.ids.value.length !== 1) {
    needOutlet.value = true;
    return;
  }
  needOutlet.value = false;
  const lines = Object.entries(picks)
    .map(([key, v]) => ({ material_id: Number(key.split(':')[0]), qty: Number(v) }))
    .filter((l) => l.qty > 0);
  if (!lines.length) return;
  drafting.value = true;
  try {
    draftResult.value = await createMaterialPurchaseDrafts(lines, uuid(), outletFilter.ids.value[0] ?? null);
    Object.keys(picks).forEach((k) => delete picks[k]);
  } catch (err) {
    // 422 "semua ditolak" membawa daftar penolakan; galat lain ditoast handler pusat.
    if (err?.data?.refused) draftResult.value = { created: [], refused: err.data.refused };
  } finally {
    drafting.value = false;
  }
}

// 088 US5 — parameter ekspor = yang sedang tampil (dipakai tombol ekspor kepala halaman ReportsView).
function exportParams() {
  return {
    ...prefs,
    ...(outletFilter.ids.value.length ? { outlet_id: outletFilter.ids.value } : {}),
    ...(filters.status.length ? { status: filters.status } : {}),
    ...(filters.kind ? { kind: [filters.kind] } : {}),
    ...(filters.search.trim() ? { search: filters.search.trim() } : {}),
  };
}
defineExpose({ exportParams });

const qty = (v) => {
  const n = Number(v);
  return `${n < 0 ? '−' : ''}${Math.abs(n).toLocaleString('id-ID', { maximumFractionDigits: 3 })}`;
};
const money = (v) => (v === null || v === undefined ? '—' : formatIDR(Number(v)));
</script>

<template>
  <div class="flex flex-col gap-4 px-5 py-4" data-testid="materials-tab">
    <!-- 088 — dua sub-tab: posisi & risiko (bawaan) dan pemakaian per periode. Jenis & cari berlaku untuk keduanya. -->
    <div class="flex flex-wrap items-center gap-3">
      <div class="inline-flex border-2 border-line" role="tablist">
        <button v-for="s in ['position', 'usage']" :key="s" type="button" role="tab" :aria-selected="sub === s" class="h-9 px-3.5 text-[13px] font-semibold" :class="sub === s ? 'bg-brand text-white' : 'text-muted-5 hover:text-brand-active'" :data-testid="`mat-subtab-${s}`" @click="sub = s">{{ t(`reports.mat_subtab_${s}`) }}</button>
      </div>
      <BaseMultiSelect
        v-if="outletFilter.visible.value"
        v-model="outletModel"
        :options="outletFilter.options.value"
        :all-label="t('reports.mat_filter_outlet_all')"
        class="w-60"
        data-testid="mat-outlet-filter"
      />
      <BaseSelect v-model="filters.kind" :options="kindOptions" class="w-44" data-testid="mat-kind" />
      <input v-model="filters.search" type="search" :placeholder="t('vendors_materials.search_material_placeholder')" class="h-9 min-w-[200px] flex-1 border-2 border-line bg-white px-2.5 text-[13px] sm:max-w-[320px]" data-testid="mat-search" />
    </div>

    <MaterialUsagePanel v-if="sub === 'usage'" :kind="filters.kind" :search="filters.search" :outlet-ids="outletFilter.ids.value" />
    <template v-else>
    <!-- Ringkasan: tiap kartu status = saringan (klik lagi untuk melepas). -->
    <div v-if="summary" class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
      <button
        v-for="s in STATUSES"
        :key="s"
        type="button"
        class="flex flex-col items-start gap-1 border-2 px-3 py-2.5 text-left"
        :class="filters.status.includes(s) ? 'border-brand bg-mint-100' : 'border-line bg-white hover:border-brand'"
        :aria-pressed="filters.status.includes(s)"
        :data-testid="`mat-summary-${s}`"
        @click="toggleStatus(s)"
      >
        <StatusPill :variant="statusTone(s)">{{ t(`reports.mat_status_${s}`) }}</StatusPill>
        <span class="text-[22px] font-extrabold tabular-nums">{{ summary[s] }}</span>
      </button>
      <div class="flex flex-col gap-1 border-2 border-line bg-white px-3 py-2.5" data-testid="mat-summary-products">
        <span class="text-[11.5px] font-semibold text-muted-4">{{ t('reports.mat_at_risk_products') }}</span>
        <span class="text-[22px] font-extrabold tabular-nums">{{ summary.at_risk_products }}</span>
      </div>
      <div class="flex flex-col gap-1 border-2 border-line bg-white px-3 py-2.5" data-testid="mat-summary-value">
        <span class="text-[11.5px] font-semibold text-muted-4">{{ t('reports.mat_stock_value') }}</span>
        <span class="text-[17px] font-extrabold tabular-nums">{{ money(summary.stock_value) }}</span>
      </div>
    </div>

    <div class="flex flex-wrap items-end gap-3">
      <label class="flex flex-col gap-1 text-[12px] font-semibold text-muted-4">
        {{ t('reports.mat_window_days') }}
        <input :value="prefs.window_days" type="number" min="1" max="90" class="h-9 w-24 border-2 border-line bg-white px-2 text-[13px]" data-testid="mat-window-days" @input="setPref('window_days', $event.target.value)" />
      </label>
      <label class="flex flex-col gap-1 text-[12px] font-semibold text-muted-4">
        {{ t('reports.mat_target_days') }}
        <input :value="prefs.target_days" type="number" min="1" max="90" class="h-9 w-24 border-2 border-line bg-white px-2 text-[13px]" data-testid="mat-target-days" @input="setPref('target_days', $event.target.value)" />
      </label>
      <label class="flex flex-col gap-1 text-[12px] font-semibold text-muted-4">
        {{ t('reports.mat_risk_days') }}
        <input :value="prefs.risk_days" type="number" min="1" max="60" class="h-9 w-24 border-2 border-line bg-white px-2 text-[13px]" data-testid="mat-risk-days" @input="setPref('risk_days', $event.target.value)" />
      </label>
      <span v-if="data" class="ml-auto text-[11.5px] text-muted-3">{{ t('reports.mat_basis', { days: data.params.window_days }) }}</span>
    </div>

    <div v-if="canDraft" class="flex flex-wrap items-center gap-3">
      <BaseButton :disabled="!pickedCount" :loading="drafting" data-testid="mat-create-drafts" @click="createDrafts">
        <i class="ph-duotone ph-shopping-cart text-[16px]" aria-hidden="true"></i>
        {{ t('reports.mat_create_drafts', { n: pickedCount }) }}
      </BaseButton>
      <span v-if="needOutlet" role="alert" class="text-[12.5px] font-semibold text-danger-text">{{ t('reports.mat_pick_outlet') }}</span>
    </div>
    <div v-if="draftResult" class="border-2 border-line bg-white px-4 py-3 text-[13px]" role="status" data-testid="mat-drafts-result">
      <p v-for="c in draftResult.created" :key="c.purchase_order_id">
        {{ t('reports.mat_draft_created', { po: c.po_number, vendor: c.vendor, total: money(c.total) }) }}
        <RouterLink :to="{ name: 'purchase-orders' }" class="ml-1 font-semibold text-brand-active underline">{{ t('reports.mat_open_po') }}</RouterLink>
      </p>
      <p v-for="r in draftResult.refused" :key="r.material_id" class="text-danger-text">{{ materialName(r.material_id) }}: {{ t(`reports.mat_refused_${r.reason}`) }}</p>
    </div>

    <ErrorState v-if="failed" @retry="load" />
    <div v-else class="overflow-x-auto border-2 border-line bg-white">
      <table class="w-full text-[13px]">
        <thead class="bg-surface-subtle text-left text-[11.5px] font-bold uppercase tracking-wide text-muted-4">
          <tr>
            <th class="px-3 py-2.5">{{ t('reports.mat_col_material') }}</th>
            <th v-if="showOutlet" class="px-3 py-2.5">{{ t('stock.col_outlet') }}</th>
            <th class="px-3 py-2.5 text-right">{{ t('reports.mat_col_on_hand') }}</th>
            <th class="px-3 py-2.5 text-right">{{ t('reports.mat_col_daily_usage') }}</th>
            <th class="px-3 py-2.5">{{ t('reports.mat_col_days_left') }}</th>
            <th class="px-3 py-2.5">{{ t('reports.mat_col_threatened') }}</th>
            <th class="px-3 py-2.5 text-right">{{ t('reports.mat_col_value') }}</th>
            <th class="px-3 py-2.5">{{ t('reports.mat_col_suggestion') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading && !rows.length"><td :colspan="showOutlet ? 8 : 7" class="px-3 py-6 text-center text-muted-3">{{ t('common.loading_data') }}</td></tr>
          <tr v-else-if="!rows.length"><td :colspan="showOutlet ? 8 : 7" class="px-3 py-6 text-center text-muted-3">{{ t('reports.mat_empty') }}</td></tr>
          <tr v-for="r in rows" :key="`${r.material_id}:${r.outlet_id}`" class="border-t border-line align-top" data-testid="mat-row">
            <td class="px-3 py-2.5">
              <span class="block font-semibold">{{ r.name }}</span>
              <span class="font-mono text-[11.5px] text-muted-3">{{ r.code }} · {{ t(`vendors_materials.kind_${r.kind}`) }}</span>
            </td>
            <td v-if="showOutlet" class="px-3 py-2.5 font-mono text-[12px]">{{ r.outlet_code }}</td>
            <td class="px-3 py-2.5 text-right font-mono tabular-nums" :class="Number(r.on_hand) < 0 ? 'text-danger-text' : ''" data-testid="mat-on-hand">{{ qty(r.on_hand) }} {{ r.unit }}</td>
            <td class="px-3 py-2.5 text-right font-mono tabular-nums text-muted-4">{{ r.daily_usage === null ? '—' : qty(r.daily_usage) }}</td>
            <td class="px-3 py-2.5">
              <StatusPill :variant="statusTone(r.status)">{{ t(`reports.mat_status_${r.status}`) }}</StatusPill>
              <span class="mt-1 block text-[11.5px] text-muted-4">{{ daysLeftText(r, t) }}</span>
            </td>
            <td class="px-3 py-2.5 text-[12px]">
              <span v-for="o in r.at_risk_options" :key="o.option_id" class="block">{{ o.group }} · <b>{{ o.option }}</b> ({{ t('reports.mat_portions', { n: o.portions }) }})</span>
              <span v-if="r.at_risk_products.length" class="block text-muted-4">{{ r.at_risk_products.map((p) => p.name).join(', ') }}</span>
              <span v-if="!r.at_risk_options.length && !r.at_risk_products.length" class="text-muted-3">—</span>
            </td>
            <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ money(r.stock_value) }}</td>
            <td class="px-3 py-2.5 text-[12px]">
              <div v-if="r.suggestion" data-testid="mat-suggestion">
                <div class="flex items-center gap-2">
                  <input v-if="pickable(r)" type="checkbox" class="h-4 w-4 accent-brand" :checked="picks[pickKey(r)] !== undefined" :aria-label="t('reports.mat_pick_aria', { name: r.name })" data-testid="mat-pick" @change="togglePick(r)" />
                  <span class="font-mono font-semibold">{{ qty(r.suggestion.qty) }} {{ r.unit }}</span>
                  <input v-if="picks[pickKey(r)] !== undefined" v-model="picks[pickKey(r)]" type="number" min="0.001" step="any" class="h-7 w-20 border border-line px-1.5 text-right font-mono" :aria-label="t('reports.mat_pick_qty_aria', { name: r.name })" data-testid="mat-pick-qty" />
                </div>
                <span v-if="r.suggestion.vendor" class="block text-muted-4">{{ r.suggestion.vendor }} · {{ money(r.suggestion.cost) }}</span>
                <span v-else class="block text-warn-text">{{ t('reports.mat_no_vendor') }}</span>
              </div>
              <span v-else class="text-muted-3">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    </template>
  </div>
</template>
