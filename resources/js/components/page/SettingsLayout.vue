<script setup>
import { useI18n } from 'vue-i18n';

/**
 * 041-back-office-ui-redesign (US2) — tata letak Pengaturan/Konfigurasi POS: daftar bagian berkelompok di kiri
 * (judul kelompok kapital, butir terpilih bertanda aksen, lencana jumlah masalah) dan isi bagian terpilih di kanan.
 * Pemanggil memegang bagian mana yang aktif (modelValue) — biasanya lewat useQueryState supaya bisa ditautkan.
 *
 * groups: [{ key, label, items: [{ key, label, badge?, badgeTone?: 'warning'|'danger' }] }]
 */
defineProps({
  groups: { type: Array, required: true },
  modelValue: { type: String, required: true },
  label: { type: String, default: '' },
});
defineEmits(['update:modelValue']);
const { t } = useI18n();
const TONES = { warning: 'bg-warn-bg text-warn-text', danger: 'bg-danger-bg text-danger-text' };
</script>

<template>
  <div class="grid grid-cols-1 items-start gap-5 px-[26px] pb-8 lg:grid-cols-[250px_minmax(0,1fr)]">
    <nav :aria-label="label || t('nav.settings')" class="border-2 border-line bg-white">
      <section v-for="g in groups" :key="g.key" :aria-label="g.label" class="border-b-2 border-line-3 py-2 last:border-b-0">
        <h3 class="px-4 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wider text-muted">{{ g.label }}</h3>
        <ul>
          <li v-for="item in g.items" :key="item.key">
            <button
              type="button"
              class="relative flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left text-[13px] font-semibold transition-colors hover:bg-surface focus-visible:bg-surface focus-visible:outline-none"
              :class="modelValue === item.key ? 'bg-mint-50 text-brand-active' : 'text-muted-5'"
              :aria-current="modelValue === item.key ? 'page' : undefined"
              @click="$emit('update:modelValue', item.key)"
            >
              <span v-if="modelValue === item.key" class="absolute inset-y-0 left-0 w-[3px] bg-brand" aria-hidden="true"></span>
              {{ item.label }}
              <span v-if="item.badge" class="px-1.5 py-px text-[11px] font-bold tabular-nums" :class="TONES[item.badgeTone] ?? TONES.danger">{{ item.badge }}</span>
            </button>
          </li>
        </ul>
      </section>
    </nav>
    <section class="min-w-0 border-2 border-line bg-white"><slot /></section>
  </div>
</template>
