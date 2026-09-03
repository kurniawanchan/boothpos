<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { currentSession, openSession, closeSession, sessionSummary } from '../api/sessions';
import { listEvents } from '../api/events';
import { listArtists } from '../api/artists';
import { useToastStore } from '../stores/toast';
import { formatIDR, parseMoney, toMoneyString } from '../utils/money';
import { formatDateTime } from '../utils/date';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import StatusPill from '../components/ui/StatusPill.vue';

const toast = useToastStore();
const { t } = useI18n();

const loading = ref(true);
const session = ref(null);
const summary = ref(null);
const activeEvents = ref([]);

const openForm = reactive({ event_id: '', opening_cash: '0', notes: '' });
const openErrors = reactive({});
const opening = ref(false);

// 006-purchase-order-and-ops (US5) — rincian kas awal per artist, aditif
// terhadap opening_cash di atas (lihat komentar
// CashierSessionController::store()). Baris kosong = fitur tidak dipakai
// sama sekali, opening_cash tetap dikirim sendirian seperti sebelumnya.
const artists = ref([]);
const openingCashEntries = ref([]);
const useOpeningCashPerArtist = ref(false);

function addOpeningCashEntryRow() {
  openingCashEntries.value.push({ artist_id: '', amount: '0' });
}
function removeOpeningCashEntryRow(idx) {
  openingCashEntries.value.splice(idx, 1);
}
const openingCashEntriesTotal = computed(() =>
  openingCashEntries.value.reduce((sum, e) => sum + (parseMoney(e.amount) || 0), 0)
);
// The server rejects a mismatch (Constitution IV — never trust a client
// total independent of its parts), so opening_cash is kept in lockstep
// with the entries total while per-artist mode is on, rather than
// letting the two drift and surfacing a 422 only after submit.
watch(openingCashEntriesTotal, (total) => {
  if (useOpeningCashPerArtist.value) openForm.opening_cash = toMoneyString(total);
});

const closingCash = ref(0);
const closeNotes = ref('');
const closing = ref(false);
const closeErrors = reactive({});

const METHOD_LABELS = computed(() => ({
  cash: t('events_sessions.method_full_cash'),
  bank_transfer: t('events_sessions.method_full_bank_transfer'),
  qr_ewallet: t('events_sessions.method_full_qris'),
}));
const METHOD_ICONS = { cash: 'ph-money', bank_transfer: 'ph-bank', qr_ewallet: 'ph-qr-code' };

onMounted(load);

async function load() {
  loading.value = true;
  try {
    session.value = await currentSession();
    if (session.value) {
      summary.value = await sessionSummary(session.value.id);
      closingCash.value = 0;
    } else {
      const res = await listEvents({ status: 'active', per_page: 50 });
      activeEvents.value = res.data;
      if (activeEvents.value[0]) openForm.event_id = activeEvents.value[0].id;
      artists.value = (await listArtists({ per_page: 100, is_active: true })).data;
    }
  } catch {
    // Object-level 403 (not this cashier's session) is already toasted by
    // the shared axios interceptor — leave the screen in a safe empty state.
  } finally {
    loading.value = false;
  }
}

async function handleOpen() {
  Object.keys(openErrors).forEach((k) => delete openErrors[k]);
  opening.value = true;
  try {
    await openSession({
      event_id: Number(openForm.event_id),
      opening_cash: toMoneyString(openForm.opening_cash),
      notes: openForm.notes || null,
      ...(useOpeningCashPerArtist.value && openingCashEntries.value.length
        ? { opening_cash_entries: openingCashEntries.value.map((e) => ({ artist_id: e.artist_id ? Number(e.artist_id) : null, amount: toMoneyString(e.amount) })) }
        : {}),
    });
    toast.success(t('events_sessions.session_opened'));
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(openErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
    // 409 (masih ada sesi lain terbuka) sudah ditoast oleh interceptor.
  } finally {
    opening.value = false;
  }
}

const cashSalesAmount = computed(() => {
  const row = summary.value?.by_method?.find((m) => m.method === 'cash');
  return row ? parseMoney(row.amount) : 0;
});
const previewExpected = computed(() => parseMoney(session.value?.opening_cash ?? 0) + cashSalesAmount.value);
const previewDifference = computed(() => (Number(closingCash.value) || 0) - previewExpected.value);
const notesRequired = computed(() => previewDifference.value !== 0);

async function handleClose() {
  if (notesRequired.value && !closeNotes.value.trim()) {
    closeErrors.notes = t('events_sessions.notes_required_diff');
    return;
  }
  delete closeErrors.notes;
  closing.value = true;
  try {
    await closeSession(session.value.id, {
      closing_cash: toMoneyString(closingCash.value),
      notes: closeNotes.value || null,
    });
    toast.success(t('events_sessions.session_closed'));
    session.value = null;
    summary.value = null;
    closingCash.value = 0;
    closeNotes.value = '';
    await load();
  } catch {
    // 409/403 already toasted globally.
  } finally {
    closing.value = false;
  }
}
</script>

<template>
  <div class="px-[26px] pb-10 pt-[22px]">
    <div v-if="loading" class="py-14 text-center text-[13px] text-muted-3">{{ t('events_sessions.loading') }}</div>

    <!-- No open session — designed fresh, no mockup reference for this state. -->
    <div v-else-if="!session" class="mx-auto flex max-w-[460px] flex-col gap-5 rounded-card border border-line-2 bg-white p-6">
      <div class="flex flex-col gap-1">
        <span class="text-[16px] font-bold tracking-tight">{{ t('events_sessions.open_session') }}</span>
        <span class="text-[12.5px] text-muted-2">{{ t('events_sessions.open_session_desc') }}</span>
      </div>

      <div v-if="activeEvents.length === 0" class="flex items-center gap-3 rounded-lg border border-warn-border bg-warn-bg px-4 py-3.5 text-[13px] text-warn-text">
        <i class="ph-duotone ph-info text-[18px]" aria-hidden="true"></i>
        {{ t('events_sessions.no_active_event_open_one') }}
      </div>
      <form v-else class="flex flex-col gap-3.5" @submit.prevent="handleOpen">
        <BaseSelect
          v-model="openForm.event_id"
          :label="t('events_sessions.event')"
          required
          :options="activeEvents.map((e) => ({ value: e.id, label: e.name }))"
          :error="openErrors.event_id"
        />
        <BaseInput v-model="openForm.opening_cash" :label="t('events_sessions.opening_cash_rp')" type="number" min="0" required :disabled="useOpeningCashPerArtist" :error="openErrors.opening_cash" />

        <label class="flex items-center gap-2.5 text-[12.5px] font-semibold text-muted-4">
          <input v-model="useOpeningCashPerArtist" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" @change="openingCashEntries.length === 0 && addOpeningCashEntryRow()" />
          {{ t('events_sessions.opening_cash_per_artist_toggle') }}
        </label>

        <div v-if="useOpeningCashPerArtist" class="flex flex-col gap-2.5 rounded-lg border border-line-3 bg-surface-subtle p-3">
          <div v-for="(entry, idx) in openingCashEntries" :key="idx" class="flex items-end gap-2">
            <BaseSelect v-model="entry.artist_id" class="flex-1" :label="idx === 0 ? t('events_sessions.artist') : ''" :options="artists.map((a) => ({ value: a.id, label: a.name }))" />
            <BaseInput v-model="entry.amount" class="w-32" :label="idx === 0 ? t('events_sessions.amount') : ''" type="number" min="0" />
            <button type="button" class="mb-[11px] flex h-[46px] w-[38px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('common.delete')" @click="removeOpeningCashEntryRow(idx)">
              <i class="ph-duotone ph-trash text-[15px]" aria-hidden="true"></i>
            </button>
          </div>
          <BaseButton type="button" variant="secondary" size="sm" @click="addOpeningCashEntryRow">
            <i class="ph-duotone ph-plus text-[14px]" aria-hidden="true"></i>
            {{ t('events_sessions.add_artist_row') }}
          </BaseButton>
          <div class="flex justify-between border-t border-dashed border-line-2 pt-2 text-[12.5px]">
            <span class="font-bold">{{ t('events_sessions.total') }}</span>
            <span class="font-extrabold">{{ formatIDR(openingCashEntriesTotal) }}</span>
          </div>
        </div>
        <BaseTextarea v-model="openForm.notes" :label="t('events_sessions.notes_optional')" :rows="2" />
        <BaseButton type="submit" size="lg" class="w-full" :loading="opening">
          <i class="ph-duotone ph-lock-simple-open text-[17px]" aria-hidden="true"></i>
          {{ t('events_sessions.open_session_btn') }}
        </BaseButton>
      </form>
    </div>

    <!-- Open session -->
    <div v-else class="grid grid-cols-1 items-start gap-[18px] xl:grid-cols-[1.15fr_1fr]">
      <div class="flex flex-col gap-[18px] rounded-card border border-line-2 bg-white p-5">
        <div class="flex items-start justify-between gap-3">
          <div class="flex flex-col gap-1">
            <span class="text-[16px] font-bold tracking-tight">{{ t('events_sessions.session_number', { id: session.id, user: session.user_name }) }}</span>
            <span class="text-[12.5px] text-muted-2">{{ t('events_sessions.opened_at', { time: formatDateTime(session.opened_at), event: session.event_name }) }}</span>
          </div>
          <StatusPill variant="mint"><span class="h-1.5 w-1.5 rounded-full bg-brand"></span>{{ t('events_sessions.open_status') }}</StatusPill>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="flex flex-col gap-1 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
            <span class="text-[11.5px] font-semibold text-muted-2">{{ t('events_sessions.transactions') }}</span>
            <span class="text-[20px] font-extrabold tracking-tight">{{ summary?.order_count ?? 0 }}</span>
          </div>
          <div class="flex flex-col gap-1 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
            <span class="text-[11.5px] font-semibold text-muted-2">{{ t('events_sessions.total_sales') }}</span>
            <span class="text-[20px] font-extrabold tracking-tight">{{ formatIDR(summary?.total_sales ?? 0) }}</span>
          </div>
          <div class="flex flex-col gap-1 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
            <span class="text-[11.5px] font-semibold text-muted-2">{{ t('events_sessions.opening_cash') }}</span>
            <span class="text-[20px] font-extrabold tracking-tight">{{ formatIDR(session.opening_cash) }}</span>
          </div>
          <div class="flex flex-col gap-1 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
            <span class="text-[11.5px] font-semibold text-muted-2">{{ t('events_sessions.cash_sales') }}</span>
            <span class="text-[20px] font-extrabold tracking-tight">{{ formatIDR(cashSalesAmount) }}</span>
          </div>
        </div>

        <div v-if="summary?.opening_cash_entries?.length" class="flex flex-col gap-2">
          <span class="text-[13px] font-bold">{{ t('events_sessions.opening_cash_per_artist_toggle') }}</span>
          <div v-for="(e, idx) in summary.opening_cash_entries" :key="idx" class="flex items-center justify-between border-b border-line-6 py-2 last:border-b-0 text-[12.5px]">
            <span>{{ e.artist_name || t('events_sessions.unattributed') }}</span>
            <span class="font-bold">{{ formatIDR(e.amount) }}</span>
          </div>
        </div>

        <div class="flex flex-col gap-2.5">
          <span class="text-[13px] font-bold">{{ t('events_sessions.payment_method_breakdown') }}</span>
          <div v-for="m in summary?.by_method ?? []" :key="m.method" class="flex items-center gap-3 border-b border-line-6 py-2.5 last:border-b-0">
            <i class="ph-duotone text-[19px] text-brand" :class="METHOD_ICONS[m.method]" aria-hidden="true"></i>
            <span class="flex-1 text-[13px] font-semibold">{{ METHOD_LABELS[m.method] ?? m.method }}</span>
            <span class="text-[12px] text-muted-3">{{ m.count }}×</span>
            <span class="min-w-[110px] text-right text-[13.5px] font-bold">{{ formatIDR(m.amount) }}</span>
          </div>
        </div>
      </div>

      <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[15px] font-bold tracking-tight">{{ t('events_sessions.close_session') }}</span>
        <BaseInput v-model="closingCash" :label="t('events_sessions.physical_cash_counted_rp')" type="number" min="0" />

        <div class="flex flex-col gap-2 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
          <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('events_sessions.opening_cash') }}</span><span class="font-semibold">{{ formatIDR(session.opening_cash) }}</span></div>
          <div class="flex justify-between text-[12.5px]"><span class="text-muted">{{ t('events_sessions.cash_sales') }}</span><span class="font-semibold">{{ formatIDR(cashSalesAmount) }}</span></div>
          <div class="flex justify-between border-t border-dashed border-line-2 pt-2"><span class="font-bold text-[12.5px]">{{ t('events_sessions.expected_cash_preview') }}</span><span class="text-[13.5px] font-extrabold">{{ formatIDR(previewExpected) }}</span></div>
        </div>

        <div
          class="flex items-center justify-between rounded-lg border px-3.5 py-3"
          :class="previewDifference === 0 ? 'border-mint-border bg-mint-50' : 'border-danger-border bg-danger-bg'"
        >
          <span class="text-[12.5px] font-bold" :class="previewDifference === 0 ? 'text-brand-active' : 'text-danger-text'">{{ t('events_sessions.difference_preview') }}</span>
          <span class="text-[18px] font-extrabold" :class="previewDifference === 0 ? 'text-brand-active' : 'text-danger-text'">{{ formatIDR(previewDifference) }}</span>
        </div>

        <BaseTextarea
          v-model="closeNotes"
          :label="t('events_sessions.diff_notes_label', { suffix: notesRequired ? t('events_sessions.diff_notes_required_suffix') : t('events_sessions.diff_notes_optional_suffix') })"
          :placeholder="t('events_sessions.diff_notes_placeholder')"
          :rows="3"
          :error="closeErrors.notes"
        />

        <BaseButton variant="dark" size="lg" class="w-full" :loading="closing" @click="handleClose">
          <i class="ph-duotone ph-lock-simple text-[17px]" aria-hidden="true"></i>
          {{ t('events_sessions.close_session_btn') }}
        </BaseButton>
        <p class="text-[11.5px] leading-relaxed text-muted-3">
          {{ t('events_sessions.server_computes_expected_cash') }}
        </p>
      </div>
    </div>
  </div>
</template>
