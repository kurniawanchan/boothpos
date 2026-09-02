<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useAuthStore } from '../../stores/auth';
import { useSettingsStore } from '../../stores/settings';
import { usePosCartStore } from '../../stores/posCart';

const { t } = useI18n();
const route = useRoute();
const auth = useAuthStore();
const settings = useSettingsStore();
const cart = usePosCartStore();
const { count: cartCount } = storeToRefs(cart);

const props = defineProps({ preorderAlertCount: { type: Number, default: 0 } });
defineEmits(['logout']);

// 'menuKey' matches app/Support/MenuKeys.php exactly — this is the one
// place the sidebar decides visibility, delegating to
// auth.canAccessMenu() (which mirrors User::canAccessMenu() server-side)
// instead of a hardcoded role list. See specs/001-user-store-settings.
//
// Pengaturan/Pengguna/Peran are grouped under one collapsible "Pengaturan"
// parent (routes/menu keys unchanged — 'settings'/'users'/'roles' are
// still three separate pages, this only changes how they're presented in
// the sidebar) — three same-purpose administrative screens as one flat
// top-level item each was cluttering the nav for owner/admin, the only
// roles that ever see more than one of them.
// `label` menyimpan KUNCI terjemahan (di bawah namespace `nav.*`), bukan
// lagi teks literal — dibaca lewat t(item.label) di template.
const NAV_DEFS = [
  { name: 'dashboard', label: 'nav.dashboard', icon: 'ph-house', menuKey: 'dashboard' },
  { name: 'pos', label: 'nav.pos', icon: 'ph-shopping-cart-simple', menuKey: 'pos' },
  { name: 'session', label: 'nav.session', icon: 'ph-cash-register', menuKey: 'session' },
  { name: 'events', label: 'nav.events', icon: 'ph-calendar-dots', menuKey: 'events' },
  { name: 'products', label: 'nav.products', icon: 'ph-package', menuKey: 'products' },
  { name: 'artists', label: 'nav.artists', icon: 'ph-users-three', menuKey: 'artists' },
  { name: 'categories', label: 'nav.categories', icon: 'ph-squares-four', menuKey: 'categories' },
  { name: 'stock', label: 'nav.stock', icon: 'ph-stack', menuKey: 'stock' },
  { name: 'vendors', label: 'nav.vendors', icon: 'ph-truck', menuKey: 'vendors' },
  { name: 'materials', label: 'nav.materials', icon: 'ph-flask', menuKey: 'materials' },
  { name: 'customers', label: 'nav.customers', icon: 'ph-address-book', menuKey: 'customers' },
  { name: 'preorders', label: 'nav.preorders', icon: 'ph-clock-countdown', menuKey: 'preorders' },
  { name: 'sales', label: 'nav.sales', icon: 'ph-receipt', menuKey: 'sales' },
  { name: 'reports', label: 'nav.reports', icon: 'ph-chart-bar', menuKey: 'reports' },
  {
    key: 'settings-group',
    label: 'nav.settings_group',
    icon: 'ph-gear-six',
    children: [
      { name: 'settings', label: 'nav.settings', menuKey: 'settings' },
      { name: 'users', label: 'nav.users', menuKey: 'users' },
      { name: 'roles', label: 'nav.roles', menuKey: 'roles' },
    ],
  },
];

// A group is visible only if at least one of its children is — same
// hide-entirely convention as every other role gate in this app, applied
// one level up.
const navItems = computed(() =>
  NAV_DEFS.map((item) => {
    if (item.children) {
      const children = item.children.filter((c) => auth.canAccessMenu(c.menuKey));
      return children.length ? { ...item, children } : null;
    }
    return auth.canAccessMenu(item.menuKey)
      ? { ...item, badge: item.name === 'pos' ? cartCount.value : item.name === 'preorders' ? props.preorderAlertCount : 0 }
      : null;
  }).filter(Boolean)
);

const expandedGroups = ref(new Set());
function isGroupExpanded(item) {
  return expandedGroups.value.has(item.key) || item.children.some((c) => c.name === route.name);
}
function toggleGroup(item) {
  const next = new Set(expandedGroups.value);
  if (next.has(item.key)) next.delete(item.key);
  else next.add(item.key);
  expandedGroups.value = next;
}
// Auto-expand a group the first time its own child becomes the active
// route (e.g. arriving at /users via a direct link or after saving a
// form) so the parent doesn't look collapsed while showing an active
// child underneath it.
watch(
  () => route.name,
  () => {
    for (const item of navItems.value) {
      if (item.children?.some((c) => c.name === route.name)) expandedGroups.value.add(item.key);
    }
  },
  { immediate: true }
);
</script>

<template>
  <nav :aria-label="t(`nav.aria_label`)" class="sticky top-0 flex h-screen w-[228px] flex-none flex-col border-r border-line-2 bg-white">
    <div class="flex items-center gap-2.5 px-[18px] pb-[18px] pt-5">
      <div class="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-brand text-[18px] text-white">
        <i class="ph-duotone ph-storefront" aria-hidden="true"></i>
      </div>
      <div class="flex flex-col leading-tight">
        <span class="text-[15.5px] font-extrabold tracking-tight">BoothPOS</span>
        <span class="text-[10.5px] font-semibold tracking-wide text-muted-3">{{ settings.tierLabel }}</span>
      </div>
    </div>

    <ul class="flex flex-1 flex-col gap-0.5 overflow-auto px-3">
      <li v-for="item in navItems" :key="item.key ?? item.name">
        <RouterLink
          v-if="!item.children"
          :to="{ name: item.name }"
          class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2.5 text-[13.5px] font-medium text-muted-4 transition-colors hover:bg-line-7"
          :class="route.name === item.name ? 'bg-mint-100 font-bold text-brand-active' : ''"
        >
          <i class="ph-duotone text-[17px]" :class="item.icon" aria-hidden="true"></i>
          <span class="flex-1 text-left">{{ t(item.label) }}</span>
          <span
            v-if="item.badge"
            class="rounded-full px-1.5 py-0.5 text-[10.5px] font-bold"
            :class="route.name === item.name ? 'bg-mint-200 text-brand-active' : 'bg-line-7 text-muted-2'"
          >
            {{ item.badge }}
          </span>
        </RouterLink>

        <template v-else>
          <button
            type="button"
            class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2.5 text-[13.5px] font-medium text-muted-4 transition-colors hover:bg-line-7"
            :class="item.children.some((c) => c.name === route.name) ? 'text-brand-active' : ''"
            :aria-expanded="isGroupExpanded(item)"
            @click="toggleGroup(item)"
          >
            <i class="ph-duotone text-[17px]" :class="item.icon" aria-hidden="true"></i>
            <span class="flex-1 text-left font-bold">{{ t(item.label) }}</span>
            <i
              class="ph-duotone ph-caret-down text-[13px] transition-transform"
              :class="{ 'rotate-180': isGroupExpanded(item) }"
              aria-hidden="true"
            ></i>
          </button>
          <ul v-if="isGroupExpanded(item)" class="mt-0.5 flex flex-col gap-0.5 border-l border-line-3 pl-3.5">
            <li v-for="child in item.children" :key="child.name">
              <RouterLink
                :to="{ name: child.name }"
                class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-[13px] font-medium text-muted-4 transition-colors hover:bg-line-7"
                :class="route.name === child.name ? 'bg-mint-100 font-bold text-brand-active' : ''"
              >
                <span class="flex-1 text-left">{{ t(child.label) }}</span>
              </RouterLink>
            </li>
          </ul>
        </template>
      </li>
    </ul>

    <div class="flex flex-col gap-2.5 border-t border-line-3 p-3">
      <div class="flex items-center gap-2.5 px-1 py-1.5">
        <div class="flex h-[30px] w-[30px] flex-none items-center justify-center rounded-full bg-mint-100 text-[12px] font-bold text-brand-active">
          {{ (auth.user?.name || '?').slice(0, 2).toUpperCase() }}
        </div>
        <div class="flex min-w-0 flex-col">
          <span class="truncate text-[12.5px] font-semibold">{{ auth.user?.name }} · {{ auth.user?.username }}</span>
          <span class="text-[11px] capitalize text-muted-3">{{ auth.user?.role }}</span>
        </div>
      </div>
      <button
        type="button"
        class="flex items-center justify-center gap-2 rounded-md border border-line px-3 py-2 text-[12.5px] font-bold text-muted-4 transition-colors hover:border-danger-border-hover hover:bg-danger-bg hover:text-danger-text"
        @click="$emit('logout')"
      >
        <i class="ph-duotone ph-sign-out text-[15px]" aria-hidden="true"></i>
        Keluar
      </button>
    </div>
  </nav>
</template>
