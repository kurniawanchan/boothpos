<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import EmptyState from '../ui/EmptyState.vue';
import { listPosDrafts, discardPosDraft } from '../../api/posDrafts';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { useToastStore } from '../../stores/toast';

/**
 * 006-purchase-order-and-ops (US4) — daftar draft milik kasir yang sedang
 * login (backend sudah melingkupi ke user_id, lihat PosDraftController).
 */
const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'resume']);

const { t } = useI18n();
const toast = useToastStore();
const drafts = ref([]);
const loading = ref(false);
const discarding = ref(null);

async function load() {
  loading.value = true;
  try {
    drafts.value = (await listPosDrafts()).data;
  } catch (err) {
    toast.error(err.message);
  } finally {
    loading.value = false;
  }
}

watch(() => props.open, (open) => { if (open) load(); });

function resume(draft) {
  emit('resume', draft.id);
}

async function discard(draft) {
  discarding.value = draft.id;
  try {
    await discardPosDraft(draft.id);
    drafts.value = drafts.value.filter((d) => d.id !== draft.id);
  } catch (err) {
    toast.error(err.message);
  } finally {
    discarding.value = null;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('pos.drafts_title')" max-width-class="max-w-[460px]" @close="emit('close')">
    <div class="flex flex-col gap-2 px-6 py-5">
      <EmptyState v-if="!loading && !drafts.length" icon="ph-note-pencil" :message="t('pos.no_drafts')" />
      <div v-for="d in drafts" :key="d.id" class="flex items-center justify-between gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3">
        <div class="flex flex-col gap-0.5">
          <span class="text-[13px] font-semibold">{{ d.label || t('pos.draft_unlabeled') }}</span>
          <span class="text-[11.5px] text-muted-3">{{ t('pos.item_count', { count: d.item_count }) }} · {{ formatIDR(d.total) }}</span>
          <span class="text-[11px] text-muted-3">{{ formatDateTime(d.created_at) }}</span>
        </div>
        <div class="flex items-center gap-2">
          <BaseButton size="sm" @click="resume(d)">{{ t('pos.resume_draft') }}</BaseButton>
          <button type="button" class="text-[12px] font-semibold text-danger-text hover:underline" :disabled="discarding === d.id" @click="discard(d)">
            {{ t('common.delete') }}
          </button>
        </div>
      </div>
    </div>
  </BaseModal>
</template>
