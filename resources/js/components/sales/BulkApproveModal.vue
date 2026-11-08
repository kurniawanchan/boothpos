<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import ApprovalMethodPicker from './ApprovalMethodPicker.vue';
import { previewBulkApproval, bulkApprove } from '../../api/orderApprovals';
import { formatIDR } from '../../utils/money';
import { useSettingsStore } from '../../stores/settings';

/**
 * 064 — persetujuan MASSAL dari daftar Sales. Langkah: choose (metode + gambar SEKALI untuk seluruh batch) →
 * checking → confirm (angka dari PRATINJAU server: transaksi, persetujuan seller, seller, nilai, yang dilewati) →
 * processing → result. Membatalkan/menutup di langkah mana pun tidak mengirim persetujuan apa pun. Server menentukan
 * segalanya per penjualan (hak, seller, batal, siklus, kesegaran); di sini hanya kemudahan agar angka dan alasan terlihat.
 *
 * Pemilih metode tetap terpasang (`v-show`, bukan `v-if`) di langkah konfirmasi: melepas kanvas membuang goresannya.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  /** id penjualan terpilih (angka). Diambil saat dialog dibuka — tak ada sisa pilihan dari pembukaan sebelumnya. */
  orderIds: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'applied']);

const { t } = useI18n();
const settings = useSettingsStore();

const method = ref('draw');
const step = ref('choose'); // choose → checking → confirm → processing → result
const picker = ref(null);
const error = ref('');
const preview = ref(null);
const result = ref(null);
const ids = ref([]);
let seq = 0; // jawaban pratinjau yang basi (dialog ditutup/dibuka ulang) dibuang

watch(
  () => props.open,
  (open) => {
    seq++;
    if (!open) return;
    method.value = 'draw';
    step.value = 'choose';
    error.value = '';
    preview.value = null;
    result.value = null;
    ids.value = [...props.orderIds];
    picker.value?.reset();
  },
  { immediate: true },
);

const overLimit = computed(() => ids.value.length > settings.bulkApproveMax);
const busy = computed(() => step.value === 'checking' || step.value === 'processing');
const methodLabel = computed(() => t(`approvals.method_${method.value}`));
const nothing = computed(() => preview.value !== null && preview.value.approvals === 0);

async function goConfirm() {
  error.value = '';
  if (overLimit.value || !picker.value?.validate()) return;
  const mine = ++seq;
  step.value = 'checking';
  try {
    const data = await previewBulkApproval(ids.value);
    if (mine !== seq) return;
    preview.value = data;
    step.value = 'confirm';
  } catch (err) {
    if (mine !== seq) return;
    step.value = 'choose';
    error.value = err.errors?.order_ids?.[0] || t('approvals.bulk_preview_failed');
  }
}

async function confirm() {
  if (step.value !== 'confirm' || nothing.value) return; // satu penerbangan: klik ganda tak mengirim dua kali
  const image = await picker.value?.getImage();
  if (image === undefined) {
    step.value = 'choose';
    error.value = t('approvals.signature_required');
    return;
  }
  const mine = ++seq;
  step.value = 'processing';
  error.value = '';
  try {
    const data = await bulkApprove({ orderIds: ids.value, plan: preview.value.plan, method: method.value, image });
    result.value = data;
    step.value = 'result';
    emit('applied', data);
  } catch (err) {
    if (mine !== seq) return;
    if (err.errors?.image?.length || err.errors?.method?.length) {
      step.value = 'choose';
      error.value = err.errors.image?.[0] || err.errors.method?.[0];
    } else {
      // Jaringan putus/galat server: TIDAK mengaku berhasil. Mengulang aman (yang sudah disetujui dilewati).
      step.value = 'confirm';
      error.value = err.errors?.order_ids?.[0] || t('approvals.bulk_apply_failed');
    }
  }
}

function back() {
  error.value = '';
  step.value = 'choose';
}

function requestClose() {
  if (step.value === 'processing') return; // tak bisa dibatalkan di tengah penerapan
  emit('close');
}

function reasonText(row) {
  return t(`approvals.bulk_reason_${row.reason}`);
}
</script>

<template>
  <BaseModal :open="open" :title="t('approvals.bulk_title')" max-width-class="max-w-[600px]" @close="requestClose">
    <div class="flex flex-col gap-4 px-6 py-5" data-testid="bulk-approve-modal">
      <!-- v-show (bukan v-if): kanvas harus tetap terpasang di langkah berikutnya supaya goresannya masih bisa dibaca. -->
      <div v-show="step === 'choose'" class="flex flex-col gap-4">
        <p class="text-[13px] text-muted-4">{{ t('approvals.bulk_choose_hint') }}</p>
        <p v-if="overLimit" role="alert" class="rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5 text-[12.5px] font-medium text-warn-text" data-testid="bulk-limit">
          {{ t('approvals.bulk_limit', { max: settings.bulkApproveMax, count: ids.length }) }}
        </p>
        <ApprovalMethodPicker
          ref="picker"
          v-model:method="method"
          :seller-label="t('approvals.bulk_stamp_seller')"
          @error="(m) => (error = m)"
        />
      </div>

      <p v-if="step === 'checking'" class="text-[13px] font-medium text-muted-4" data-testid="bulk-checking">{{ t('approvals.bulk_checking') }}</p>

      <div v-if="step === 'confirm' && preview" class="flex flex-col gap-3" data-testid="bulk-confirm-step">
        <template v-if="nothing">
          <h3 class="text-[14px] font-bold" data-testid="bulk-nothing">{{ t('approvals.bulk_nothing') }}</h3>
          <p class="text-[13px] text-muted-4">{{ t('approvals.bulk_nothing_hint') }}</p>
        </template>
        <template v-else>
          <h3 class="text-[14px] font-bold">{{ t('approvals.bulk_confirm_title') }}</h3>
          <p class="text-[13.5px] leading-relaxed text-muted-4">
            {{ t('approvals.bulk_confirm_body', { approvals: preview.approvals, orders: preview.eligible_orders, amount: formatIDR(preview.amount) }) }}
          </p>
          <p class="text-[12.5px] font-semibold text-muted-3">{{ methodLabel }}</p>
          <ul class="flex flex-col gap-1 text-[12.5px] text-muted-4" data-testid="bulk-sellers">
            <li v-for="s in preview.sellers" :key="s.artist_id">{{ t('approvals.bulk_sellers_line', { name: s.artist_name, approvals: s.approvals, amount: formatIDR(s.amount) }) }}</li>
          </ul>
        </template>
        <p v-if="preview.skipped.length" class="text-[12.5px] font-medium text-muted-3" data-testid="bulk-skipped-note">
          {{ t('approvals.bulk_skipped_note', { count: preview.skipped.length }) }}
        </p>
      </div>

      <p v-if="step === 'processing'" class="text-[13px] font-medium text-muted-4" data-testid="bulk-processing">{{ t('approvals.bulk_processing') }}</p>

      <div v-if="step === 'result' && result" class="flex flex-col gap-3" data-testid="bulk-result">
        <h3 class="text-[14px] font-bold">{{ t('approvals.bulk_result_title') }}</h3>
        <p class="text-[13.5px] text-muted-4" data-testid="bulk-result-head">
          {{ result.approved_orders > 0 ? t('approvals.bulk_result_head', { orders: result.approved_orders, approvals: result.approved_sellers }) : t('approvals.bulk_result_none') }}
          <span v-if="result.skipped_total > 0"> {{ t('approvals.bulk_result_skipped', { count: result.skipped_total }) }}</span>
        </p>
        <div v-if="result.skipped_total > 0" class="flex flex-col gap-1.5">
          <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">{{ t('approvals.bulk_skipped_list') }}</span>
          <ul class="max-h-56 overflow-auto rounded-lg border border-line-2 text-[12.5px]" data-testid="bulk-skipped-list">
            <template v-for="row in result.results" :key="row.order_id">
              <li v-if="row.status === 'skipped'" class="flex items-center justify-between gap-3 border-t border-dashed border-line-2 px-3 py-2 first:border-t-0">
                <span class="truncate font-semibold">{{ row.order_number || `#${row.order_id}` }}</span>
                <span class="shrink-0 text-muted-3">{{ reasonText(row) }}</span>
              </li>
            </template>
          </ul>
        </div>
      </div>

      <p v-if="error" role="alert" class="text-[12.5px] font-medium text-danger-text" data-testid="bulk-error">{{ error }}</p>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton v-if="step === 'result'" data-testid="bulk-done" @click="emit('close')">{{ t('approvals.bulk_done') }}</BaseButton>
        <template v-else>
          <BaseButton
            variant="secondary"
            :disabled="step === 'processing'"
            @click="step === 'confirm' ? back() : requestClose()"
          >
            {{ step === 'confirm' ? t('approvals.back') : t('common.cancel') }}
          </BaseButton>
          <BaseButton v-if="step === 'choose' || step === 'checking'" data-testid="bulk-continue" :loading="step === 'checking'" :disabled="overLimit || busy" @click="goConfirm">
            {{ t('approvals.continue') }}
          </BaseButton>
          <BaseButton v-else-if="!nothing" data-testid="bulk-confirm" :loading="step === 'processing'" :disabled="busy" @click="confirm">
            {{ t('approvals.confirm_action') }}
          </BaseButton>
        </template>
      </div>
    </template>
  </BaseModal>
</template>
