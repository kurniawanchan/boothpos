<script setup>
import { computed, ref, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import ErrorState from './ErrorState.vue';

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
  // 041-back-office-ui-redesign (US2) — pilihan baris. Pemanggil memegang daftar id terpilih (v-model:selected);
  // kotak di kepala hanya memilih/membatalkan baris di HALAMAN INI (bukan semua hasil di server).
  selectable: { type: Boolean, default: false },
  selected: { type: Array, default: () => [] },
  // Nama aksesibel kotak pilih per baris (mis. row => `Pilih ${row.order_number}`); bawaan generik "Pilih baris".
  rowLabel: { type: Function, default: null },
  // Kepala tabel menempel saat menggulir di dalam tabel; bekerja bila pemanggil membatasi tinggi lewat class
  // (mis. `max-h-[560px]`) — class jatuh ke elemen gulir di akar.
  stickyHeader: { type: Boolean, default: false },
  // Pesan galat memuat; bila terisi tabel menampilkan keadaan galat + tombol coba lagi, bukan area kosong.
  error: { type: String, default: '' },
});
const emit = defineEmits(['sort', 'update:selected', 'retry']);

function onHeaderClick(col) {
  if (!col.sortable) return;
  const dir = props.sortKey === col.key && props.sortDir === 'asc' ? 'desc' : 'asc';
  emit('sort', { key: col.key, dir });
}

const colspan = computed(() => props.columns.length + (props.selectable ? 1 : 0));
const selectedSet = computed(() => new Set(props.selected));
const pageIds = computed(() => props.rows.map((r) => r[props.rowKey]));
const selectedOnPage = computed(() => pageIds.value.filter((id) => selectedSet.value.has(id)).length);
const allSelected = computed(() => pageIds.value.length > 0 && selectedOnPage.value === pageIds.value.length);
const someSelected = computed(() => selectedOnPage.value > 0 && !allSelected.value);

// `indeterminate` hanya bisa diatur lewat properti DOM, bukan atribut.
const headBox = ref(null);
watchEffect(
  () => {
    if (headBox.value) headBox.value.indeterminate = someSelected.value;
  },
  { flush: 'post' }
);

function toggleAll() {
  const rest = props.selected.filter((id) => !pageIds.value.includes(id));
  emit('update:selected', allSelected.value ? rest : [...rest, ...pageIds.value]);
}
function toggleRow(id) {
  emit('update:selected', selectedSet.value.has(id) ? props.selected.filter((x) => x !== id) : [...props.selected, id]);
}

const stickyTh = computed(() => (props.stickyHeader ? 'sticky top-0 z-[1]' : ''));
</script>

<template>
  <div class="overflow-auto">
    <table class="w-full border-collapse">
      <thead>
        <tr>
          <th v-if="selectable" scope="col" class="w-10 border-b-2 border-line bg-surface-subtle px-4 py-3" :class="stickyTh">
            <input
              ref="headBox"
              type="checkbox"
              class="h-4 w-4 cursor-pointer accent-brand"
              :checked="allSelected"
              :aria-label="t('page.select_all')"
              @change="toggleAll"
            />
          </th>
          <th
            v-for="col in columns"
            :key="col.key"
            scope="col"
            :aria-sort="col.sortable && sortKey === col.key ? (sortDir === 'asc' ? 'ascending' : 'descending') : undefined"
            class="whitespace-nowrap border-b-2 border-line bg-surface-subtle px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted"
            :class="[stickyTh, { 'cursor-pointer select-none hover:text-ink': col.sortable }]"
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
        <tr v-if="error">
          <td :colspan="colspan"><ErrorState :message="error" @retry="emit('retry')" /></td>
        </tr>
        <tr v-else-if="loading">
          <td :colspan="colspan" class="px-4 py-12 text-center text-[13px] text-muted-3" role="status" aria-busy="true">{{ t('common.loading_data') }}</td>
        </tr>
        <tr v-else-if="rows.length === 0">
          <td :colspan="colspan" class="px-4 py-12 text-center text-[13px] text-muted-3">{{ emptyMessage || t('common.no_data') }}</td>
        </tr>
        <tr
          v-for="row in rows"
          v-else
          :key="row[rowKey]"
          class="border-b border-line-5 last:border-b-0 transition-colors hover:bg-line-7"
          :class="[rowClass ? rowClass(row) : '', selectable && selectedSet.has(row[rowKey]) ? 'bg-mint-50' : '']"
        >
          <td v-if="selectable" class="w-10 px-4 py-3.5 align-middle">
            <input
              type="checkbox"
              class="h-4 w-4 cursor-pointer accent-brand"
              :checked="selectedSet.has(row[rowKey])"
              :aria-label="rowLabel ? rowLabel(row) : t('page.select_row')"
              @change="toggleRow(row[rowKey])"
            />
          </td>
          <td v-for="col in columns" :key="col.key" class="px-4 py-3.5 align-middle text-[13.5px]">
            <slot :name="`cell-${col.key}`" :row="row">{{ row[col.key] }}</slot>
          </td>
        </tr>
      </tbody>
      <!-- Footer opsional (mis. baris Grand Total) — hanya dirender kalau slot
           diisi oleh pemanggil, jadi tabel yang tidak butuh ringkasan tidak
           terpengaruh sama sekali. -->
      <tfoot v-if="$slots.footer && !loading && !error && rows.length > 0">
        <slot name="footer" />
      </tfoot>
    </table>
  </div>
</template>
