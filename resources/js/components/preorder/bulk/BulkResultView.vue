<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../../ui/BaseButton.vue';

/**
 * 065 — hasil aksi massal: angka yang SELALU berjumlah sama dengan pilihan (berhasil + dilewati + gagal), alasan per
 * pre-order yang tak berhasil, nomor yang bisa dibuka, dan "Ulangi yang ini". Teks alasan = teks service apa adanya
 * (sama dengan jalur individual); chip ringkasnya memakai kode alasan.
 */
const props = defineProps({
  result: { type: Object, required: true },
});
const emit = defineEmits(['retry', 'open']);
const { t } = useI18n();

const notDone = computed(() => props.result.results.filter((r) => r.status !== 'succeeded'));
const reasonChips = computed(() => Object.entries(props.result.skipped_by_reason || {}));
</script>

<template>
  <div class="flex flex-col gap-3" data-testid="bulk-result">
    <h3 class="text-[14px] font-bold">{{ t('preorder_bulk.result_title') }}</h3>
    <p class="text-[13.5px] text-muted-4" data-testid="bulk-result-head">
      {{ t('preorder_bulk.result_head', { succeeded: result.succeeded, selected: result.selected }) }}
    </p>

    <dl class="grid grid-cols-4 gap-2 text-center" data-testid="bulk-result-counts">
      <div class="rounded-lg border border-line-2 px-2 py-2">
        <dt class="text-[11px] font-bold uppercase tracking-wider text-muted-3">{{ t('preorder_bulk.count_selected') }}</dt>
        <dd class="text-[16px] font-bold" data-testid="count-selected">{{ result.selected }}</dd>
      </div>
      <div class="rounded-lg border border-mint-border bg-mint-50 px-2 py-2">
        <dt class="text-[11px] font-bold uppercase tracking-wider text-brand-active">{{ t('preorder_bulk.count_succeeded') }}</dt>
        <dd class="text-[16px] font-bold" data-testid="count-succeeded">{{ result.succeeded }}</dd>
      </div>
      <div class="rounded-lg border border-warn-border bg-warn-bg px-2 py-2">
        <dt class="text-[11px] font-bold uppercase tracking-wider text-warn-text">{{ t('preorder_bulk.count_skipped') }}</dt>
        <dd class="text-[16px] font-bold" data-testid="count-skipped">{{ result.skipped }}</dd>
      </div>
      <div class="rounded-lg border border-danger-border bg-danger-bg px-2 py-2">
        <dt class="text-[11px] font-bold uppercase tracking-wider text-danger-text">{{ t('preorder_bulk.count_failed') }}</dt>
        <dd class="text-[16px] font-bold" data-testid="count-failed">{{ result.failed }}</dd>
      </div>
    </dl>

    <ul v-if="reasonChips.length" class="flex flex-wrap gap-1.5" data-testid="bulk-reason-chips">
      <li v-for="[code, n] in reasonChips" :key="code" class="rounded-full bg-surface-subtle px-2.5 py-1 text-[12px] font-semibold text-muted-4">
        {{ t(`preorder_bulk.reason_${code}`) }} · {{ n }}
      </li>
    </ul>

    <div v-if="notDone.length" class="flex flex-col gap-1.5">
      <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">{{ t('preorder_bulk.not_done_list') }}</span>
      <ul class="max-h-56 overflow-auto rounded-lg border border-line-2 text-[12.5px]" data-testid="bulk-not-done-list">
        <li
          v-for="row in notDone"
          :key="row.preorder_id"
          class="flex items-start justify-between gap-3 border-t border-dashed border-line-2 px-3 py-2 first:border-t-0"
        >
          <button
            v-if="row.preorder_number"
            type="button"
            class="shrink-0 whitespace-nowrap font-semibold text-brand-active hover:underline"
            :aria-label="t('preorder_bulk.open_preorder', { number: row.preorder_number })"
            @click="emit('open', row.preorder_id)"
          >
            {{ row.preorder_number }}
          </button>
          <span v-else class="shrink-0 font-semibold">#{{ row.preorder_id }}</span>
          <span class="text-right text-muted-3">{{ row.message }}</span>
        </li>
      </ul>
      <BaseButton variant="secondary" size="sm" class="self-start" data-testid="bulk-retry" @click="emit('retry', notDone.map((r) => r.preorder_id))">
        {{ t('preorder_bulk.retry', { count: notDone.length }) }}
      </BaseButton>
    </div>
  </div>
</template>
