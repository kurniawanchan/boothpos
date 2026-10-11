<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../../stores/auth';
import LanguageSwitcher from './LanguageSwitcher.vue';

/**
 * 041-back-office-ui-redesign (US1) — menu pengguna: nama + peran di topbar; klik membuka profil, bahasa,
 * dan keluar. Menyatukan yang dulu terpisah di topbar (tautan profil, LanguageSwitcher, tombol Logout).
 * Menutup lewat Escape, klik di luar, atau memilih salah satu butir; fokus kembali ke pemicu.
 */
const emit = defineEmits(['logout']);
const { t } = useI18n();
const auth = useAuthStore();

const open = ref(false);
const rootRef = ref(null);
const triggerRef = ref(null);

function close(returnFocus = false) {
  open.value = false;
  if (returnFocus) triggerRef.value?.focus();
}
function onDocPointer(e) {
  if (open.value && rootRef.value && !rootRef.value.contains(e.target)) close();
}
function onKey(e) {
  if (e.key === 'Escape' && open.value) close(true);
}
onMounted(() => {
  document.addEventListener('pointerdown', onDocPointer);
  document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocPointer);
  document.removeEventListener('keydown', onKey);
});

const initials = () => (auth.user?.name || '?').slice(0, 2).toUpperCase();
</script>

<template>
  <div ref="rootRef" class="relative">
    <button
      ref="triggerRef"
      type="button"
      class="flex items-center gap-2.5 border-2 border-transparent px-2 py-1 text-left transition-colors hover:border-line focus-visible:outline-none focus-visible:border-brand"
      :aria-label="t('frame.user_menu')"
      aria-haspopup="menu"
      :aria-expanded="open"
      @click="open = !open"
    >
      <img v-if="auth.user?.photo_url" :src="auth.user.photo_url" :alt="auth.user?.name" class="h-8 w-8 flex-none object-cover" />
      <span v-else class="flex h-8 w-8 flex-none items-center justify-center bg-mint-100 text-[12px] font-bold text-brand-active" aria-hidden="true">{{ initials() }}</span>
      <span class="hidden min-w-0 flex-col leading-tight md:flex">
        <span class="max-w-[160px] truncate text-[13px] font-bold">{{ auth.user?.name }}</span>
        <span class="text-[11px] capitalize text-muted">{{ auth.user?.role }}</span>
      </span>
      <i class="ph-duotone ph-caret-down hidden text-[12px] text-muted md:inline" aria-hidden="true"></i>
    </button>

    <div v-if="open" role="menu" class="absolute right-0 top-full z-[90] mt-1 flex w-[240px] flex-col border-2 border-line bg-white py-1 shadow-drawer">
      <div class="border-b-2 border-line-3 px-4 py-2.5">
        <p class="truncate text-[13px] font-bold">{{ auth.user?.name }}</p>
        <p class="truncate text-[11.5px] text-muted">{{ auth.user?.username }} · {{ auth.user?.role }}</p>
      </div>
      <RouterLink
        role="menuitem"
        :to="{ name: 'profile' }"
        class="flex items-center gap-2.5 px-4 py-2.5 text-[13px] font-semibold text-muted-5 hover:bg-surface hover:text-brand-active"
        @click="close()"
      >
        <i class="ph-duotone ph-user-circle text-[17px]" aria-hidden="true"></i>
        {{ t('frame.profile') }}
      </RouterLink>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <span class="text-[13px] font-semibold text-muted-5">{{ t('frame.language') }}</span>
        <LanguageSwitcher />
      </div>
      <button
        type="button"
        role="menuitem"
        class="flex items-center gap-2.5 border-t-2 border-line-3 px-4 py-2.5 text-left text-[13px] font-semibold text-danger-text hover:bg-danger-bg"
        @click="
          close();
          emit('logout');
        "
      >
        <i class="ph-duotone ph-sign-out text-[17px]" aria-hidden="true"></i>
        {{ t('nav.logout') }}
      </button>
    </div>
  </div>
</template>
