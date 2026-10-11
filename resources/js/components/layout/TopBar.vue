<script setup>
import Breadcrumb from './Breadcrumb.vue';
import GlobalSearch from './GlobalSearch.vue';
import NotificationBell from './NotificationBell.vue';
import OutletSelector from './OutletSelector.vue';
import SystemModeBadge from './SystemModeBadge.vue';
import UserMenu from './UserMenu.vue';

/**
 * 041-back-office-ui-redesign (US1) — topbar: jejak halaman di kiri; pencarian, pemilih outlet, status
 * DEMO/LIVE, lonceng, dan menu pengguna di kanan. Menggantikan AppTopbar.vue.
 *
 * Kendali yang datanya belum ada TIDAK dirender (bukan dinonaktifkan): `search` kosong → tanpa pencarian,
 * `attention` null → tanpa lonceng, ≤ 1 outlet → tanpa pemilih. Saat pemilih outlet tidak tampil, nama toko
 * dan event aktif tetap terlihat sebagai label (perilaku topbar sebelumnya).
 * SystemModeBadge sengaja berdiri di topbar untuk SEMUA peran: mode DEMO/LIVE tak boleh tersembunyi.
 */
defineProps({
  storeName: { type: String, default: '' },
  activeEvent: { type: Object, default: null },
  outlets: { type: Array, default: () => [] },
  outletId: { type: [Number, String, null], default: null },
  search: { type: Function, default: null },
  attention: { type: Array, default: null },
});
defineEmits(['logout', 'update:outletId']);
</script>

<template>
  <header class="sticky top-0 z-[60] flex h-[56px] flex-none items-center gap-4 border-b-2 border-line bg-surface-soft px-6">
    <div class="min-w-0 flex-1">
      <Breadcrumb />
    </div>

    <GlobalSearch :search="search" />

    <p v-if="outlets.length <= 1 && (storeName || activeEvent)" class="hidden min-w-0 max-w-[280px] truncate text-[12px] font-semibold text-muted lg:block" data-testid="store-label">
      <span v-if="storeName">{{ storeName }}</span>
      <span v-if="storeName && activeEvent"> · </span>
      <span v-if="activeEvent">{{ activeEvent.name }}</span>
    </p>

    <OutletSelector :outlets="outlets" :model-value="outletId" @update:model-value="$emit('update:outletId', $event)" />
    <SystemModeBadge />
    <NotificationBell :items="attention" />
    <slot name="actions" />
    <UserMenu @logout="$emit('logout')" />
  </header>
</template>
