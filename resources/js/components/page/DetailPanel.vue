<script setup>
import BaseDrawer from '../ui/BaseDrawer.vue';
import StatusPill from '../ui/StatusPill.vue';

/**
 * 041-back-office-ui-redesign (US2) — panel detail di sisi kanan, dibangun di atas BaseDrawer (fokus terkurung,
 * Escape menutup, sudah teruji) — BUKAN panel berdampingan permanen; lihat research.md Keputusan 18.
 * Susunan: kicker (jenis + kode), judul, chip status, tindakan yang boleh untuk pemanggil pada status rekaman
 * ini, grid fakta kunci, lalu bagian bebas (tabel, catatan, lini masa) lewat slot default.
 *
 * `actions` HARUS sudah disaring pemanggil menurut izin dan status — tindakan yang tak boleh dipakai tidak
 * dirender (Konstitusi III); izin sebenarnya tetap dijaga server.
 *
 * chips: [{ label, variant }]   actions: [{ key, label, variant?: 'primary'|'secondary'|'danger', disabled?, title? }]
 * facts: [{ k, v }]
 */
defineProps({
  open: { type: Boolean, default: false },
  kicker: { type: String, default: '' },
  title: { type: String, required: true },
  chips: { type: Array, default: () => [] },
  actions: { type: Array, default: () => [] },
  facts: { type: Array, default: () => [] },
  maxWidthClass: { type: String, default: 'max-w-[560px]' },
});
defineEmits(['close', 'action']);

const BTN = {
  primary: 'border-transparent bg-brand text-white hover:bg-brand-hover',
  secondary: 'border-line bg-white text-muted-5 hover:border-brand hover:text-brand-active',
  danger: 'border-line bg-white text-danger-text hover:border-danger-border-hover hover:bg-danger-bg',
};
</script>

<template>
  <BaseDrawer :open="open" :title="title" :max-width-class="maxWidthClass" @close="$emit('close')">
    <template #header>
      <div class="flex min-w-0 flex-col gap-1.5">
        <p v-if="kicker" class="text-[11px] font-bold uppercase tracking-wide text-muted">{{ kicker }}</p>
        <h2 class="text-[19px] font-bold tracking-tight">{{ title }}</h2>
        <div v-if="chips.length" class="flex flex-wrap gap-1.5">
          <StatusPill v-for="(c, i) in chips" :key="i" :variant="c.variant ?? 'neutral'">{{ c.label }}</StatusPill>
        </div>
      </div>
    </template>

    <div v-if="actions.length" class="mb-5 flex flex-wrap gap-2" data-testid="panel-actions">
      <button
        v-for="a in actions"
        :key="a.key"
        type="button"
        :disabled="a.disabled"
        :title="a.title"
        class="border-2 px-3.5 py-2 text-[12.5px] font-bold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
        :class="BTN[a.variant ?? 'secondary']"
        @click="$emit('action', a.key)"
      >
        {{ a.label }}
      </button>
    </div>

    <dl v-if="facts.length" class="mb-5 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
      <div v-for="(f, i) in facts" :key="i" class="flex flex-col gap-0.5">
        <dt class="text-[11px] font-bold uppercase tracking-wide text-muted">{{ f.k }}</dt>
        <dd class="text-[13.5px] font-semibold">{{ f.v }}</dd>
      </div>
    </dl>

    <slot />
  </BaseDrawer>
</template>
