<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import TablesAdmin from '../components/restaurant/TablesAdmin.vue';
import StationsAdmin from '../components/restaurant/StationsAdmin.vue';
import ModifierGroupsAdmin from '../components/restaurant/ModifierGroupsAdmin.vue';

/**
 * 028 — Pengaturan Restoran (menu `dining_setup`). Cangkang bertab; setiap
 * user story menambah satu tab (Meja, Stasiun, Modifier).
 */
const { t } = useI18n();
const tabs = [
  { key: 'tables', label: 'dining_setup.tab_tables', component: TablesAdmin },
  { key: 'stations', label: 'dining_setup.tab_stations', component: StationsAdmin },
  { key: 'modifiers', label: 'dining_setup.tab_modifiers', component: ModifierGroupsAdmin },
];
const active = ref('tables');
</script>

<template>
  <div class="flex flex-col gap-4 px-[26px] pb-10 pt-5">
    <div role="tablist" class="flex gap-1 border-b border-line-2">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        role="tab"
        type="button"
        :aria-selected="active === tab.key"
        class="-mb-px border-b-2 px-4 py-2.5 text-[13.5px] font-bold"
        :class="active === tab.key ? 'border-brand text-brand-active' : 'border-transparent text-muted-3 hover:text-ink'"
        @click="active = tab.key"
      >
        {{ t(tab.label) }}
      </button>
    </div>

    <template v-for="tab in tabs" :key="tab.key">
      <component :is="tab.component" v-if="active === tab.key" />
    </template>
  </div>
</template>
