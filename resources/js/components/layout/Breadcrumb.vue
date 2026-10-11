<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';

/**
 * 041-back-office-ui-redesign (US1) — "Beranda / <halaman>". Judul halaman diambil dari route.meta.titleKey
 * dan, bila tak ada, dari `nav.<nama rute>` (konvensi yang sama dengan judul halaman sebelumnya), jadi
 * halaman yang sudah punya judul tak perlu mendaftarkan apa pun.
 *
 * `trail` opsional untuk halaman yang lebih dalam (mis. Meja → Tab): daftar {label, to?}; butir terakhir
 * adalah halaman saat ini dan ditandai aria-current.
 */
const props = defineProps({
  trail: { type: Array, default: null },
});

const { t } = useI18n();
const route = useRoute();

const crumbs = computed(() => {
  if (props.trail?.length) return props.trail;
  const title = t(route.meta.titleKey ?? `nav.${String(route.name)}`);
  return [{ label: title }];
});
</script>

<template>
  <nav :aria-label="t('frame.breadcrumb_aria')" class="min-w-0">
    <ol class="flex min-w-0 items-center gap-2 text-[13px]">
      <li class="flex-none">
        <RouterLink :to="{ name: 'dashboard' }" class="font-medium text-muted hover:text-brand-active">{{ t('frame.home') }}</RouterLink>
      </li>
      <li v-for="(crumb, i) in crumbs" :key="i" class="flex min-w-0 items-center gap-2">
        <span class="text-muted-3" aria-hidden="true">/</span>
        <RouterLink v-if="crumb.to && i < crumbs.length - 1" :to="crumb.to" class="truncate font-medium text-muted hover:text-brand-active">{{ crumb.label }}</RouterLink>
        <span v-else class="truncate font-bold text-ink" :aria-current="i === crumbs.length - 1 ? 'page' : undefined">{{ crumb.label }}</span>
      </li>
    </ol>
  </nav>
</template>
