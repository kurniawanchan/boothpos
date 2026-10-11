<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listMenuChanges, createMenuRelease, cancelMenuChange } from '../../api/menu';
import { useToastStore } from '../../stores/toast';
import { formatIDR } from '../../utils/formatters';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import LoadingState from '../ui/LoadingState.vue';
import ErrorState from '../ui/ErrorState.vue';
import StatusPill from '../ui/StatusPill.vue';

/**
 * 043 (US10, T068) — dialog "Rilis menu": daftar perubahan yang belum dirilis, pilih yang ikut, rilis sekarang atau
 * dijadwalkan, catatan opsional.
 *
 * Hanya perubahan berstatus `pending` (tak butuh persetujuan) dan `approved` yang siap; `awaiting_approval` dan
 * `rejected` tampil NONAKTIF dengan statusnya (tidak bisa dipilih) — server menolak memilihnya dengan 409
 * `changes_not_ready`, dan 409 itu dijelaskan di dalam dialog (bukan hanya toast) karena pilihan harus diperbaiki.
 * Memuat gagal = keadaan galat + Coba lagi, bukan daftar kosong.
 *
 * `publish_at` null = rilis sekarang. Waktu jadwal dikirim sebagai ISO 8601 dari input datetime-local (zona perambah).
 */
const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'released', 'changed']);
const { t } = useI18n();
const toast = useToastStore();

const READY = ['pending', 'approved'];
const STATUS_VARIANT = { pending: 'info', approved: 'success', awaiting_approval: 'warning', rejected: 'danger' };

const changes = ref([]);
const loadState = ref('loading'); // loading | ready | error
const chosen = ref([]);
const mode = ref('now'); // now | schedule
const publishAt = ref('');
const note = ref('');
const submitting = ref(false);
const formError = ref('');
const cancelTarget = ref(null);
const cancelling = ref(false);
let seq = 0;

const isReady = (c) => READY.includes(c.status);

async function load({ keepChoice = false } = {}) {
  const mine = ++seq;
  loadState.value = 'loading';
  try {
    const res = await listMenuChanges();
    if (mine !== seq) return;
    changes.value = res.data ?? [];
    const readyIds = changes.value.filter(isReady).map((c) => c.id);
    chosen.value = keepChoice ? chosen.value.filter((id) => readyIds.includes(id)) : readyIds;
    loadState.value = 'ready';
  } catch {
    if (mine === seq) loadState.value = 'error';
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    mode.value = 'now';
    publishAt.value = '';
    note.value = '';
    formError.value = '';
    submitting.value = false;
    load();
  },
  { immediate: true }
);

function label(c) {
  if (c.kind === 'price') return [c.product_name, c.variant_name].filter(Boolean).join(' · ') || t('products.release_kind_price');
  return c.product_name || t(`products.release_kind_${c.kind}`);
}
function detail(c) {
  if (c.kind === 'price' && c.old_price !== null && c.new_price !== null) return `${formatIDR(c.old_price)} → ${formatIDR(c.new_price)}`;
  return t(`products.release_kind_${c.kind}`);
}
function toggle(id, checked) {
  chosen.value = checked ? [...chosen.value, id] : chosen.value.filter((x) => x !== id);
}

const submitLabel = computed(() => (mode.value === 'schedule' ? t('products.release_submit_schedule') : t('products.release_submit_now')));

async function submit() {
  if (submitting.value) return;
  formError.value = '';
  if (!chosen.value.length) {
    formError.value = t('products.release_none_selected');
    return;
  }
  let at = null;
  if (mode.value === 'schedule') {
    const d = publishAt.value ? new Date(publishAt.value) : null;
    if (!d || Number.isNaN(d.getTime())) {
      formError.value = t('products.release_publish_at_required');
      return;
    }
    at = d.toISOString();
  }
  submitting.value = true;
  try {
    const release = await createMenuRelease({ publish_at: at, note: note.value.trim() || null, change_ids: chosen.value });
    toast.success(t(at ? 'products.release_scheduled' : 'products.release_published', { version: release.version }));
    emit('released', release);
    emit('close');
  } catch (err) {
    if (err?.status === 409) {
      // changes_not_ready: status berubah sejak daftar dimuat (mis. persetujuan ditolak). Muat ulang kebenarannya.
      formError.value = err.errors?.change_ids?.[0] ?? err.message ?? t('products.release_not_ready');
      await load({ keepChoice: true });
    } else if (err?.isValidation) {
      formError.value = Object.values(err.errors ?? {})[0]?.[0] ?? err.message;
    } else if (err?.status !== 403) {
      formError.value = err?.message ?? t('page.load_failed');
    }
  } finally {
    submitting.value = false;
  }
}

async function confirmCancel() {
  const target = cancelTarget.value;
  if (!target || cancelling.value) return;
  cancelling.value = true;
  try {
    await cancelMenuChange(target.id);
    toast.success(t('products.release_change_cancelled'));
    cancelTarget.value = null;
    emit('changed');
    await load({ keepChoice: true });
  } catch {
    // 403/409 sudah di-toast handler pusat; daftar dimuat ulang karena statusnya mungkin sudah berubah.
    cancelTarget.value = null;
    await load({ keepChoice: true });
  } finally {
    cancelling.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('products.release_dialog_title')" max-width-class="max-w-[640px]" @close="emit('close')">
    <div class="flex flex-col gap-4 px-6 py-5">
      <p class="text-[13px] leading-relaxed text-muted-4">{{ t('products.release_dialog_intro') }}</p>

      <LoadingState v-if="loadState === 'loading'" :rows="3" />
      <ErrorState v-else-if="loadState === 'error'" @retry="load()" />
      <template v-else>
        <p v-if="!changes.length" class="py-4 text-center text-[13px] text-muted-3" data-testid="release-none">{{ t('products.release_none') }}</p>
        <fieldset v-else class="flex flex-col gap-0">
          <legend class="mb-1.5 flex w-full items-center justify-between text-[12.5px] font-semibold text-muted-4">
            <span>{{ t('products.release_changes') }}</span>
            <span>{{ t('products.release_selected', { count: chosen.length }) }}</span>
          </legend>
          <ul class="max-h-[300px] overflow-y-auto overscroll-contain border-2 border-line bg-white" data-testid="release-changes">
            <li v-for="c in changes" :key="c.id" class="flex items-center gap-3 border-b border-line-5 px-3 py-2.5 last:border-b-0" data-testid="release-change">
              <input
                :id="`release-change-${c.id}`"
                type="checkbox"
                class="h-4 w-4 flex-none accent-brand"
                :checked="chosen.includes(c.id)"
                :disabled="!isReady(c)"
                @change="toggle(c.id, $event.target.checked)"
              />
              <label :for="`release-change-${c.id}`" class="flex min-w-0 flex-1 flex-col" :class="isReady(c) ? 'cursor-pointer' : 'text-muted-3'">
                <span class="truncate text-[13px] font-semibold">{{ label(c) }}</span>
                <span class="truncate text-[12px] text-muted-4">{{ detail(c) }}<template v-if="c.requested_by"> · {{ t('products.release_requested_by', { name: c.requested_by }) }}</template></span>
              </label>
              <StatusPill :variant="STATUS_VARIANT[c.status] ?? 'neutral'">{{ t(`products.release_status_${c.status}`) }}</StatusPill>
              <button
                v-if="c.status !== 'released' && c.status !== 'cancelled'"
                type="button"
                class="flex-none text-[12px] font-bold text-muted underline decoration-dotted hover:text-danger-text"
                :aria-label="t('products.release_cancel_change_aria', { name: label(c) })"
                @click="cancelTarget = c"
              >
                {{ t('products.release_cancel_change') }}
              </button>
            </li>
          </ul>
        </fieldset>

        <fieldset v-if="changes.length" class="flex flex-col gap-2">
          <legend class="mb-1 text-[12.5px] font-semibold text-muted-4">{{ t('products.release_when') }}</legend>
          <div class="flex flex-wrap gap-5">
            <label class="flex cursor-pointer items-center gap-2 text-[13px] font-semibold">
              <input v-model="mode" type="radio" value="now" class="accent-brand" /> {{ t('products.release_when_now') }}
            </label>
            <label class="flex cursor-pointer items-center gap-2 text-[13px] font-semibold">
              <input v-model="mode" type="radio" value="schedule" class="accent-brand" /> {{ t('products.release_when_schedule') }}
            </label>
          </div>
          <label v-if="mode === 'schedule'" class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-muted-4">
            {{ t('products.release_publish_at') }}
            <input v-model="publishAt" type="datetime-local" class="h-[42px] border-2 border-line bg-white px-3 text-[13.5px] font-normal outline-none focus:border-brand" />
          </label>
        </fieldset>

        <label v-if="changes.length" class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-muted-4">
          {{ t('products.release_note') }}
          <textarea v-model="note" rows="2" maxlength="255" class="border-2 border-line bg-white px-3 py-2.5 text-[13.5px] font-normal outline-none focus:border-brand"></textarea>
        </label>

        <p v-if="formError" role="alert" class="border-2 border-danger-border bg-danger-bg px-3 py-2 text-[12.5px] font-semibold text-danger-text" data-testid="release-error">{{ formError }}</p>
      </template>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('page.cancel') }}</BaseButton>
        <BaseButton v-if="loadState === 'ready' && changes.length" :loading="submitting" @click="submit">{{ submitLabel }}</BaseButton>
      </div>
    </template>
  </BaseModal>

  <ConfirmDialog
    :open="!!cancelTarget"
    :title="t('products.release_cancel_change_title')"
    :message="t('products.release_cancel_change_message', { name: cancelTarget ? label(cancelTarget) : '' })"
    :confirm-label="t('products.release_cancel_change_confirm')"
    :loading="cancelling"
    @close="cancelTarget = null"
    @confirm="confirmCancel"
  />
</template>
