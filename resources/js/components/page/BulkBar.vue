<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * 041-back-office-ui-redesign (US2) — bilah tindakan massal. Muncul hanya bila ada baris terpilih. `actions`
 * harus sudah disaring pemanggil menjadi tindakan yang valid untuk SEMUA baris terpilih (spec US2: "only actions
 * valid for all selected rows are offered"); komponen tidak menebak validitas. Setelah tindakan dijalankan,
 * `result` ({ succeeded, failed: [{ label, message }] }) menampilkan hasil PER BARIS — satu baris gagal tak
 * boleh menyembunyikan baris lain yang berhasil.
 *
 * actions: [{ key, label, danger?, disabled? }]
 */
const props = defineProps({
  count: { type: Number, default: 0 },
  actions: { type: Array, default: () => [] },
  result: { type: Object, default: null },
  busy: { type: Boolean, default: false },
});
defineEmits(['action', 'clear']);
const { t } = useI18n();

const failed = computed(() => props.result?.failed ?? []);
</script>

<template>
  <div v-if="count > 0 || result" class="mx-5 mb-3 border-2 border-brand bg-mint-50" role="toolbar" :aria-label="t('page.bulk_actions')">
    <div class="flex flex-wrap items-center gap-3 px-4 py-2.5">
      <span v-if="count > 0" class="text-[13px] font-bold">{{ t('page.selected_count', { count }) }}</span>
      <button
        v-for="a in actions"
        :key="a.key"
        type="button"
        :disabled="busy || a.disabled"
        class="border-2 bg-white px-3 py-1.5 text-[12.5px] font-bold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
        :class="a.danger ? 'border-line text-danger-text hover:border-danger-border-hover hover:bg-danger-bg' : 'border-line text-muted-5 hover:border-brand hover:text-brand-active'"
        @click="$emit('action', a.key)"
      >
        {{ a.label }}
      </button>
      <button v-if="count > 0" type="button" class="ml-auto text-[12.5px] font-bold text-muted underline decoration-dotted hover:text-ink" @click="$emit('clear')">{{ t('page.clear_selection') }}</button>
    </div>
    <div v-if="result" class="border-t-2 border-line px-4 py-2.5 text-[12.5px]" role="status">
      <p class="font-bold">{{ result.succeeded }} ✓<span v-if="failed.length"> · {{ failed.length }} ✗</span></p>
      <ul v-if="failed.length" class="mt-1 list-disc pl-5 text-danger-text">
        <li v-for="(f, i) in failed" :key="i"><strong>{{ f.label }}</strong> — {{ f.message }}</li>
      </ul>
    </div>
  </div>
</template>
