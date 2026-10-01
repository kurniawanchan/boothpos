<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 027-preorder-duplicate-split (US2) — ringkasan duplikat banyak pre-order.
 *
 * Server membalas 200 dengan laporan per-pre-order (satu yang gagal tidak
 * menggagalkan yang lain), jadi dialog ini memisahkan yang berhasil (nomor
 * sumber → nomor baru, bisa dibuka) dari yang gagal beserta alasannya.
 * Komponen ini hanya menampilkan; membuka pre-order hasil tetap tanggung
 * jawab pemanggil lewat event `open`.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  // [{ source_id, source_number, status: 'created'|'failed', preorder?, error? }]
  results: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'open']);

const { t } = useI18n();

const created = computed(() => props.results.filter((r) => r.status === 'created'));
const failed = computed(() => props.results.filter((r) => r.status !== 'created'));
</script>

<template>
  <BaseModal :open="open" :title="t('preorders.duplicate_result_title')" max-width-class="max-w-[560px]" @close="emit('close')">
    <div class="flex flex-col gap-5 px-6 py-5">
      <section v-if="created.length" data-testid="duplicate-created" class="flex flex-col gap-2">
        <h3 class="text-[13px] font-bold text-brand-active">{{ t('preorders.duplicate_result_created', { count: created.length }) }}</h3>
        <ul class="flex flex-col divide-y divide-line-6 rounded-lg border border-line-2">
          <li v-for="r in created" :key="r.source_id" class="flex items-center justify-between gap-3 px-3 py-2.5 text-[12.5px]">
            <span class="font-mono text-muted-4">{{ r.source_number }}</span>
            <i class="ph-bold ph-arrow-right text-muted-3" aria-hidden="true"></i>
            <button
              type="button"
              class="font-mono font-semibold text-brand-active underline"
              @click="emit('open', r.preorder.id)"
            >{{ r.preorder.preorder_number }}</button>
          </li>
        </ul>
      </section>

      <section v-if="failed.length" data-testid="duplicate-failed" class="flex flex-col gap-2">
        <h3 class="text-[13px] font-bold text-danger-text">{{ t('preorders.duplicate_result_failed', { count: failed.length }) }}</h3>
        <ul class="flex flex-col gap-2">
          <li v-for="r in failed" :key="r.source_id" role="alert" class="rounded-lg bg-danger-bg px-3 py-2 text-[12.5px] text-danger-text">
            <span class="font-mono font-semibold">{{ r.source_number || `#${r.source_id}` }}</span>
            — {{ r.error }}
          </li>
        </ul>
      </section>
    </div>
    <template #footer>
      <div class="flex justify-end">
        <BaseButton data-autofocus @click="emit('close')">{{ t('common.close') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
