<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { currentSession, openSession, closeSession, sessionSummary } from '../api/sessions';
import { listEvents } from '../api/events';
import { useToastStore } from '../stores/toast';
import { formatIDR, parseMoney, toMoneyString } from '../utils/money';
import { formatDateTime } from '../utils/date';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import StatusPill from '../components/ui/StatusPill.vue';

const toast = useToastStore();

const loading = ref(true);
const session = ref(null);
const summary = ref(null);
const activeEvents = ref([]);

const openForm = reactive({ event_id: '', opening_cash: '0', notes: '' });
const openErrors = reactive({});
const opening = ref(false);

const closingCash = ref(0);
const closeNotes = ref('');
const closing = ref(false);
const closeErrors = reactive({});

const METHOD_LABELS = { cash: 'Tunai', bank_transfer: 'Transfer bank', qr_ewallet: 'QRIS / e-wallet' };
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
    });
    toast.success('Sesi kasir dibuka.');
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
    closeErrors.notes = 'Catatan wajib diisi karena ada selisih kas.';
    return;
  }
  delete closeErrors.notes;
  closing.value = true;
  try {
    await closeSession(session.value.id, {
      closing_cash: toMoneyString(closingCash.value),
      notes: closeNotes.value || null,
    });
    toast.success('Sesi kasir ditutup.');
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
    <div v-if="loading" class="py-14 text-center text-[13px] text-muted-3">Memuat…</div>

    <!-- No open session — designed fresh, no mockup reference for this state. -->
    <div v-else-if="!session" class="mx-auto flex max-w-[460px] flex-col gap-5 rounded-card border border-line-2 bg-white p-6">
      <div class="flex flex-col gap-1">
        <span class="text-[16px] font-bold tracking-tight">Buka sesi kasir</span>
        <span class="text-[12.5px] text-muted-2">Setiap transaksi harus terikat pada sesi kasir yang terbuka.</span>
      </div>

      <div v-if="activeEvents.length === 0" class="flex items-center gap-3 rounded-lg border border-warn-border bg-warn-bg px-4 py-3.5 text-[13px] text-warn-text">
        <i class="ph-duotone ph-info text-[18px]" aria-hidden="true"></i>
        Tidak ada event berstatus aktif. Aktifkan satu event dulu di layar Event.
      </div>
      <form v-else class="flex flex-col gap-3.5" @submit.prevent="handleOpen">
        <BaseSelect
          v-model="openForm.event_id"
          label="Event"
          required
          :options="activeEvents.map((e) => ({ value: e.id, label: e.name }))"
          :error="openErrors.event_id"
        />
        <BaseInput v-model="openForm.opening_cash" label="Kas awal (Rp)" type="number" min="0" required :error="openErrors.opening_cash" />
        <BaseTextarea v-model="openForm.notes" label="Catatan (opsional)" :rows="2" />
        <BaseButton type="submit" size="lg" class="w-full" :loading="opening">
          <i class="ph-duotone ph-lock-simple-open text-[17px]" aria-hidden="true"></i>
          Buka sesi
        </BaseButton>
      </form>
    </div>

    <!-- Open session -->
    <div v-else class="grid grid-cols-1 items-start gap-[18px] xl:grid-cols-[1.15fr_1fr]">
      <div class="flex flex-col gap-[18px] rounded-card border border-line-2 bg-white p-5">
        <div class="flex items-start justify-between gap-3">
          <div class="flex flex-col gap-1">
            <span class="text-[16px] font-bold tracking-tight">Sesi #{{ session.id }} · {{ session.user_name }}</span>
            <span class="text-[12.5px] text-muted-2">Dibuka {{ formatDateTime(session.opened_at) }} · {{ session.event_name }}</span>
          </div>
          <StatusPill variant="mint"><span class="h-1.5 w-1.5 rounded-full bg-brand"></span>Terbuka</StatusPill>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="flex flex-col gap-1 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
            <span class="text-[11.5px] font-semibold text-muted-2">Transaksi</span>
            <span class="text-[20px] font-extrabold tracking-tight">{{ summary?.order_count ?? 0 }}</span>
          </div>
          <div class="flex flex-col gap-1 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
            <span class="text-[11.5px] font-semibold text-muted-2">Total penjualan</span>
            <span class="text-[20px] font-extrabold tracking-tight">{{ formatIDR(summary?.total_sales ?? 0) }}</span>
          </div>
          <div class="flex flex-col gap-1 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
            <span class="text-[11.5px] font-semibold text-muted-2">Kas awal</span>
            <span class="text-[20px] font-extrabold tracking-tight">{{ formatIDR(session.opening_cash) }}</span>
          </div>
          <div class="flex flex-col gap-1 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
            <span class="text-[11.5px] font-semibold text-muted-2">Penjualan tunai</span>
            <span class="text-[20px] font-extrabold tracking-tight">{{ formatIDR(cashSalesAmount) }}</span>
          </div>
        </div>

        <div class="flex flex-col gap-2.5">
          <span class="text-[13px] font-bold">Rincian metode bayar</span>
          <div v-for="m in summary?.by_method ?? []" :key="m.method" class="flex items-center gap-3 border-b border-line-6 py-2.5 last:border-b-0">
            <i class="ph-duotone text-[19px] text-brand" :class="METHOD_ICONS[m.method]" aria-hidden="true"></i>
            <span class="flex-1 text-[13px] font-semibold">{{ METHOD_LABELS[m.method] ?? m.method }}</span>
            <span class="text-[12px] text-muted-3">{{ m.count }}×</span>
            <span class="min-w-[110px] text-right text-[13.5px] font-bold">{{ formatIDR(m.amount) }}</span>
          </div>
        </div>
      </div>

      <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
        <span class="text-[15px] font-bold tracking-tight">Tutup sesi</span>
        <BaseInput v-model="closingCash" label="Kas fisik dihitung (Rp)" type="number" min="0" />

        <div class="flex flex-col gap-2 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
          <div class="flex justify-between text-[12.5px]"><span class="text-muted">Kas awal</span><span class="font-semibold">{{ formatIDR(session.opening_cash) }}</span></div>
          <div class="flex justify-between text-[12.5px]"><span class="text-muted">Penjualan tunai</span><span class="font-semibold">{{ formatIDR(cashSalesAmount) }}</span></div>
          <div class="flex justify-between border-t border-dashed border-line-2 pt-2"><span class="font-bold text-[12.5px]">Kas seharusnya (pratinjau)</span><span class="text-[13.5px] font-extrabold">{{ formatIDR(previewExpected) }}</span></div>
        </div>

        <div
          class="flex items-center justify-between rounded-lg border px-3.5 py-3"
          :class="previewDifference === 0 ? 'border-mint-border bg-mint-50' : 'border-danger-border bg-danger-bg'"
        >
          <span class="text-[12.5px] font-bold" :class="previewDifference === 0 ? 'text-brand-active' : 'text-danger-text'">Selisih (pratinjau)</span>
          <span class="text-[18px] font-extrabold" :class="previewDifference === 0 ? 'text-brand-active' : 'text-danger-text'">{{ formatIDR(previewDifference) }}</span>
        </div>

        <BaseTextarea
          v-model="closeNotes"
          :label="`Catatan selisih${notesRequired ? ' (wajib)' : ' (opsional)'}`"
          placeholder="Contoh: kembalian kurang saat transaksi ke-4"
          :rows="3"
          :error="closeErrors.notes"
        />

        <BaseButton variant="dark" size="lg" class="w-full" :loading="closing" @click="handleClose">
          <i class="ph-duotone ph-lock-simple text-[17px]" aria-hidden="true"></i>
          Tutup sesi
        </BaseButton>
        <p class="text-[11.5px] leading-relaxed text-muted-3">
          Server menghitung <span class="font-mono text-[11px]">expected_cash</span> sendiri saat sesi ditutup; angka di atas hanya pratinjau.
        </p>
      </div>
    </div>
  </div>
</template>
