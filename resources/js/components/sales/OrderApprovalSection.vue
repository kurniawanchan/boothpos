<script setup>
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import ApprovalStamp from './ApprovalStamp.vue';
import ApprovalImage from './ApprovalImage.vue';
import { getApprovals, approveSeller } from '../../api/orderApprovals';
import { approvalMeta, approvalMethodKey } from '../../utils/approval';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { useAuthStore } from '../../stores/auth';
import { useToastStore } from '../../stores/toast';

// Dialog (dan kanvasnya) dimuat hanya saat pertama kali dibuka — bundel Sales tidak membengkak (Constitution V).
const ApproveSellerModal = defineAsyncComponent(() => import('./ApproveSellerModal.vue'));

/**
 * 059 — bagian "Persetujuan seller" di detail transaksi. Status keseluruhan dan status per seller sengaja
 * tampil TERPISAH dan berbeda rupa (kepala bagian vs pil kecil per seller). Semua keputusan (status, hak
 * `can_approve`, boleh-lihat-gambar, riwayat) datang dari server; SPA tidak menurunkannya dari peran.
 * Membuka halaman TIDAK PERNAH menyetujui apa pun — hanya GET.
 */
const props = defineProps({
  orderId: { type: [Number, String], required: true },
});
const emit = defineEmits(['changed']);

const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const data = ref(null);
const loading = ref(false);
const loadError = ref('');
let generation = 0;

async function load() {
  generation += 1;
  const mine = generation;
  loading.value = true;
  loadError.value = '';
  try {
    const result = await getApprovals(props.orderId);
    if (mine === generation) data.value = result;
  } catch (err) {
    // Gagal memuat adalah keadaan GAGAL, bukan keadaan kosong: tampilkan pesan + Coba lagi.
    if (mine === generation) loadError.value = err.message || t('approvals.load_failed');
  } finally {
    if (mine === generation) loading.value = false;
  }
}

watch(() => props.orderId, load, { immediate: true });

const meta = computed(() => approvalMeta(data.value?.status));
const sellers = computed(() => data.value?.sellers ?? []);
const history = computed(() => data.value?.history ?? null);

// Alasan pembatalan dari server → teks; alasan baru yang belum dikenal tampil apa adanya, bukan kunci mentah.
function reasonLabel(reason) {
  const known = ['items_changed'];
  return known.includes(reason) ? t(`approvals.reason_${reason}`) : reason;
}

function methodLabel(method) {
  const key = approvalMethodKey(method);
  return key ? t(key) : method;
}

// --- Menyetujui -------------------------------------------------------------------------------
const target = ref(null); // seller yang sedang disetujui
const saving = ref(false);
const serverError = ref('');
const loadedModal = ref(false); // komponen async baru dipasang setelah dibuka pertama kali

const canApproveAny = computed(() => auth.canDo('sales', 'approve'));
const onBehalf = computed(() => !!target.value && auth.user?.artist?.id !== target.value.artist_id);

function openApprove(seller) {
  serverError.value = '';
  target.value = seller;
  loadedModal.value = true;
}

function closeApprove() {
  if (saving.value) return;
  target.value = null;
}

async function submit({ method, image }) {
  const seller = target.value;
  if (!seller) return;
  saving.value = true;
  serverError.value = '';
  try {
    data.value = await approveSeller(props.orderId, {
      artistId: seller.artist_id, method, itemsHash: seller.items_hash, image,
    });
    toast.success(t('approvals.success', { seller: seller.artist_name }));
    target.value = null;
    emit('changed');
  } catch (err) {
    if (['already_approved', 'approval_stale', 'order_voided'].includes(err.code)) {
      // Layar basi: pesan 409 sudah ditoast klien API. Tutup dialog dan muat ulang supaya pengguna meninjau yang terbaru.
      target.value = null;
      await load();
    } else {
      serverError.value = err.errors?.image?.[0] || err.errors?.artist_id?.[0] || err.message;
    }
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <section class="flex flex-col gap-3 rounded-lg border border-line-2 px-4 py-3.5" data-testid="order-approval">
    <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">{{ t('approvals.section_title') }}</span>

    <p v-if="loading && !data" class="text-[13px] text-muted-3">{{ t('common.loading_data') }}</p>

    <div v-else-if="loadError && !data" role="alert" class="flex flex-wrap items-center gap-3 text-[13px] text-danger-text" data-testid="approval-load-error">
      <span>{{ loadError }}</span>
      <BaseButton variant="secondary" data-testid="approval-retry" @click="load">{{ t('common.retry') }}</BaseButton>
    </div>

    <template v-else-if="data">
      <!-- Status KESELURUHAN transaksi -->
      <div class="flex flex-wrap items-center justify-between gap-2" data-testid="approval-overall">
        <div class="flex items-center gap-2">
          <i class="ph-duotone text-[20px]" :class="[meta.icon, meta.tone]" aria-hidden="true"></i>
          <span class="text-[13px] font-bold">{{ t('approvals.overall_label') }}</span>
          <StatusPill :variant="meta.pill" data-testid="approval-overall-status">{{ t(meta.key) }}</StatusPill>
        </div>
        <span v-if="data.total > 0" class="text-[12.5px] font-semibold text-muted-4" data-testid="approval-progress">
          {{ t('approvals.progress', { approved: data.approved, total: data.total }) }}
        </span>
      </div>

      <p v-if="sellers.length === 0" class="text-[13px] text-muted-3">{{ t('approvals.no_sellers') }}</p>

      <!-- Status PER SELLER -->
      <ul class="flex flex-col gap-2.5">
        <li
          v-for="s in sellers"
          :key="s.artist_id"
          class="flex flex-col gap-2 rounded-lg border border-line-2 bg-surface-subtle px-3.5 py-3"
          :data-testid="`approval-seller-${s.artist_id}`"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex min-w-0 flex-col">
              <span class="truncate text-[13.5px] font-bold">{{ s.artist_name }}</span>
              <span class="text-[12px] text-muted-3">{{ t('approvals.items_count', { count: s.items.length }) }} · {{ formatIDR(s.subtotal) }}</span>
            </div>
            <div class="flex items-center gap-2">
              <StatusPill :variant="s.status === 'approved' ? 'mint' : 'warn'" data-testid="approval-seller-status">
                {{ s.status === 'approved' ? t('approvals.seller_approved') : t('approvals.seller_pending') }}
              </StatusPill>
              <BaseButton v-if="s.can_approve && canApproveAny" data-testid="approval-approve" @click="openApprove(s)">
                {{ t('approvals.approve_action') }}
              </BaseButton>
            </div>
          </div>

          <ul class="flex flex-col gap-0.5 text-[12.5px] text-muted-4">
            <li v-for="(line, i) in s.items" :key="i" class="flex justify-between gap-3">
              <span class="min-w-0 truncate">{{ line.qty }} × {{ line.name }}</span>
              <span class="shrink-0 font-semibold">{{ formatIDR(line.line_total) }}</span>
            </li>
          </ul>

          <p v-if="s.stale" class="text-[12.5px] font-semibold text-warn-text" data-testid="approval-stale">{{ t('approvals.stale_cue') }}</p>

          <div v-if="s.approval" class="flex flex-wrap items-center gap-3 border-t border-dashed border-line-2 pt-2.5" data-testid="approval-detail">
            <ApprovalImage
              v-if="s.approval.has_image && s.approval.can_view_image"
              :order-id="orderId"
              :approval-id="s.approval.id"
              :alt="t('approvals.image_alt', { seller: s.artist_name })"
            />
            <span v-else-if="s.approval.has_image" class="text-[12px] text-muted-3" data-testid="approval-image-restricted">{{ t('approvals.image_restricted') }}</span>
            <ApprovalStamp
              v-else-if="s.approval.method === 'stamp'"
              :seller="s.artist_name"
              :approver="s.approval.approver_name"
              :date="formatDateTime(s.approval.approved_at)"
            />
            <div class="flex flex-col gap-0.5 text-[12.5px]">
              <span class="font-semibold">{{ t('approvals.approved_by', { name: s.approval.approver_name }) }}</span>
              <span class="text-muted-3">{{ formatDateTime(s.approval.approved_at) }} · {{ methodLabel(s.approval.method) }}</span>
              <span v-if="s.approval.on_behalf" class="w-fit rounded-full bg-warn-bg px-2 py-0.5 text-[11px] font-bold text-warn-text">{{ t('approvals.on_behalf_badge') }}</span>
            </div>
          </div>
        </li>
      </ul>

      <!-- Riwayat SEMUA siklus: hanya ada di payload untuk owner/admin (server tidak mengirimnya ke yang lain). -->
      <details v-if="history && history.length" class="rounded-lg border border-line-2 px-3.5 py-2.5 text-[12.5px]" data-testid="approval-history">
        <summary class="cursor-pointer font-semibold">{{ t('approvals.history_title') }} ({{ history.length }})</summary>
        <ul class="mt-2 flex flex-col gap-1.5">
          <li v-for="h in history" :key="h.id" class="flex flex-col gap-0.5 border-t border-dashed border-line-2 pt-1.5 first:border-t-0 first:pt-0" :data-testid="`approval-history-${h.id}`">
            <span class="font-semibold">{{ h.artist_name }} — {{ t('approvals.approved_by', { name: h.approver_name }) }}</span>
            <span class="text-muted-3">{{ formatDateTime(h.approved_at) }} · {{ methodLabel(h.method) }}<template v-if="h.on_behalf"> · {{ t('approvals.on_behalf_badge') }}</template><template v-if="h.via_bulk"> · <span :data-testid="`approval-history-bulk-${h.id}`">{{ t('approvals.bulk_via') }}</span></template></span>
            <span v-if="h.invalidated_at" class="font-semibold text-warn-text">
              {{ t('approvals.history_invalidated', { reason: reasonLabel(h.invalidation_reason) }) }} · {{ formatDateTime(h.invalidated_at) }}
            </span>
          </li>
        </ul>
      </details>
    </template>

    <ApproveSellerModal
      v-if="loadedModal"
      :open="!!target"
      :seller="target"
      :on-behalf="onBehalf"
      :saving="saving"
      :server-error="serverError"
      @submit="submit"
      @close="closeApprove"
    />
  </section>
</template>
