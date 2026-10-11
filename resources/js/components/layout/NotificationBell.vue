<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * 041-back-office-ui-redesign (US1) — lonceng notifikasi. `items` adalah daftar urusan yang menunggu
 * tindakan PENGGUNA INI: [{ id, label, count, to }] (dari GET /attention di US4, dihitung di server dan
 * disaring dengan izin & outlet). `null` berarti sumber datanya belum ada, dan lonceng TIDAK dirender
 * (tidak ada kendali tak berfungsi). Daftar kosong ([]) menampilkan lonceng dengan "tidak ada yang perlu
 * ditindaklanjuti". Tidak ada state baca/belum baca: item adalah turunan dari data, bukan pesan tersimpan.
 */
const props = defineProps({
  items: { type: Array, default: null },
});
const { t } = useI18n();

const open = ref(false);
const rootRef = ref(null);
const total = computed(() => (props.items ?? []).reduce((sum, i) => sum + (Number(i.count) || 0), 0));

function onDocPointer(e) {
  if (open.value && rootRef.value && !rootRef.value.contains(e.target)) open.value = false;
}
function onKey(e) {
  if (e.key === 'Escape') open.value = false;
}
onMounted(() => {
  document.addEventListener('pointerdown', onDocPointer);
  document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocPointer);
  document.removeEventListener('keydown', onKey);
});
</script>

<template>
  <div v-if="items !== null" ref="rootRef" class="relative">
    <button
      type="button"
      class="relative flex h-[38px] w-[38px] items-center justify-center border-2 border-line bg-white text-muted-4 transition-colors hover:border-brand hover:text-brand-active focus-visible:outline-none focus-visible:border-brand"
      :aria-label="total ? `${t('frame.notifications')}: ${t('frame.notifications_count', { count: total })}` : t('frame.notifications')"
      aria-haspopup="true"
      :aria-expanded="open"
      @click="open = !open"
    >
      <i class="ph-duotone ph-bell text-[18px]" aria-hidden="true"></i>
      <span v-if="total" class="absolute -right-1.5 -top-1.5 min-w-[18px] bg-marker-danger px-1 text-center text-[10.5px] font-bold leading-[18px] text-white" aria-hidden="true">{{ total > 99 ? '99+' : total }}</span>
    </button>

    <div v-if="open" class="absolute right-0 top-full z-[90] mt-1 w-[320px] border-2 border-line bg-white shadow-drawer">
      <p class="border-b-2 border-line-3 px-4 py-2.5 text-[12px] font-bold uppercase tracking-wide text-muted">{{ t('frame.notifications') }}</p>
      <p v-if="!items.length" class="px-4 py-5 text-[12.5px] text-muted">{{ t('frame.notifications_empty') }}</p>
      <ul v-else>
        <li v-for="item in items" :key="item.id">
          <RouterLink :to="item.to" class="flex items-center justify-between gap-3 px-4 py-2.5 text-[13px] font-semibold hover:bg-mint-50 focus-visible:bg-mint-50 focus-visible:outline-none" @click="open = false">
            <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
            <span class="bg-warn-bg px-1.5 py-0.5 text-[11px] font-bold text-warn-text">{{ item.count }}</span>
          </RouterLink>
        </li>
      </ul>
    </div>
  </div>
</template>
