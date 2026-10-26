<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import {
  getSettlement, refreshSettlement, deleteSettlement, finalizeSettlement, reopenSettlement,
  addSettlementAdjustment, updateSettlementAdjustment, deleteSettlementAdjustment,
  acknowledgeSettlement, getAcknowledgementSignature, addSettlementPayment, deleteSettlementPayment,
  getSettlementPaymentProof,
} from '../api/settlements';
import { useToastStore } from '../stores/toast';
import { formatIDRExact } from '../utils/money';
import { formatDateTime } from '../utils/date';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseTooltip from '../components/ui/BaseTooltip.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import SettlementStatusPill from '../components/settlement/SettlementStatusPill.vue';
import ReconciliationCard from '../components/settlement/ReconciliationCard.vue';
import SettlementSourcesTable from '../components/settlement/SettlementSourcesTable.vue';
import SettlementAdjustmentModal from '../components/settlement/SettlementAdjustmentModal.vue';

// Dialog yang berat/jarang dipakai dimuat sesuai kebutuhan, bukan di bundel awal (Constitution V).
const SettlementAcknowledgeModal = defineAsyncComponent(() => import('../components/settlement/SettlementAcknowledgeModal.vue'));
const AddSettlementPaymentModal = defineAsyncComponent(() => import('../components/settlement/AddSettlementPaymentModal.vue'));
const SettlementDocumentModal = defineAsyncComponent(() => import('../components/settlement/SettlementDocumentModal.vue'));

// 076-settlement-tab-session-branding — satu laporan settlement seller.
// Layar ini TIDAK menghitung uang: setiap angka, status, dan hak aksi (`can`)
// datang dari server, dan setiap aksi tulis mengembalikan detail terbaru.
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToastStore();

const settlement = ref(null);
const loading = ref(true);
const loadError = ref(false);
const busy = ref(false);

async function load() {
  loading.value = true;
  loadError.value = false;
  try {
    settlement.value = (await getSettlement(route.params.id)).data;
  } catch {
    // Kegagalan memuat = keadaan gagal + Coba lagi, BUKAN keadaan kosong.
    loadError.value = true;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
watch(() => route.params.id, (id) => id && load());

const s = computed(() => settlement.value);
const isDraft = computed(() => s.value?.status === 'draft');

const directionLabel = computed(() => t(`settlements.direction_${s.value?.direction ?? 'none'}`));

/** Menjalankan aksi tulis: ganti state dengan detail terbaru; galat 409/422 ditangani handler pusat. */
async function run(action, successMessage) {
  busy.value = true;
  try {
    const res = await action();
    if (res?.data) settlement.value = res.data;
    if (successMessage) toast.success(successMessage);
    return res;
  } catch {
    return null;
  } finally {
    busy.value = false;
  }
}

// --- Aksi siklus ---------------------------------------------------------
const confirm = reactive({ kind: '' }); // finalize | reopen | delete | remove-adjustment
const pendingAdjustment = ref(null);
const pendingPayment = ref(null);

function ask(kind, target = null) {
  pendingAdjustment.value = kind === 'remove-adjustment' ? target : null;
  pendingPayment.value = kind === 'remove-payment' ? target : null;
  confirm.kind = kind;
}
const confirmMessage = computed(() => {
  switch (confirm.kind) {
    case 'finalize': return t('settlements.confirm_finalize');
    case 'reopen': return t('settlements.confirm_reopen');
    case 'delete': return t('settlements.confirm_delete');
    case 'remove-adjustment': return t('settlements.adj_delete_confirm', { label: pendingAdjustment.value?.label ?? '' });
    case 'remove-payment': return t('settlements.pay_delete_confirm', { amount: formatIDRExact(pendingPayment.value?.amount ?? 0) });
    default: return '';
  }
});

async function onConfirm() {
  const kind = confirm.kind;
  const id = s.value.id;

  if (kind === 'finalize') {
    const res = await run(() => finalizeSettlement(id));
    if (res) toast.success(t('settlements.finalized_toast', { number: res.data.number }));
  } else if (kind === 'reopen') {
    await run(() => reopenSettlement(id), t('settlements.reopened_toast'));
  } else if (kind === 'delete') {
    busy.value = true;
    try {
      await deleteSettlement(id);
      toast.success(t('settlements.deleted_toast'));
      confirm.kind = '';
      router.push({ name: 'settlements' });
      return;
    } catch {
      // 409 sudah di-toast oleh handler pusat
    } finally {
      busy.value = false;
    }
  } else if (kind === 'remove-adjustment') {
    await run(() => deleteSettlementAdjustment(id, pendingAdjustment.value.id));
  } else if (kind === 'remove-payment') {
    await run(() => deleteSettlementPayment(id, pendingPayment.value.id), t('settlements.pay_removed_toast'));
  }
  confirm.kind = '';
}

const refreshDraft = () => run(() => refreshSettlement(s.value.id), t('settlements.refreshed_toast'));

// --- Potongan & penyesuaian ---------------------------------------------
const adjModal = reactive({ open: false, kind: 'deduction', editing: null, errors: {} });

function openAdjustment(kind, editing = null) {
  Object.assign(adjModal, { open: true, kind, editing, errors: {} });
}

async function saveAdjustment(payload) {
  adjModal.errors = {};
  busy.value = true;
  try {
    const call = adjModal.editing
      ? updateSettlementAdjustment(s.value.id, adjModal.editing.id, payload)
      : addSettlementAdjustment(s.value.id, payload);
    settlement.value = (await call).data;
    adjModal.open = false;
  } catch (err) {
    if (err.isValidation) {
      adjModal.errors = Object.fromEntries(Object.entries(err.errors ?? {}).map(([k, v]) => [k, v[0]]));
    }
  } finally {
    busy.value = false;
  }
}

// --- Pengakuan seller -----------------------------------------------------
const ackModal = reactive({ open: false, saving: false, errors: {} });
const signaturePreview = ref(null); // object URL gambar tanda tangan (diambil lewat endpoint ber-izin)

function revokeSignaturePreview() {
  if (signaturePreview.value) URL.revokeObjectURL(signaturePreview.value);
  signaturePreview.value = null;
}

// Pratinjau tanda tangan mengikuti pengakuan yang berlaku; tidak pernah URL publik.
watch(
  () => s.value?.acknowledgement,
  async (ack) => {
    revokeSignaturePreview();
    if (!ack?.has_signature) return;
    try {
      signaturePreview.value = URL.createObjectURL(await getAcknowledgementSignature(s.value.id, ack.id));
    } catch {
      // pratinjau opsional; kegagalannya tidak menghalangi layar
    }
  },
  { immediate: true },
);
onBeforeUnmount(revokeSignaturePreview);

function fieldErrors(err) {
  return Object.fromEntries(Object.entries(err.errors ?? {}).map(([k, v]) => [k, v[0]]));
}

async function saveAcknowledgement(data) {
  ackModal.errors = {};
  ackModal.saving = true;
  try {
    settlement.value = (await acknowledgeSettlement(s.value.id, data)).data;
    ackModal.open = false;
    toast.success(t('settlements.ack_toast'));
  } catch (err) {
    if (err.isValidation) ackModal.errors = fieldErrors(err);
  } finally {
    ackModal.saving = false;
  }
}

// --- Pembayaran -----------------------------------------------------------
const payModal = reactive({ open: false, saving: false, errors: {} });

async function savePayment({ payload, proof }) {
  payModal.errors = {};
  payModal.saving = true;
  try {
    settlement.value = (await addSettlementPayment(s.value.id, payload, proof)).data;
    payModal.open = false;
    toast.success(t('settlements.pay_toast'));
  } catch (err) {
    if (err.isValidation) payModal.errors = fieldErrors(err);
  } finally {
    payModal.saving = false;
  }
}

async function viewPaymentProof(payment) {
  try {
    const url = URL.createObjectURL(await getSettlementPaymentProof(s.value.id, payment.id));
    window.open(url, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch {
    // handler pusat menampilkan galatnya
  }
}

// --- Dokumen cetak --------------------------------------------------------
const docModal = reactive({ open: false, kind: 'settlement', paymentId: null });

function openDocument(kind, paymentId = null) {
  Object.assign(docModal, { open: true, kind, paymentId });
}

function adjustmentKindLabel(kind) {
  return t(`settlements.adj_kind_${kind}`);
}
</script>

<template>
  <div class="flex flex-col gap-4 px-[26px] pb-10 pt-5">
    <RouterLink :to="{ name: 'settlements' }" class="inline-flex w-fit items-center gap-1.5 text-[13px] font-semibold text-muted-4 hover:text-brand-active">
      <i class="ph-duotone ph-arrow-left text-[15px]" aria-hidden="true"></i>
      {{ t('settlements.back_to_list') }}
    </RouterLink>

    <p v-if="loading && !s" class="text-[13px] text-muted-3">{{ t('common.loading_data') }}</p>

    <p v-else-if="loadError" role="alert" class="rounded-lg bg-danger-bg px-3.5 py-2.5 text-[13px] font-medium text-danger-text">
      {{ t('settlements.load_failed') }}
      <button type="button" class="ml-2 font-bold underline" @click="load">{{ t('common.retry') }}</button>
    </p>

    <template v-else-if="s">
      <!-- Kepala -->
      <header class="flex flex-wrap items-start justify-between gap-3 rounded-card border border-line-2 bg-white p-4">
        <div class="flex min-w-0 flex-col gap-1">
          <div class="flex flex-wrap items-center gap-2.5">
            <h2 class="font-mono text-[18px] font-extrabold tracking-tight" data-testid="settlement-number">{{ s.number ?? t('settlements.draft_title') }}</h2>
            <SettlementStatusPill :status="s.display_status" />
          </div>
          <p class="text-[13.5px] text-muted-4">
            <span class="font-semibold text-ink">{{ s.seller.name }}</span> · {{ s.event.name }}
          </p>
          <p class="text-[12.5px] text-muted-3">
            {{ directionLabel }}<template v-if="s.finalized_at"> · {{ formatDateTime(s.finalized_at) }}</template>
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <template v-if="isDraft">
            <BaseButton variant="secondary" size="sm" :disabled="busy" @click="refreshDraft">
              <i class="ph-duotone ph-arrows-clockwise text-[14px]" aria-hidden="true"></i>
              {{ t('settlements.action_refresh') }}
            </BaseButton>
            <BaseButton size="sm" :disabled="busy || !s.can.finalize" @click="ask('finalize')">
              <i class="ph-duotone ph-seal-check text-[14px]" aria-hidden="true"></i>
              {{ t('settlements.action_finalize') }}
            </BaseButton>
            <BaseTooltip :text="s.can.delete ? '' : t('settlements.reason_was_finalized')">
              <BaseButton variant="danger" size="sm" :disabled="busy || !s.can.delete" @click="ask('delete')">
                <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
                {{ t('settlements.action_delete') }}
              </BaseButton>
            </BaseTooltip>
          </template>
          <template v-else>
          <BaseButton variant="secondary" size="sm" data-testid="open-proof" @click="openDocument('settlement')">
            <i class="ph-duotone ph-printer text-[14px]" aria-hidden="true"></i>
            {{ t('settlements.doc_open_proof') }}
          </BaseButton>
          <BaseTooltip :text="s.can.reopen ? '' : t('settlements.reason_has_payments')">
            <BaseButton variant="secondary" size="sm" :disabled="busy || !s.can.reopen" @click="ask('reopen')">
              <i class="ph-duotone ph-arrow-counter-clockwise text-[14px]" aria-hidden="true"></i>
              {{ t('settlements.action_reopen') }}
            </BaseButton>
          </BaseTooltip>
          </template>
        </div>
      </header>

      <!-- Sumber berubah sejak final -->
      <div v-if="s.source_changed" role="status" class="rounded-lg border border-warn-border bg-warn-bg px-4 py-3 text-[13px] text-warn-text" data-testid="source-changed">
        <p class="font-bold">{{ t('settlements.source_changed_title') }}</p>
        <p class="mt-0.5">
          {{ t('settlements.source_changed_body', { pos: formatIDRExact(s.source_changed.pos_delta), preorder: formatIDRExact(s.source_changed.preorder_delta) }) }}
        </p>
      </div>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="flex flex-col gap-4 lg:col-span-2">
          <!-- Pendapatan -->
          <section class="rounded-card border border-line-2 bg-white p-4" :aria-label="t('settlements.revenue_title')">
            <h3 class="mb-2.5 text-[12.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('settlements.revenue_title') }}</h3>
            <dl class="flex flex-col gap-1.5 text-[13.5px]">
              <div class="flex items-center justify-between gap-3">
                <dt>{{ t('settlements.revenue_pos') }} <span class="text-muted-3">· {{ t('settlements.units_n', { count: s.pos_units }) }}</span></dt>
                <dd class="tabular-nums">{{ formatIDRExact(s.pos_revenue) }}</dd>
              </div>
              <div class="flex items-center justify-between gap-3">
                <dt>{{ t('settlements.revenue_preorder') }} <span class="text-muted-3">· {{ t('settlements.units_n', { count: s.preorder_units }) }}</span></dt>
                <dd class="tabular-nums">{{ formatIDRExact(s.preorder_revenue) }}</dd>
              </div>
              <div class="flex items-center justify-between gap-3 border-t border-dashed border-line-2 pt-1.5 font-bold">
                <dt>{{ t('settlements.revenue_total') }}</dt>
                <dd class="tabular-nums" data-testid="revenue-total">{{ formatIDRExact(s.revenue_total) }}</dd>
              </div>
            </dl>
          </section>

          <!-- Potongan & penyesuaian -->
          <section class="rounded-card border border-line-2 bg-white p-4" :aria-label="t('settlements.adjustments_title')">
            <div class="mb-2.5 flex flex-wrap items-center justify-between gap-2">
              <h3 class="text-[12.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('settlements.adjustments_title') }}</h3>
              <div class="flex gap-2">
                <BaseButton v-if="isDraft" variant="secondary" size="sm" :disabled="busy" @click="openAdjustment('deduction')">
                  <i class="ph-duotone ph-minus-circle text-[14px]" aria-hidden="true"></i>
                  {{ t('settlements.add_deduction') }}
                </BaseButton>
                <BaseButton v-if="s.can.adjust" variant="secondary" size="sm" :disabled="busy" @click="openAdjustment('adjustment')">
                  <i class="ph-duotone ph-plus-minus text-[14px]" aria-hidden="true"></i>
                  {{ t('settlements.add_adjustment') }}
                </BaseButton>
              </div>
            </div>

            <p v-if="!s.adjustments.length" class="text-[13px] text-muted-3">{{ t('settlements.adjustments_empty') }}</p>
            <ul v-else class="flex flex-col divide-y divide-line-3">
              <li v-for="adj in s.adjustments" :key="adj.id" class="flex items-start justify-between gap-3 py-2.5" data-testid="adjustment-row">
                <div class="min-w-0">
                  <p class="text-[13.5px] font-semibold">
                    {{ adj.label }}
                    <span class="ml-1.5 rounded-full bg-line-7 px-2 py-0.5 text-[10.5px] font-bold uppercase text-muted-4">{{ adjustmentKindLabel(adj.kind) }}</span>
                    <span v-if="adj.post_finalization" class="ml-1 rounded-full bg-warn-bg px-2 py-0.5 text-[10.5px] font-bold text-warn-text">{{ t('settlements.adj_post_final') }}</span>
                  </p>
                  <p class="text-[12.5px] text-muted-4">{{ adj.reason }}</p>
                  <p v-if="adj.basis" class="text-[12px] text-muted-3">{{ adj.basis }}</p>
                </div>
                <div class="flex flex-none items-center gap-2">
                  <span class="text-[13.5px] font-semibold tabular-nums" :class="Number(adj.amount) < 0 ? 'text-danger-text' : ''">{{ formatIDRExact(adj.amount) }}</span>
                  <template v-if="isDraft">
                    <button type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-muted-4 hover:border-brand hover:text-brand-active" :aria-label="t('common.edit')" @click="openAdjustment(adj.kind, adj)">
                      <i class="ph-duotone ph-pencil-simple text-[14px]" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="flex h-[30px] w-[30px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('common.delete')" @click="ask('remove-adjustment', adj)">
                      <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
                    </button>
                  </template>
                </div>
              </li>
            </ul>
          </section>

          <!-- Total settlement -->
          <section class="rounded-card border border-line-2 bg-white p-4" :aria-label="t('settlements.net_title')">
            <h3 class="mb-2.5 text-[12.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('settlements.net_title') }}</h3>
            <dl class="flex flex-col gap-1.5 text-[13.5px]">
              <div class="flex items-center justify-between gap-3">
                <dt class="font-bold">{{ t('settlements.net_label') }}</dt>
                <dd class="text-[20px] font-extrabold tracking-tight tabular-nums" :class="Number(s.net_amount) < 0 ? 'text-danger-text' : ''" data-testid="net-amount">{{ formatIDRExact(s.net_amount) }}</dd>
              </div>
              <div class="flex items-center justify-between gap-3 text-muted-4">
                <dt>{{ t('settlements.paid_label') }}</dt>
                <dd class="tabular-nums">{{ formatIDRExact(s.paid_amount) }}</dd>
              </div>
              <div class="flex items-center justify-between gap-3 border-t border-dashed border-line-2 pt-1.5 font-bold">
                <dt>{{ t('settlements.balance_label') }}</dt>
                <dd class="tabular-nums" data-testid="balance">{{ formatIDRExact(s.balance) }}</dd>
              </div>
            </dl>
          </section>

          <!-- Pengakuan seller (hanya setelah difinalkan) -->
          <section v-if="!isDraft" class="rounded-card border border-line-2 bg-white p-4" :aria-label="t('settlements.ack_title')" data-testid="ack-section">
            <div class="mb-2.5 flex flex-wrap items-center justify-between gap-2">
              <h3 class="text-[12.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('settlements.ack_title') }}</h3>
              <BaseButton v-if="s.can.acknowledge" variant="secondary" size="sm" :disabled="busy" data-testid="open-ack" @click="ackModal.open = true">
                <i class="ph-duotone ph-signature text-[14px]" aria-hidden="true"></i>
                {{ s.acknowledgement ? t('settlements.ack_replace') : t('settlements.ack_add') }}
              </BaseButton>
            </div>

            <p v-if="!s.acknowledgement" class="text-[13px] text-muted-3" data-testid="ack-none">{{ t('settlements.ack_none') }}</p>
            <div v-else class="flex flex-wrap items-start gap-4" data-testid="ack-current">
              <img v-if="signaturePreview" :src="signaturePreview" :alt="t('settlements.ack_signature_alt')" class="h-[72px] max-w-[220px] rounded-md border border-line-3 bg-white object-contain p-1" />
              <div class="text-[13px]">
                <p v-if="s.acknowledgement.signer_name" class="font-semibold">{{ s.acknowledgement.signer_name }}</p>
                <p class="text-muted-4">{{ t(`settlements.ack_method_${s.acknowledgement.method}`) }} · {{ formatDateTime(s.acknowledgement.acknowledged_at) }}</p>
                <p v-if="s.acknowledgement.method === 'external'" class="text-[12.5px] font-semibold text-warn-text">{{ t('settlements.ack_external_label') }}<template v-if="s.acknowledgement.note"> — {{ s.acknowledgement.note }}</template></p>
                <p v-if="s.acknowledgement.recorded_by_name" class="text-[12px] text-muted-3">{{ t('settlements.ack_by', { name: s.acknowledgement.recorded_by_name }) }}</p>
              </div>
            </div>

            <details v-if="s.acknowledgement_history.length" class="mt-3 text-[12.5px] text-muted-4">
              <summary class="cursor-pointer font-semibold">{{ t('settlements.ack_history') }} ({{ s.acknowledgement_history.length }})</summary>
              <ul class="mt-1.5 flex flex-col gap-1">
                <li v-for="h in s.acknowledgement_history" :key="h.id">
                  {{ h.signer_name || t(`settlements.ack_method_${h.method}`) }} · {{ formatDateTime(h.acknowledged_at) }}
                  — {{ t(`settlements.ack_superseded_${h.superseded_reason}`) }}
                </li>
              </ul>
            </details>
          </section>

          <!-- Pembayaran -->
          <section v-if="!isDraft" class="rounded-card border border-line-2 bg-white p-4" :aria-label="t('settlements.pay_title')" data-testid="payments-section">
            <div class="mb-2.5 flex flex-wrap items-center justify-between gap-2">
              <h3 class="text-[12.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('settlements.pay_title') }}</h3>
              <BaseButton v-if="s.can.add_payment" variant="secondary" size="sm" :disabled="busy" data-testid="open-payment" @click="payModal.open = true">
                <i class="ph-duotone ph-plus text-[14px]" aria-hidden="true"></i>
                {{ t('settlements.pay_add') }}
              </BaseButton>
            </div>

            <p v-if="!s.payments.length" class="text-[13px] text-muted-3">{{ t('settlements.pay_none') }}</p>
            <ul v-else class="flex flex-col divide-y divide-line-3">
              <li v-for="p in s.payments" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5" data-testid="payment-row">
                <div class="text-[13px]">
                  <p class="font-semibold tabular-nums">{{ formatIDRExact(p.amount) }}</p>
                  <p class="text-[12px] text-muted-4">
                    {{ p.paid_at }} · {{ t(`settlements.pay_method_${p.method}`) }}<template v-if="p.reference"> · {{ p.reference }}</template>
                    <template v-if="p.recorded_by_name"> · {{ t('settlements.pay_recorded_by', { name: p.recorded_by_name }) }}</template>
                  </p>
                </div>
                <div class="flex items-center gap-3 text-[12.5px] font-semibold">
                  <button v-if="p.has_proof" type="button" class="text-brand-active hover:underline" @click="viewPaymentProof(p)">{{ t('settlements.pay_view_proof') }}</button>
                  <button type="button" class="text-brand-active hover:underline" @click="openDocument('payment', p.id)">{{ t('settlements.pay_print_proof') }}</button>
                  <button type="button" class="text-danger-text hover:underline" :aria-label="t('common.delete')" @click="ask('remove-payment', p)">
                    <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
                  </button>
                </div>
              </li>
            </ul>
          </section>
        </div>

        <div class="flex flex-col gap-4">
          <ReconciliationCard :reconciliation="s.reconciliation" />

          <section class="rounded-card border border-line-2 bg-white p-4" :aria-label="t('settlements.memo_title')">
            <h3 class="mb-2.5 text-[12.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('settlements.memo_title') }}</h3>
            <dl class="flex flex-col gap-1.5 text-[13px]">
              <div class="flex items-center justify-between gap-3"><dt class="text-muted-4">{{ t('settlements.memo_uncollected') }}</dt><dd class="tabular-nums">{{ formatIDRExact(s.memo.preorder_uncollected) }}</dd></div>
              <div class="flex items-center justify-between gap-3"><dt class="text-muted-4">{{ t('settlements.memo_cogs') }}</dt><dd class="tabular-nums">{{ formatIDRExact(s.memo.cost_of_goods) }}</dd></div>
              <div class="flex items-center justify-between gap-3"><dt class="text-muted-4">{{ t('settlements.memo_profit') }}</dt><dd class="tabular-nums">{{ formatIDRExact(s.memo.gross_profit) }}</dd></div>
            </dl>
          </section>
        </div>
      </div>

      <!-- Rincian transaksi -->
      <section class="overflow-hidden rounded-card border border-line-2 bg-white" :aria-label="t('settlements.sources_title')">
        <div class="flex items-center justify-between gap-3 border-b border-line-3 px-4 py-3">
          <h3 class="text-[12.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('settlements.sources_title') }}</h3>
          <p class="text-[11.5px] text-muted-3">{{ t('settlements.rounding_note') }}</p>
        </div>
        <SettlementSourcesTable :sources="s.sources" />
      </section>
    </template>

    <ConfirmDialog
      :open="confirm.kind !== ''"
      :message="confirmMessage"
      :loading="busy"
      @confirm="onConfirm"
      @close="confirm.kind = ''"
    />

    <SettlementAcknowledgeModal
      v-if="ackModal.open"
      :open="ackModal.open"
      :saving="ackModal.saving"
      :errors="ackModal.errors"
      @save="saveAcknowledgement"
      @close="ackModal.open = false"
    />

    <AddSettlementPaymentModal
      v-if="payModal.open"
      :open="payModal.open"
      :settlement="s"
      :saving="payModal.saving"
      :errors="payModal.errors"
      @save="savePayment"
      @close="payModal.open = false"
    />

    <SettlementDocumentModal
      v-if="docModal.open && s"
      :open="docModal.open"
      :kind="docModal.kind"
      :settlement-id="s.id"
      :payment-id="docModal.paymentId"
      @close="docModal.open = false"
    />

    <SettlementAdjustmentModal
      :open="adjModal.open"
      :kind="adjModal.kind"
      :adjustment="adjModal.editing"
      :saving="busy"
      :errors="adjModal.errors"
      @save="saveAdjustment"
      @close="adjModal.open = false"
    />
  </div>
</template>
