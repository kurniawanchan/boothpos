<script setup>
defineProps({
  columns: { type: Array, required: true }, // [{ key, label }]
  rows: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  emptyMessage: { type: String, default: 'Belum ada data.' },
  rowKey: { type: String, default: 'id' },
});
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
          >
            {{ col.label }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="loading">
          <td :colspan="columns.length" class="px-4 py-12 text-center text-[13px] text-muted-3">Memuat data…</td>
        </tr>
        <tr v-else-if="rows.length === 0">
          <td :colspan="columns.length" class="px-4 py-12 text-center text-[13px] text-muted-3">{{ emptyMessage }}</td>
        </tr>
        <tr v-for="row in rows" v-else :key="row[rowKey]" class="border-b border-line-5 last:border-b-0">
          <td v-for="col in columns" :key="col.key" class="px-4 py-3.5 align-middle text-[13.5px]">
            <slot :name="`cell-${col.key}`" :row="row">{{ row[col.key] }}</slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
