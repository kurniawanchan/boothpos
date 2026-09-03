<script setup>
import { computed } from 'vue';
import { useAuthStore } from '../../stores/auth';

/**
 * Toggle dua-opsi sederhana, bukan BaseSelect — dua pilihan tetap
 * (id/en) tidak butuh dropdown penuh, dan ini dipasang di topbar yang
 * ruangnya sempit. Dipasang langsung di AppTopbar.vue (bukan lewat slot
 * per-view) supaya benar-benar tersedia dari layar mana pun setelah
 * login (FR-003/FR-004), bukan opt-in per halaman.
 */
const auth = useAuthStore();
const current = computed(() => auth.user?.language ?? 'en');

function setLanguage(lang) {
  if (lang === current.value) return;
  auth.setLanguage(lang);
}
</script>

<template>
  <div class="flex items-center gap-0.5 rounded-lg border border-line bg-white p-0.5 text-[12.5px] font-semibold">
    <button
      type="button"
      class="rounded-md px-2.5 py-1 transition-colors"
      :class="current === 'id' ? 'bg-mint-100 text-ink' : 'text-muted-3 hover:text-ink'"
      :aria-pressed="current === 'id'"
      @click="setLanguage('id')"
    >
      ID
    </button>
    <button
      type="button"
      class="rounded-md px-2.5 py-1 transition-colors"
      :class="current === 'en' ? 'bg-mint-100 text-ink' : 'text-muted-3 hover:text-ink'"
      :aria-pressed="current === 'en'"
      @click="setLanguage('en')"
    >
      EN
    </button>
  </div>
</template>
