<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import TablesAdmin from '../components/restaurant/TablesAdmin.vue';
import StationsAdmin from '../components/restaurant/StationsAdmin.vue';
import ModifierGroupsAdmin from '../components/restaurant/ModifierGroupsAdmin.vue';
import BaseMultiSelect from '../components/ui/BaseMultiSelect.vue';
import { useOutletPageFilter } from '../composables/useOutletPageFilter';

/**
 * 028 — Pengaturan Restoran (menu `dining_setup`). Cangkang bertab; setiap
 * user story menambah satu tab (Meja, Stasiun, Modifier).
 *
 * 071 — filter Outlet di atas isi tab Meja dan Stasiun (bukan Modifier: grup modifier bukan milik outlet). Keadaannya
 * dipegang di sini, bukan di tiap tab, karena tab dipasang ulang saat berpindah dan pilihan harus ikut. Tab menerimanya
 * sebagai prop `outletIds` dan meminta daftar tanpa header outlet bilah atas.
 */
const { t } = useI18n();
const outletFilter = useOutletPageFilter();
const FILTERED_TABS = ['tables', 'stations'];
const tabs = [
  { key: 'tables', label: 'dining_setup.tab_tables', component: TablesAdmin },
  { key: 'stations', label: 'dining_setup.tab_stations', component: StationsAdmin },
  { key: 'modifiers', label: 'dining_setup.tab_modifiers', component: ModifierGroupsAdmin },
];
const active = ref('tables');
const outletModel = computed({
  get: () => outletFilter.ids.value,
  set: (ids) => outletFilter.set(ids),
});
const propsFor = (key) => (FILTERED_TABS.includes(key) ? { outletIds: outletFilter.ids.value } : {});
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

    <div v-if="outletFilter.visible.value && FILTERED_TABS.includes(active)" class="flex flex-wrap items-center gap-3" data-testid="outlet-filter-bar">
      <BaseMultiSelect v-model="outletModel" :options="outletFilter.options.value" :all-label="t('dining_setup.filter_outlet_all')" class="w-60" data-testid="outlet-filter" />
      <button v-if="outletFilter.active.value" type="button" class="text-[12.5px] font-bold text-brand-active underline decoration-dotted hover:text-brand" @click="outletFilter.reset()">
        {{ t('page.reset_filters') }}
      </button>
    </div>

    <template v-for="tab in tabs" :key="tab.key">
      <component :is="tab.component" v-if="active === tab.key" v-bind="propsFor(tab.key)" />
    </template>
  </div>
</template>
