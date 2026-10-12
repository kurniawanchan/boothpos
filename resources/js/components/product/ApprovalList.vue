<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listApprovals, approveApproval, rejectApproval } from '../../api/menu';
import { useAuthStore } from '../../stores/auth';
import { useToastStore } from '../../stores/toast';
import { formatIDR } from '../../utils/formatters';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import LoadingState from '../ui/LoadingState.vue';
import ErrorState from '../ui/ErrorState.vue';
import ItemReasonDialog from './ItemReasonDialog.vue';

/**
 * 043 (US10, T068) — daftar persetujuan harga untuk pemegang menu.price.approve: item, harga lama -> baru, selisih %,
 * pengaju, Setujui / Tolak (alasan berkode kategori `approval_reject`).
 *
 * Pemisahan tugas: pengaju tidak boleh memutuskan permintaannya sendiri (server menjawab 409 `self_approval` untuk
 * setujui MAUPUN tolak), jadi kedua tombol disembunyikan pada baris milik sendiri dan diganti keterangan. Respons
 * daftar memuat `requested_by_id` dan nama pengaju; pembandingannya memakai id, tidak pernah nama.
 *
 * Komponen tidak dirender sama sekali tanpa izin (pemanggil menggerbang; server tetap 403).
 */
const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'changed']);
const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const rows = ref([]);
const threshold = ref(null);
const loadState = ref('loading'); // loading | ready | error
const busyId = ref(null);
const rejectTarget = ref(null);
let seq = 0;

async function load() {
  const mine = ++seq;
  loadState.value = 'loading';
  try {
    const res = await listApprovals({ kind: 'menu_price', status: 'pending' });
    if (mine !== seq) return;
    rows.value = res.data ?? [];
    threshold.value = res.threshold_pct ?? null;
    loadState.value = 'ready';
  } catch {
    if (mine === seq) loadState.value = 'error';
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) load();
  },
  { immediate: true }
);

// Pembanding adalah id pengaju dari server (`requested_by_id`), BUKAN nama: dua pengguna bisa senama. Tanpa id
// (respons lama) baris dianggap bukan milik sendiri; server tetap menolak dengan 409 self_approval.
function isOwn(row) {
  return row.requested_by_id !== undefined && row.requested_by_id !== null && row.requested_by_id === auth.user?.id;
}
// 045: perubahan harga outlet menyebut outletnya ("@ WJ2"); harga dasar tidak membawa outlet_code.
const nameOf = (row) => [row.product_name, row.variant_name].filter(Boolean).join(' · ') + (row.outlet_code ? ` @ ${row.outlet_code}` : '');

async function approve(row) {
  if (busyId.value) return;
  busyId.value = row.id;
  try {
    await approveApproval(row.id);
    toast.success(t('products.approvals_approved'));
    emit('changed');
  } catch {
    // 409 (sudah diputuskan / permintaan sendiri) sudah di-toast handler pusat; muat ulang kebenarannya.
  } finally {
    busyId.value = null;
    await load();
  }
}

const rejectSubmit = (payload) => rejectApproval(rejectTarget.value.id, payload);
async function afterReject() {
  rejectTarget.value = null;
  toast.success(t('products.approvals_rejected'));
  emit('changed');
  await load();
}
async function afterRejectConflict() {
  rejectTarget.value = null;
  await load();
}
</script>

<template>
  <BaseModal :open="open" :title="t('products.approvals_title')" max-width-class="max-w-[720px]" @close="emit('close')">
    <div class="flex flex-col gap-4 px-6 py-5">
      <p v-if="threshold !== null" class="text-[13px] leading-relaxed text-muted-4">{{ t('products.approvals_intro', { threshold }) }}</p>
      <LoadingState v-if="loadState === 'loading'" :rows="3" />
      <ErrorState v-else-if="loadState === 'error'" @retry="load" />
      <p v-else-if="!rows.length" class="py-6 text-center text-[13px] text-muted-3" data-testid="approvals-none">{{ t('products.approvals_none') }}</p>
      <ul v-else class="border-2 border-line bg-white" data-testid="approvals-list">
        <li v-for="row in rows" :key="row.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line-5 px-4 py-3 last:border-b-0" data-testid="approval-row">
          <div class="min-w-0 flex-1">
            <p class="truncate text-[13.5px] font-bold">{{ nameOf(row) }}</p>
            <p class="text-[12.5px] text-muted-4">
              <span class="tabular-nums">{{ formatIDR(row.old_price) }} → <strong class="text-ink">{{ formatIDR(row.new_price) }}</strong></span>
              · {{ t('products.approvals_difference', { pct: row.difference_pct }) }}
            </p>
            <p v-if="row.requested_by" class="text-[12px] text-muted-3">{{ t('products.release_requested_by', { name: row.requested_by }) }}</p>
          </div>
          <p v-if="isOwn(row)" class="max-w-[220px] text-[12px] text-muted-3" data-testid="approval-own">{{ t('products.approvals_own') }}</p>
          <div v-else class="flex items-center gap-2">
            <BaseButton size="sm" variant="secondary" :aria-label="t('products.approvals_reject_aria', { name: nameOf(row) })" :disabled="busyId === row.id" @click="rejectTarget = row">{{ t('products.approvals_reject') }}</BaseButton>
            <BaseButton size="sm" :aria-label="t('products.approvals_approve_aria', { name: nameOf(row) })" :loading="busyId === row.id" @click="approve(row)">{{ t('products.approvals_approve') }}</BaseButton>
          </div>
        </li>
      </ul>
    </div>
    <template #footer>
      <div class="flex justify-end"><BaseButton variant="secondary" @click="emit('close')">{{ t('page.close') }}</BaseButton></div>
    </template>
  </BaseModal>

  <ItemReasonDialog
    :open="!!rejectTarget"
    category="approval_reject"
    :title="t('products.approvals_reject_title', { name: rejectTarget ? nameOf(rejectTarget) : '' })"
    :message="t('products.approvals_reject_message')"
    :confirm-label="t('products.approvals_reject_confirm')"
    :submit="rejectSubmit"
    @close="rejectTarget = null"
    @done="afterReject"
    @conflict="afterRejectConflict"
  />
</template>
