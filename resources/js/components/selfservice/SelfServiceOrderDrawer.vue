<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  dispatchSelfServiceOrder, fetchProofBlob, getSelfServiceOrder, rejectSelfServiceOrder, verifySelfServiceOrder,
} from '../../api/selfServiceOrders';
import { useAuthStore } from '../../stores/auth';
import { useToastStore } from '../../stores/toast';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { statusOf } from '../../utils/selfServiceStatus';
import BaseButton from '../ui/BaseButton.vue';
import BaseDrawer from '../ui/BaseDrawer.vue';
import BaseInput from '../ui/BaseInput.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import StatusPill from '../ui/StatusPill.vue';
import ReasonDialog from '../restaurant/ReasonDialog.vue';

// 090 / US6 — detail satu pesanan mandiri: item, pembayaran QRIS, foto bukti, dan tiga aksi staf: Verifikasi → Kirim ke
// dapur, atau Tolak. Tombol yang tak boleh dipakai pengguna ini DISEMBUNYIKAN (Konstitusi III), tetapi server tetap
// penjaga sebenarnya: izin QRIS dan status dicek ulang di sana, dan galat 403/409 ditoast oleh penangan pusat.
const props = defineProps({ orderId: { type: Number, default: null } });
const emit = defineEmits(['close', 'changed']);
const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const order = ref(null);
const loading = ref(false);
const loadFailed = ref(false);

const proofUrl = ref(null);
const proofState = ref('idle'); // idle | loading | ready | error
const zoom = ref(false);

const reference = ref('');
const referenceError = ref('');
const showReject = ref(false);
const busy = ref('');
// Masalah dispatch yang butuh keputusan staf (item habis / total berubah): ditampilkan di badan laci, bukan hanya toast.
const problem = ref(null);

const canJudge = computed(() => auth.hasPermission('payment.qris.verify'));
const status = computed(() => order.value?.status ?? null);
const referenceOk = computed(() => reference.value.trim().length >= 4);

function revokeProof() {
  if (proofUrl.value) URL.revokeObjectURL(proofUrl.value);
  proofUrl.value = null;
}

async function loadProof(proofId) {
  revokeProof();
  if (!proofId) return;
  proofState.value = 'loading';
  try {
    proofUrl.value = URL.createObjectURL(await fetchProofBlob(proofId));
    proofState.value = 'ready';
  } catch {
    proofState.value = 'error';
  }
}

async function load() {
  problem.value = null;
  reference.value = '';
  referenceError.value = '';
  order.value = null;
  loadFailed.value = false;
  revokeProof();
  proofState.value = 'idle';
  if (props.orderId === null) return;

  loading.value = true;
  try {
    order.value = await getSelfServiceOrder(props.orderId);
    loading.value = false;
    // Foto bukti dimuat TERPISAH (tidak ditunggu): isi pesanan dan tombol aksi langsung tampil bersama, foto menyusul.
    loadProof(order.value.payment.proof_id);
  } catch {
    loadFailed.value = true;
  } finally {
    loading.value = false;
  }
}

watch(() => props.orderId, load, { immediate: true });
onBeforeUnmount(revokeProof);

/** Setelah aksi berhasil: ganti isi laci dengan keadaan baru dan beri tahu daftar. */
function applyResult(next) {
  order.value = next;
  problem.value = null;
  emit('changed');
}

async function verify() {
  referenceError.value = '';
  busy.value = 'verify';
  try {
    applyResult(await verifySelfServiceOrder(order.value.id, reference.value.trim()));
    toast.success(t('self_service_staff.verified_toast'));
  } catch (err) {
    if (err.isValidation) referenceError.value = err.errors?.reference?.[0] ?? err.message;
    // 403/409 sudah ditoast oleh penangan galat pusat.
  } finally {
    busy.value = '';
  }
}

async function reject(reason) {
  busy.value = 'reject';
  try {
    applyResult(await rejectSelfServiceOrder(order.value.id, reason));
    showReject.value = false;
    toast.success(t('self_service_staff.rejected_toast'));
  } catch {
    showReject.value = false;
  } finally {
    busy.value = '';
  }
}

async function dispatchToKitchen() {
  busy.value = 'dispatch';
  problem.value = null;
  try {
    const res = await dispatchSelfServiceOrder(order.value.id);
    applyResult(res.data);
    toast.success(t('self_service_staff.dispatch_done', { number: res.order.order_number }));
  } catch (err) {
    if (['item_unavailable', 'total_changed', 'no_open_shift'].includes(err.code)) {
      problem.value = { code: err.code, message: err.message, items: err.data?.items ?? [] };
    }
  } finally {
    busy.value = '';
  }
}

const typeLabel = computed(() => (order.value?.service_type === 'takeaway' ? t('self_service_staff.type_takeaway') : t('self_service_staff.type_dine_in')));
const modifierText = (l) => l.modifiers.map((m) => (m.qty > 1 ? `${m.option} ×${m.qty}` : m.option)).join(', ');
</script>

<template>
  <BaseDrawer
    :open="orderId !== null"
    :title="order ? t('self_service_staff.detail_title', { ref: order.reference_no }) : t('self_service_staff.title')"
    :subtitle="order ? `${t('self_service_staff.table_label', { name: order.table.name })} · ${typeLabel}` : ''"
    max-width-class="max-w-[640px]"
    @close="emit('close')"
  >
    <p v-if="loading" class="text-[13px] text-muted" role="status">{{ t('self_service_staff.loading') }}</p>
    <div v-else-if="loadFailed" class="flex flex-col items-start gap-3" role="alert">
      <p class="text-[13px] font-semibold text-danger-text">{{ t('self_service_staff.load_failed') }}</p>
      <BaseButton variant="secondary" size="sm" @click="load">{{ t('self_service_staff.retry') }}</BaseButton>
    </div>

    <div v-else-if="order" class="flex flex-col gap-5" data-testid="self-service-drawer">
      <div class="flex flex-wrap items-center gap-3">
        <StatusPill :variant="statusOf(order.status).variant">{{ t(statusOf(order.status).label) }}</StatusPill>
        <span class="text-[12.5px] text-muted">{{ formatDateTime(order.created_at) }}</span>
        <span v-if="order.order" class="text-[12.5px] font-bold text-ink" data-testid="drawer-order-number">{{ t('self_service_staff.order_number', { number: order.order.order_number }) }}</span>
      </div>

      <section class="flex flex-col gap-1" data-testid="drawer-customer">
        <h3 class="text-[13.5px] font-extrabold text-ink">{{ t('self_service_staff.customer') }}</h3>
        <dl v-if="order.customer" class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[13px]">
          <dt class="text-muted">{{ t('self_service_staff.customer_name') }}</dt><dd class="font-bold">{{ order.customer.name }}</dd>
          <dt class="text-muted">{{ t('self_service_staff.customer_phone') }}</dt><dd class="font-mono">{{ order.customer.phone }}</dd>
          <template v-if="order.customer.email">
            <dt class="text-muted">{{ t('self_service_staff.customer_email') }}</dt><dd>{{ order.customer.email }}</dd>
          </template>
        </dl>
        <p v-else class="text-[13px] text-muted">{{ t('self_service_staff.guest') }}</p>
      </section>

      <p v-if="order.rejection" class="bg-danger-bg px-3.5 py-2.5 text-[13px] text-danger-text" data-testid="drawer-rejection">
        <span class="font-bold">{{ t('self_service_staff.rejected_reason') }}</span> {{ order.rejection.reason }}
        <span v-if="order.rejection.by" class="block text-[11.5px]">{{ order.rejection.by }} · {{ formatDateTime(order.rejection.at) }}</span>
      </p>

      <section class="flex flex-col gap-2">
        <h3 class="text-[13.5px] font-extrabold text-ink">{{ t('self_service_staff.items') }}</h3>
        <ul class="flex flex-col divide-y divide-line border border-line bg-white">
          <li v-for="(l, i) in order.lines" :key="i" class="flex items-start justify-between gap-3 px-3.5 py-2.5">
            <div class="min-w-0">
              <p class="text-[13.5px] font-bold text-ink">{{ l.qty }}× {{ l.name }}</p>
              <p v-if="l.modifiers.length" class="text-[12px] text-muted">{{ modifierText(l) }}</p>
              <p v-if="l.note" class="text-[12px] italic text-muted">“{{ l.note }}”</p>
            </div>
            <p class="shrink-0 text-[13.5px] font-bold">{{ formatIDR(l.line_total) }}</p>
          </li>
        </ul>
        <dl class="flex flex-col gap-1 pt-1 text-[13px]">
          <div class="flex justify-between"><dt class="text-muted">{{ t('self_service_staff.subtotal') }}</dt><dd class="font-bold">{{ formatIDR(order.subtotal) }}</dd></div>
          <div v-if="Number(order.tax_amount) > 0" class="flex justify-between"><dt class="text-muted">{{ t('self_service_staff.tax') }}</dt><dd class="font-bold">{{ formatIDR(order.tax_amount) }}</dd></div>
          <div class="flex justify-between text-[15px]"><dt class="font-extrabold">{{ t('self_service_staff.total') }}</dt><dd class="font-extrabold" data-testid="drawer-total">{{ formatIDR(order.total_amount) }}</dd></div>
        </dl>
      </section>

      <section class="flex flex-col gap-2">
        <h3 class="text-[13.5px] font-extrabold text-ink">{{ t('self_service_staff.payment_title') }}</h3>
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[13px]">
          <dt class="text-muted">{{ t('self_service_staff.payment_amount') }}</dt><dd class="font-bold">{{ formatIDR(order.payment.amount) }}</dd>
          <dt class="text-muted">{{ t('self_service_staff.payment_status') }}</dt>
          <dd class="font-bold" data-testid="drawer-payment-status">{{ t(`self_service_staff.pay_${order.payment.status}`) }}</dd>
          <template v-if="order.payment.reference">
            <dt class="text-muted">{{ t('self_service_staff.payment_reference') }}</dt><dd class="font-mono font-bold">{{ order.payment.reference }}</dd>
          </template>
          <template v-if="order.payment.verified_by">
            <dt class="text-muted">{{ t('self_service_staff.verified_by') }}</dt><dd>{{ order.payment.verified_by }}</dd>
          </template>
        </dl>

        <div class="border border-line bg-white p-3">
          <p class="mb-2 text-[12.5px] font-semibold text-muted-4">{{ t('self_service_staff.proof_title') }}</p>
          <p v-if="proofState === 'loading'" class="text-[12.5px] text-muted" role="status">{{ t('self_service_staff.proof_loading') }}</p>
          <p v-else-if="proofState === 'error'" class="text-[12.5px] font-semibold text-danger-text" role="alert">{{ t('self_service_staff.proof_failed') }}</p>
          <button v-else-if="proofUrl" type="button" class="block" :aria-label="t('self_service_staff.proof_zoom')" @click="zoom = true">
            <img :src="proofUrl" :alt="t('self_service_staff.proof_alt')" class="max-h-72 w-auto object-contain" data-testid="drawer-proof" />
          </button>
        </div>
      </section>

      <section v-if="status === 'awaiting_verification' && canJudge" class="flex flex-col gap-2">
        <BaseInput
          v-model="reference"
          :label="t('self_service_staff.verify_reference_label')"
          :hint="t('self_service_staff.verify_reference_hint')"
          :error="referenceError"
          maxlength="100"
          autocomplete="off"
        />
      </section>
      <p v-else-if="status === 'awaiting_verification'" class="bg-warn-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-warn-text" data-testid="no-permission">
        {{ t('self_service_staff.no_permission') }}
      </p>

      <p v-if="status === 'verified'" class="text-[12.5px] text-muted">{{ t('self_service_staff.verified_next') }}</p>

      <div v-if="problem" class="flex flex-col gap-1.5 bg-danger-bg px-3.5 py-2.5 text-[13px] text-danger-text" role="alert" data-testid="drawer-problem">
        <p class="font-bold">{{ problem.message }}</p>
        <ul v-if="problem.items.length" class="list-disc pl-5">
          <li v-for="it in problem.items" :key="it.variant_id">{{ it.name ?? `#${it.variant_id}` }}</li>
        </ul>
        <p v-if="problem.code === 'total_changed' || problem.code === 'item_unavailable'" class="text-[12px]">{{ t('self_service_staff.problem_reject_hint') }}</p>
      </div>
    </div>

    <template #footer>
      <template v-if="order && status === 'awaiting_verification' && canJudge">
        <BaseButton variant="danger" :loading="busy === 'reject'" @click="showReject = true">{{ t('self_service_staff.reject') }}</BaseButton>
        <BaseButton :disabled="!referenceOk" :loading="busy === 'verify'" data-testid="btn-verify" @click="verify">{{ t('self_service_staff.verify') }}</BaseButton>
      </template>
      <template v-else-if="order && status === 'verified'">
        <BaseButton v-if="canJudge" variant="danger" :loading="busy === 'reject'" @click="showReject = true">{{ t('self_service_staff.reject') }}</BaseButton>
        <BaseButton :loading="busy === 'dispatch'" data-testid="btn-dispatch" @click="dispatchToKitchen">{{ t('self_service_staff.dispatch') }}</BaseButton>
      </template>
      <BaseButton v-else variant="secondary" @click="emit('close')">{{ t('common.close') }}</BaseButton>
    </template>
  </BaseDrawer>

  <ReasonDialog
    :open="showReject"
    :title="t('self_service_staff.reject_title')"
    :message="t('self_service_staff.reject_message')"
    :confirm-label="t('self_service_staff.reject')"
    :submitting="busy === 'reject'"
    @close="showReject = false"
    @confirm="reject"
  />
  <ImageLightbox :open="zoom" :src="proofUrl" :alt="t('self_service_staff.proof_alt')" @close="zoom = false" />
</template>
