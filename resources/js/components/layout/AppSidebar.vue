<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useAuthStore } from '../../stores/auth';
import { useSettingsStore } from '../../stores/settings';
import { usePosCartStore } from '../../stores/posCart';

const route = useRoute();
const auth = useAuthStore();
const settings = useSettingsStore();
const cart = usePosCartStore();
const { count: cartCount } = storeToRefs(cart);

const props = defineProps({ preorderAlertCount: { type: Number, default: 0 } });
defineEmits(['logout']);

const NAV_DEFS = [
  { name: 'dashboard', label: 'Beranda', icon: 'ph-house' },
  { name: 'pos', label: 'Kasir', icon: 'ph-shopping-cart-simple' },
  { name: 'session', label: 'Sesi Kasir', icon: 'ph-cash-register' },
  { name: 'events', label: 'Event', icon: 'ph-calendar-dots' },
  { name: 'products', label: 'Produk', icon: 'ph-package' },
  { name: 'artists', label: 'Artist', icon: 'ph-users-three' },
  { name: 'categories', label: 'Kategori', icon: 'ph-squares-four' },
  { name: 'stock', label: 'Stok', icon: 'ph-stack' },
  { name: 'vendors', label: 'Vendor', icon: 'ph-truck', roles: ['owner', 'admin', 'inventory'] },
  { name: 'materials', label: 'Bahan Baku', icon: 'ph-flask', roles: ['owner', 'admin', 'inventory'] },
  { name: 'customers', label: 'Pelanggan', icon: 'ph-address-book' },
  { name: 'preorders', label: 'Pre-order', icon: 'ph-clock-countdown' },
  { name: 'reports', label: 'Laporan', icon: 'ph-chart-bar' },
  { name: 'settings', label: 'Pengaturan', icon: 'ph-gear-six', roles: ['owner', 'admin'] },
];

const navItems = computed(() =>
  NAV_DEFS.filter((item) => !item.roles || item.roles.includes(auth.role)).map((item) => ({
    ...item,
    badge: item.name === 'pos' ? cartCount.value : item.name === 'preorders' ? props.preorderAlertCount : 0,
  }))
);
</script>

<template>
  <nav aria-label="Navigasi utama" class="sticky top-0 flex h-screen w-[228px] flex-none flex-col border-r border-line-2 bg-white">
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
      <li v-for="item in navItems" :key="item.name">
        <RouterLink
          :to="{ name: item.name }"
          class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2.5 text-[13.5px] font-medium text-muted-4 transition-colors hover:bg-line-7"
          :class="route.name === item.name ? 'bg-mint-100 font-bold text-brand-active' : ''"
        >
          <i class="ph-duotone text-[17px]" :class="item.icon" aria-hidden="true"></i>
          <span class="flex-1 text-left">{{ item.label }}</span>
          <span
            v-if="item.badge"
            class="rounded-full px-1.5 py-0.5 text-[10.5px] font-bold"
            :class="route.name === item.name ? 'bg-mint-200 text-brand-active' : 'bg-line-7 text-muted-2'"
          >
            {{ item.badge }}
          </span>
        </RouterLink>
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
