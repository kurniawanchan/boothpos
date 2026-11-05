<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseTooltip from '../ui/BaseTooltip.vue';
import { approvalMeta, approvalMethodKey } from '../../utils/approval';
import { formatDateTime } from '../../utils/date';

/**
 * 059 (US3) — ikon kecil status persetujuan KESELURUHAN di samping nomor transaksi + popover rincian per seller.
 * Datanya hanya ringkasan dari baris daftar (`row.approval`): tak ada permintaan jaringan dan tak ada gambar.
 * Status selalu dari server (`pending|partial|full|voided`); komponen ini tidak menghitung ulang apa pun.
 *
 * Popover di-teleport ke <body> dengan posisi `fixed` (teknik BaseTooltip): sel DataTable berada di dalam
 * `overflow-auto`, jadi elemen `absolute` akan terpotong. Ditutup dengan Escape (fokus kembali ke ikon),
 * klik di luar, atau saat halaman digulung/ukuran berubah.
 */
const props = defineProps({
  approval: { type: Object, default: null },
});
const { t } = useI18n();

const WIDTH = 300;
const MARGIN = 8;
const GAP = 6;

const id = useId();
const open = ref(false);
const buttonEl = ref(null);
const panelEl = ref(null);
const style = ref({});

const meta = computed(() => approvalMeta(props.approval?.status));
const statusLabel = computed(() => t(meta.value.key));
const progress = computed(() => `${props.approval?.approved ?? 0}/${props.approval?.total ?? 0}`);
const tooltip = computed(() =>
  props.approval?.status === 'voided'
    ? statusLabel.value
    : `${statusLabel.value}\n${t('approvals.progress', { approved: props.approval?.approved ?? 0, total: props.approval?.total ?? 0 })}`,
);

function methodLabel(method) {
  const key = approvalMethodKey(method);
  return key ? t(key) : method;
}

function place() {
  const r = buttonEl.value?.getBoundingClientRect();
  if (!r) return;
  const below = window.innerHeight - r.bottom;
  const openUp = below < 240 && r.top > below;
  const left = Math.max(MARGIN, Math.min(r.left, window.innerWidth - MARGIN - WIDTH));
  style.value = {
    position: 'fixed',
    left: `${left}px`,
    width: `${WIDTH}px`,
    maxHeight: `${Math.max(160, (openUp ? r.top : below) - MARGIN - GAP)}px`,
    ...(openUp ? { bottom: `${window.innerHeight - r.top + GAP}px` } : { top: `${r.bottom + GAP}px` }),
  };
}

function onKey(event) {
  if (event.key === 'Escape') close(true);
}
function onOutside(event) {
  if (panelEl.value?.contains(event.target) || buttonEl.value?.contains(event.target)) return;
  close(false);
}

function close(returnFocus = false) {
  if (!open.value) return;
  open.value = false;
  window.removeEventListener('keydown', onKey);
  window.removeEventListener('pointerdown', onOutside, true);
  window.removeEventListener('scroll', closeQuiet, true);
  window.removeEventListener('resize', closeQuiet);
  if (returnFocus) buttonEl.value?.focus();
}
const closeQuiet = () => close(false);

async function toggle() {
  if (open.value) return close(true);
  place();
  open.value = true;
  window.addEventListener('keydown', onKey);
  window.addEventListener('pointerdown', onOutside, true);
  window.addEventListener('scroll', closeQuiet, true);
  window.addEventListener('resize', closeQuiet);
  await nextTick();
  panelEl.value?.focus();
}

onBeforeUnmount(() => close(false));
</script>

<template>
  <span v-if="approval" class="inline-flex" data-testid="approval-icon-wrap">
    <BaseTooltip :text="tooltip">
      <button
        ref="buttonEl"
        type="button"
        class="inline-flex h-6 w-6 items-center justify-center rounded-full hover:bg-surface-subtle focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand"
        data-testid="approval-icon"
        :data-status="approval.status"
        :aria-label="t('approvals.icon_label', { status: statusLabel })"
        :aria-expanded="open"
        :aria-controls="id"
        aria-haspopup="dialog"
        @click.stop="toggle"
      >
        <i class="ph-duotone text-[17px]" :class="[meta.icon, meta.tone]" aria-hidden="true"></i>
      </button>
    </BaseTooltip>

    <Teleport to="body">
      <div
        v-if="open"
        :id="id"
        ref="panelEl"
        role="dialog"
        tabindex="-1"
        :aria-label="t('approvals.popover_title', { status: statusLabel })"
        :style="style"
        class="z-[90] flex flex-col gap-2.5 overflow-auto rounded-xl border border-line-2 bg-white p-3.5 text-[12.5px] shadow-lg outline-none"
        data-testid="approval-popover"
      >
        <div class="flex items-start justify-between gap-2">
          <span class="text-[13px] font-bold leading-snug">{{ t('approvals.popover_title', { status: statusLabel }) }}</span>
          <button type="button" class="shrink-0 text-muted-3 hover:text-ink" :aria-label="t('approvals.popover_close')" @click="close(true)">
            <i class="ph-duotone ph-x text-[15px]" aria-hidden="true"></i>
          </button>
        </div>

        <template v-if="approval.status !== 'voided'">
          <p class="text-muted-4" data-testid="approval-popover-counts">
            {{ t('approvals.popover_sellers', { count: approval.total }) }} · {{ t('approvals.popover_approved', { count: approval.approved }) }} · {{ t('approvals.popover_pending', { count: approval.pending }) }}
          </p>

          <ul class="flex flex-col gap-2" data-testid="approval-popover-sellers">
            <li v-for="s in approval.sellers" :key="s.artist_id" class="flex flex-col gap-0.5 rounded-lg bg-surface-subtle px-2.5 py-2">
              <div class="flex items-center justify-between gap-2">
                <span class="min-w-0 truncate font-semibold">{{ s.name }}</span>
                <span class="shrink-0 font-bold" :class="s.status === 'approved' ? 'text-brand-active' : 'text-warn-text'">
                  {{ s.status === 'approved' ? t('approvals.seller_approved') : t('approvals.seller_pending') }}
                </span>
              </div>
              <template v-if="s.status === 'approved'">
                <span class="text-muted-4">{{ t('approvals.approved_by', { name: s.approver_name }) }}</span>
                <span class="text-muted-3">{{ formatDateTime(s.approved_at) }} · {{ methodLabel(s.method) }}</span>
              </template>
              <span v-else-if="s.stale" class="font-semibold text-warn-text">{{ t('approvals.stale_cue') }}</span>
            </li>
          </ul>

          <p class="border-t border-dashed border-line-2 pt-2 font-semibold" data-testid="approval-popover-progress">
            {{ t('approvals.popover_progress', { progress }) }}
          </p>
        </template>
      </div>
    </Teleport>
  </span>
</template>
