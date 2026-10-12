<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useAuthStore } from '../../stores/auth';
import { useSettingsStore } from '../../stores/settings';
import { usePosCartStore } from '../../stores/posCart';
import { isActive, visibleSections } from '../../utils/navigation';

/**
 * 041-back-office-ui-redesign (US1) — rel navigasi gelap. Menggantikan AppSidebar.vue.
 *
 *  - LEBAR 240 px, CIUT 64 px (nilai dari halaman desain). Saat ciut hanya ikon: nama modul muncul saat
 *    hover/fokus (tooltip), dan grup membuka daftar anak (flyout) lewat klik/Enter. Nama selalu ada sebagai
 *    aria-label, jadi pembaca layar tidak kehilangan apa pun.
 *  - VISIBILITAS tetap lewat auth.canAccessMenu() (cermin server); modul yang tak boleh dipakai TIDAK
 *    dirender sama sekali (disembunyikan, bukan dinonaktifkan — Konstitusi III). Definisi di utils/navigation.js.
 *  - TITIK STATUS (`dots`) menandai modul yang ada urusan menunggu; datanya dari /attention (US4), prop kosong
 *    sampai itu ada — komponen tidak menciptakan data sendiri.
 *  - Keadaan ciut/lebar dipegang pemanggil (AppShell + useRailState); komponen ini murni memancarkan `toggle`.
 */
const props = defineProps({
  collapsed: { type: Boolean, default: false },
  dots: { type: Object, default: () => ({}) },
});
defineEmits(['toggle']);

const { t } = useI18n();
const route = useRoute();
const auth = useAuthStore();
const settings = useSettingsStore();
const { count: cartCount } = storeToRefs(usePosCartStore());

const sections = computed(() =>
  visibleSections(
    (key) => auth.canAccessMenu(key),
    { cart: cartCount.value },
    props.dots
  )
);

// --- grup: lebar = buka/tutup di tempat; ciut = flyout --------------------------------------------
const expanded = ref(new Set());
const flyout = ref(null); // kunci grup yang flyout-nya terbuka (hanya saat ciut)

function groupOpen(item) {
  return expanded.value.has(item.key) || isActive(item, route.name);
}
function toggleGroup(item) {
  if (props.collapsed) {
    flyout.value = flyout.value === item.key ? null : item.key;
    return;
  }
  const next = new Set(expanded.value);
  if (next.has(item.key)) next.delete(item.key);
  else next.add(item.key);
  expanded.value = next;
}

// Sebuah grup ikut terbuka saat salah satu anaknya menjadi rute aktif (tautan langsung, selesai simpan, dst).
watch(
  () => route.name,
  () => {
    flyout.value = null;
    for (const section of sections.value) {
      for (const item of section.items) {
        if (item.children && isActive(item, route.name)) expanded.value.add(item.key);
      }
    }
  },
  { immediate: true }
);
// Mengubah mode ciut/lebar menutup flyout yang tersisa.
watch(
  () => props.collapsed,
  () => {
    flyout.value = null;
  }
);

const navRef = ref(null);
function onDocPointer(e) {
  if (flyout.value && navRef.value && !navRef.value.contains(e.target)) flyout.value = null;
}
function onKey(e) {
  if (e.key === 'Escape' && flyout.value) flyout.value = null;
}
onMounted(() => {
  document.addEventListener('pointerdown', onDocPointer);
  document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocPointer);
  document.removeEventListener('keydown', onKey);
});

const itemBase =
  'group relative flex items-center gap-3 px-[21px] py-2.5 text-[13px] font-semibold text-rail-text transition-colors hover:bg-rail-active hover:text-white focus-visible:outline-none focus-visible:bg-rail-active focus-visible:text-white';
const itemActive = 'bg-rail-active text-white';
const tooltip =
  'pointer-events-none absolute left-full top-1/2 z-[90] ml-2 -translate-y-1/2 whitespace-nowrap bg-ink px-2.5 py-1.5 text-[12px] font-semibold text-white opacity-0 shadow-drawer group-hover:opacity-100 group-focus-visible:opacity-100';
</script>

<template>
  <nav
    ref="navRef"
    :aria-label="t('frame.rail_aria')"
    :data-collapsed="collapsed"
    class="sticky top-0 flex h-screen flex-none flex-col bg-rail transition-[width] duration-200 ease-in-out motion-reduce:transition-none"
    :class="collapsed ? 'w-[64px]' : 'w-[240px]'"
  >
    <!-- Merek: kotak aksen + (saat lebar) nama aplikasi dan tingkat lisensi. -->
    <div class="flex h-[56px] flex-none items-center gap-3 px-[18px]">
      <div class="flex h-7 w-7 flex-none items-center justify-center bg-brand text-[14px] font-extrabold text-white" aria-hidden="true">
        {{ (settings.appName || 'B').slice(0, 1).toUpperCase() }}
      </div>
      <div v-if="!collapsed" class="flex min-w-0 flex-1 flex-col leading-tight">
        <span class="truncate text-[14px] font-extrabold text-white">{{ settings.appName }}</span>
        <span class="truncate text-[10.5px] font-semibold tracking-wide text-dark-muted">{{ settings.tierLabel }}</span>
      </div>
    </div>

    <div class="flex min-h-0 flex-1 flex-col" :class="collapsed ? 'overflow-visible' : 'overflow-y-auto'">
      <ul v-for="(section, si) in sections" :key="section.key" class="flex flex-col" :class="si > 0 ? 'mt-1 border-t-2 border-rail-active pt-1' : ''" :aria-label="t(section.label)">
        <li v-for="item in section.items" :key="item.key ?? item.name" class="relative">
          <!-- Item tunggal -->
          <RouterLink
            v-if="!item.children"
            :to="{ name: item.name }"
            :aria-label="collapsed ? t(item.label) : undefined"
            :class="[itemBase, route.name === item.name ? itemActive : '']"
          >
            <span v-if="route.name === item.name" class="absolute inset-y-0 left-0 w-[3px] bg-rail-indicator" aria-hidden="true"></span>
            <span class="relative flex h-[22px] w-[22px] flex-none items-center justify-center">
              <i class="ph-duotone text-[19px]" :class="item.icon" aria-hidden="true"></i>
              <span v-if="item.dot" class="absolute -right-0.5 -top-0.5 h-2 w-2 bg-marker-danger" aria-hidden="true"></span>
              <span v-if="item.dot" class="sr-only">{{ t('frame.status_dot') }}</span>
            </span>
            <span v-if="!collapsed" class="min-w-0 flex-1 truncate">{{ t(item.label) }}</span>
            <span
              v-if="!collapsed && item.badge"
              class="bg-brand px-1.5 py-0.5 text-[10.5px] font-bold text-white"
              >{{ item.badge }}</span
            >
            <span v-if="collapsed" :class="tooltip" aria-hidden="true">{{ t(item.label) }}{{ item.badge ? ` (${item.badge})` : '' }}</span>
          </RouterLink>

          <!-- Grup -->
          <template v-else>
            <button
              type="button"
              :aria-label="collapsed ? t(item.label) : undefined"
              :aria-expanded="collapsed ? flyout === item.key : groupOpen(item)"
              aria-haspopup="true"
              class="w-full text-left"
              :class="[itemBase, isActive(item, route.name) ? itemActive : '']"
              @click="toggleGroup(item)"
            >
              <span v-if="isActive(item, route.name)" class="absolute inset-y-0 left-0 w-[3px] bg-rail-indicator" aria-hidden="true"></span>
              <span class="relative flex h-[22px] w-[22px] flex-none items-center justify-center">
                <i class="ph-duotone text-[19px]" :class="item.icon" aria-hidden="true"></i>
                <span v-if="item.dot" class="absolute -right-0.5 -top-0.5 h-2 w-2 bg-marker-danger" aria-hidden="true"></span>
                <span v-if="item.dot" class="sr-only">{{ t('frame.status_dot') }}</span>
              </span>
              <span v-if="!collapsed" class="min-w-0 flex-1 truncate">{{ t(item.label) }}</span>
              <i v-if="!collapsed" class="ph-duotone ph-caret-down text-[12px] transition-transform" :class="{ 'rotate-180': groupOpen(item) }" aria-hidden="true"></i>
              <span v-if="collapsed && flyout !== item.key" :class="tooltip" aria-hidden="true">{{ t(item.label) }}</span>
            </button>

            <!-- Lebar: anak di bawah induk -->
            <ul v-if="!collapsed && groupOpen(item)" class="flex flex-col pb-1">
              <li v-for="child in item.children" :key="child.name">
                <RouterLink
                  :to="{ name: child.name }"
                  class="block py-2 pl-[56px] pr-4 text-[12.5px] font-semibold text-rail-text transition-colors hover:bg-rail-active hover:text-white focus-visible:outline-none focus-visible:bg-rail-active focus-visible:text-white"
                  :class="route.name === child.name ? 'bg-rail-active text-white' : ''"
                  >{{ t(child.label) }}</RouterLink
                >
              </li>
            </ul>

            <!-- Ciut: flyout di sisi kanan -->
            <ul
              v-if="collapsed && flyout === item.key"
              role="menu"
              class="absolute left-full top-0 z-[90] ml-1 flex min-w-[200px] flex-col border-2 border-rail-active bg-rail py-1 shadow-drawer"
            >
              <li class="px-4 py-2 text-[11px] font-bold uppercase tracking-wide text-dark-muted" aria-hidden="true">{{ t(item.label) }}</li>
              <li v-for="child in item.children" :key="child.name" role="none">
                <RouterLink
                  role="menuitem"
                  :to="{ name: child.name }"
                  class="block px-4 py-2 text-[12.5px] font-semibold text-rail-text transition-colors hover:bg-rail-active hover:text-white focus-visible:outline-none focus-visible:bg-rail-active focus-visible:text-white"
                  :class="route.name === child.name ? 'bg-rail-active text-white' : ''"
                  >{{ t(child.label) }}</RouterLink
                >
              </li>
            </ul>
          </template>
        </li>
      </ul>
    </div>

    <!-- Kendali ciut/lebar di dasar rel -->
    <button
      type="button"
      class="flex h-[48px] flex-none items-center gap-3 border-t-2 border-rail-active px-[21px] text-[12.5px] font-semibold text-rail-text transition-colors hover:bg-rail-active hover:text-white focus-visible:outline-none focus-visible:bg-rail-active focus-visible:text-white"
      :aria-label="collapsed ? t('frame.rail_expand') : t('frame.rail_collapse')"
      :aria-expanded="!collapsed"
      @click="$emit('toggle')"
    >
      <i class="ph-duotone text-[19px]" :class="collapsed ? 'ph-caret-double-right' : 'ph-caret-double-left'" aria-hidden="true"></i>
      <span v-if="!collapsed">{{ t('frame.rail_collapse') }}</span>
    </button>
  </nav>
</template>
