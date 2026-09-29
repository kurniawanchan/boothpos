<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({
  columns: { type: Array, required: true }, // [{ key, label, sortable? }]
  rows: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  emptyMessage: { type: String, default: '' },
  rowKey: { type: String, default: 'id' },
  // Sort state is owned by the caller (usually mirrored into a query
  // param) — this component only renders the current state and emits a
  // request to change it, same division of responsibility as
  // TablePagination.vue's `@change`.
  sortKey: { type: String, default: null },
  sortDir: { type: String, default: 'asc' }, // 'asc' | 'desc'
  // Optional per-row extra class (e.g. a data-quality warning highlight).
  // A function, not a static prop, since the class depends on each row's
  // own data — return '' for rows that need no extra styling.
  rowClass: { type: Function, default: null },
});
const emit = defineEmits(['sort']);

function onHeaderClick(col) {
  if (!col.sortable) return;
  const dir = props.sortKey === col.key && props.sortDir === 'asc' ? 'desc' : 'asc';
  emit('sort', { key: col.key, dir });
}
</script>

<template>
  <div class="overflow-auto">
    <table class="w-full border-collapse">
      <thead>
        <tr>
          <th
            v-for="col in columns"
            :key="col.key"
            scope="col"
            class="whitespace-nowrap border-b border-line-2 bg-surface-subtle px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted-2"
            :class="{ 'cursor-pointer select-none hover:text-ink': col.sortable }"
            @click="onHeaderClick(col)"
          >
            <span class="inline-flex items-center gap-1">
              {{ col.label }}
              <i
                v-if="col.sortable"
                class="ph-duotone text-[12px]"
                :class="sortKey === col.key
                  ? (sortDir === 'asc' ? 'ph-arrow-up text-brand-active' : 'ph-arrow-down text-brand-active')
                  : 'ph-arrows-down-up text-muted-4'"
                :aria-label="sortKey === col.key ? t(`common.sorted_${sortDir}`) : t('common.sortable_column')"
              ></i>
            </span>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="loading">
          <td :colspan="columns.length" class="px-4 py-12 text-center text-[13px] text-muted-3">{{ t('common.loading_data') }}</td>
        </tr>
        <tr v-else-if="rows.length === 0">
          <td :colspan="columns.length" class="px-4 py-12 text-center text-[13px] text-muted-3">{{ emptyMessage || t('common.no_data') }}</td>
        </tr>
        <tr
          v-for="row in rows"
          v-else
          :key="row[rowKey]"
          class="border-b border-line-5 last:border-b-0 transition-colors hover:bg-line-7"
          :class="rowClass ? rowClass(row) : ''"
        >
          <td v-for="col in columns" :key="col.key" class="px-4 py-3.5 align-middle text-[13.5px]">
            <slot :name="`cell-${col.key}`" :row="row">{{ row[col.key] }}</slot>
          </td>
        </tr>
      </tbody>
      <!-- Footer opsional (mis. baris Grand Total) — hanya dirender kalau slot
           diisi oleh pemanggil, jadi tabel yang tidak butuh ringkasan tidak
           terpengaruh sama sekali. -->
      <tfoot v-if="$slots.footer && !loading && rows.length > 0">
        <slot name="footer" />
      </tfoot>
    </table>
  </div>
</template>
