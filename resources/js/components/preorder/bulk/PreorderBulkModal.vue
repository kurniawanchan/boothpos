<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../../ui/BaseModal.vue';
import BaseButton from '../../ui/BaseButton.vue';
import BulkStatusForm from './BulkStatusForm.vue';
import BulkMarkerForm from './BulkMarkerForm.vue';
import BulkResultView from './BulkResultView.vue';
import { previewBulk, applyBulk } from '../../../api/preorders';
import { useSettingsStore } from '../../../stores/settings';

/**
 * 065 — dialog aksi massal pre-order (status / penanda progres). Satu cangkang, dua
 * formulir kecil. Langkah: configure → (preview) → review → processing → result.
 *
 * Server menentukan segalanya per pre-order (hak, kelayakan, jumlah, penerima). Di sini hanya kemudahan: angka
 * konfirmasi dari PRATINJAU, tabel yang bisa diedit untuk pembayaran/pengiriman, satu-penerbangan saat apply,
 * dan penanganan "hasil tak pasti" bila koneksi putus (mengulang aman: yang sudah diproses dilewati, tak digandakan).
 * Membatalkan/menutup di langkah mana pun sebelum konfirmasi tidak mengubah apa pun.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  action: { type: String, default: 'status' }, // status | dispatch
  /** Nilai awal penanda untuk aksi `dispatch` (tombol "invoice" → invoice_sent, tombol "pengiriman" → shipping). */
  dispatchTarget: { type: String, default: 'invoice_sent' },
  /** id pre-order terpilih; diambil saat dialog dibuka. */
  orderIds: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'applied', 'retry', 'open-preorder']);

const { t } = useI18n();
const settings = useSettingsStore();

const step = ref('configure'); // configure → checking → review → processing → result | unknown
const error = ref('');
const ids = ref([]);
const params = ref({});
const options = ref([]);
const preview = ref(null);
const result = ref(null);
let seq = 0;

function freshParams() {
  switch (props.action) {
    case 'status': return { status: '', cancel_reason: '' };
    default: return { dispatch_status: props.dispatchTarget };
  }
}

const overLimit = computed(() => ids.value.length > settings.preorderBulkMax);
const busy = computed(() => step.value === 'checking' || step.value === 'processing');

// Harus SESUDAH ref/computed yang disentuhnya (watch immediate berjalan saat setup; kalau di atas -> TDZ).
watch(
  () => props.open,
  async (open) => {
    seq++;
    if (!open) return;
    step.value = 'configure';
    error.value = '';
    ids.value = [...props.orderIds];
    params.value = freshParams();
    options.value = [];
    preview.value = null;
    result.value = null;
    if (props.action === 'status' && !overLimit.value) await loadStatusOptions();
  },
  { immediate: true },
);

async function loadStatusOptions() {
  const mine = ++seq;
  step.value = 'checking';
  try {
    const data = await previewBulk('status', ids.value, {});
    if (mine !== seq) return;
    options.value = data.status_options || [];
  } catch (err) {
    if (mine !== seq) return;
    error.value = err.errors?.order_ids?.[0] || t('preorder_bulk.failed_load');
  } finally {
    if (mine === seq) step.value = 'configure';
  }
}

const canContinue = computed(() => {
  if (overLimit.value) return false;
  const p = params.value;
  switch (props.action) {
    case 'status': return !!p.status && (p.status !== 'cancelled' || String(p.cancel_reason || '').trim() !== '');
    default: return !!p.dispatch_status;
  }
});

/** Pre-order yang benar-benar akan diproses menurut tabel/pratinjau saat ini. */
const eligibleCount = computed(() => preview.value?.eligible ?? 0);
const skippedCount = computed(() => ids.value.length - eligibleCount.value);
const nothing = computed(() => step.value === 'review' && eligibleCount.value === 0);

function cleanParams() {
  const p = { ...params.value };
  if (props.action === 'status' && p.status !== 'cancelled') delete p.cancel_reason;
  return p;
}

async function goReview() {
  error.value = '';
  if (!canContinue.value || busy.value) return;
  const mine = ++seq;
  step.value = 'checking';
  try {
    const data = await previewBulk(props.action, ids.value, cleanParams());
    if (mine !== seq) return;
    preview.value = data;
    step.value = 'review';
  } catch (err) {
    if (mine !== seq) return;
    step.value = 'configure';
    error.value = err.errors?.['params.cancel_reason']?.[0] || err.errors?.order_ids?.[0] || t('preorder_bulk.failed_load');
  }
}

async function confirm() {
  if (step.value !== 'review' || nothing.value) return; // satu penerbangan: klik ganda tak mengirim dua kali
  const mine = ++seq;
  step.value = 'processing';
  error.value = '';
  try {
    const data = await applyBulk(props.action, ids.value, cleanParams());
    result.value = data;
    step.value = 'result';
    emit('applied', data);
  } catch (err) {
    if (mine !== seq) return;
    if (err.status === 422 || err.status === 403) {
      // Ditolak SEBELUM ada yang diproses: tak ada yang berubah, boleh diperbaiki lalu dikirim lagi.
      step.value = 'review';
      error.value = err.errors?.order_ids?.[0] || err.message;
    } else {
      // Jaringan putus / galat server: hasilnya TIDAK diketahui. Jangan mengaku berhasil atau gagal.
      step.value = 'unknown';
    }
  }
}

function back() {
  error.value = '';
  step.value = 'configure';
}

function requestClose() {
  if (step.value === 'processing') return; // tak bisa dibatalkan di tengah penerapan
  emit('close');
}

function retry(retryIds) {
  emit('retry', { ids: retryIds });
}

const titleKey = computed(() => `preorder_bulk.title_${props.action}`);
</script>

<template>
  <BaseModal :open="open" :title="t(titleKey)" max-width-class="max-w-[680px]" @close="requestClose">
    <div class="flex flex-col gap-4 px-6 py-5" data-testid="preorder-bulk-modal">
      <p v-if="overLimit" role="alert" class="rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5 text-[12.5px] font-medium text-warn-text" data-testid="bulk-limit">
        {{ t('preorder_bulk.limit', { max: settings.preorderBulkMax, count: ids.length }) }}
      </p>

      <p v-if="step === 'checking'" class="text-[13px] font-medium text-muted-4" data-testid="bulk-checking">{{ t('preorder_bulk.checking') }}</p>

      <!-- Formulir pengaturan bersama (langkah configure; tetap tersedia saat checking agar tak berkedip). -->
      <div v-show="step === 'configure' || step === 'checking'">
        <BulkStatusForm v-if="action === 'status'" v-model="params" :options="options" />
        <BulkMarkerForm v-else v-model="params" />

      </div>

      <div v-if="step === 'review' && preview" class="flex flex-col gap-3" data-testid="bulk-review-step">
        <template v-if="nothing">
          <h3 class="text-[14px] font-bold" data-testid="bulk-nothing">{{ t('preorder_bulk.nothing') }}</h3>
          <p class="text-[13px] text-muted-4">{{ t('preorder_bulk.nothing_hint') }}</p>
        </template>
        <p v-else class="text-[13.5px] leading-relaxed text-muted-4" data-testid="bulk-confirm-body">
          {{ t('preorder_bulk.confirm_body', { eligible: eligibleCount, selected: ids.length, skipped: skippedCount }) }}
        </p>

        <div v-if="preview.skipped?.length" class="flex flex-col gap-1.5">
          <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">{{ t('preorder_bulk.skipped_list') }}</span>
          <ul class="max-h-48 overflow-auto rounded-lg border border-line-2 text-[12.5px]" data-testid="bulk-skipped-preview">
            <li v-for="s in preview.skipped" :key="s.preorder_id" class="flex justify-between gap-3 border-t border-dashed border-line-2 px-3 py-2 first:border-t-0">
              <span class="font-semibold">{{ s.preorder_number || `#${s.preorder_id}` }}</span>
              <span class="text-right text-muted-3">{{ s.message }}</span>
            </li>
          </ul>
        </div>
      </div>

      <p v-if="step === 'processing'" class="text-[13px] font-medium text-muted-4" role="status" data-testid="bulk-processing">
        {{ t('preorder_bulk.processing', { count: ids.length }) }}
      </p>

      <BulkResultView v-if="step === 'result' && result" :result="result" @retry="retry" @open="(id) => emit('open-preorder', id)" />

      <div v-if="step === 'unknown'" role="alert" class="flex flex-col gap-1.5" data-testid="bulk-unknown">
        <h3 class="text-[14px] font-bold">{{ t('preorder_bulk.unknown_title') }}</h3>
        <p class="text-[13px] text-muted-4">{{ t('preorder_bulk.unknown_body') }}</p>
      </div>

      <p v-if="error" role="alert" class="text-[12.5px] font-medium text-danger-text" data-testid="bulk-error">{{ error }}</p>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton v-if="step === 'result' || step === 'unknown'" data-testid="bulk-done" @click="emit('close')">{{ t('preorder_bulk.close') }}</BaseButton>
        <template v-else>
          <BaseButton variant="secondary" :disabled="step === 'processing'" @click="step === 'review' ? back() : requestClose()">
            {{ step === 'review' ? t('preorder_bulk.back') : t('common.cancel') }}
          </BaseButton>
          <BaseButton v-if="step === 'configure' || step === 'checking'" data-testid="bulk-continue" :loading="step === 'checking'" :disabled="!canContinue || busy" @click="goReview">
            {{ t('preorder_bulk.continue') }}
          </BaseButton>
          <BaseButton v-else-if="!nothing" data-testid="bulk-confirm" :loading="step === 'processing'" :disabled="busy" @click="confirm">
            {{ t('preorder_bulk.confirm') }}
          </BaseButton>
        </template>
      </div>
    </template>
  </BaseModal>
</template>
