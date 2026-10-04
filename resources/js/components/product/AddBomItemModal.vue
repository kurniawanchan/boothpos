<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import StatusPill from '../ui/StatusPill.vue';
import TablePagination from '../ui/TablePagination.vue';
import { eligibleBomLines, addBomItems } from '../../api/materials';
import { listVendors } from '../../api/vendors';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { useToastStore } from '../../stores/toast';

/**
 * 034-seller-po-bom — selector baris purchase order untuk BOM satu varian.
 * Server hanya mengembalikan baris milik SELLER varian ini yang POnya
 * ordered/received/paid; di sini hanya menyaring, memilih (boleh banyak,
 * lintas halaman), dan menambahkan. Biaya TIDAK dikirim: server menyalinnya
 * dari baris PO.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  variantId: { type: [Number, String, null], default: null },
  // 'add' = banyak baris -> BOM; 'replace' = pilih SATU baris pengganti (dikembalikan lewat `picked`).
  mode: { type: String, default: 'add' },
});
const emit = defineEmits(['close', 'added', 'picked']);

const { t } = useI18n();
const toast = useToastStore();

const loading = ref(false);
const adding = ref(false);
// 035: gagal memuat BUKAN "tidak ada baris layak" — tampilkan galat + Coba lagi.
const loadError = ref('');
const rows = ref([]);
const meta = ref({ current_page: 1, per_page: 10, total: 0, last_page: 1 });
const vendors = ref([]);
const filters = reactive({ q: '', purchase_order: '', vendor_id: '', line_type: '', date_from: '', date_to: '' });
// Pilihan dipertahankan antar halaman/filter: id -> baris.
const selected = reactive(new Map());
const selectedCount = computed(() => selected.size);
const hasFilters = computed(() => Object.values(filters).some((v) => v !== ''));

const vendorOptions = computed(() => [
  { value: '', label: t('master_data.bom_filter_all_vendors') },
  ...vendors.value.map((v) => ({ value: v.id, label: v.name })),
]);
const typeOptions = computed(() => [
  { value: '', label: t('master_data.bom_filter_all_types') },
  { value: 'material', label: t('master_data.bom_type_material') },
  { value: 'service', label: t('master_data.bom_type_service') },
]);

let requestId = 0;
async function load(page = 1) {
  if (!props.variantId) return;
  const mine = ++requestId;
  loading.value = true;
  loadError.value = '';
  try {
    const params = { page, per_page: 10 };
    for (const [k, v] of Object.entries(filters)) if (v !== '') params[k] = v;
    const res = await eligibleBomLines(props.variantId, params);
    if (mine !== requestId) return; // jawaban filter lama tidak menimpa yang baru
    rows.value = res.data;
    meta.value = res.meta;
  } catch (err) {
    if (mine === requestId) {
      rows.value = [];
      loadError.value = err.message || t('schema.load_failed');
    }
  } finally {
    if (mine === requestId) loading.value = false;
  }
}

watch(
  () => [props.open, props.variantId],
  async ([open]) => {
    if (!open) return;
    selected.clear();
    Object.assign(filters, { q: '', purchase_order: '', vendor_id: '', line_type: '', date_from: '', date_to: '' });
    await load(1);
    if (!vendors.value.length) {
      try {
        vendors.value = (await listVendors({ per_page: 100 })).data;
      } catch {
        // filter vendor opsional; daftar tetap bisa dipakai tanpa itu
      }
    }
  },
  { immediate: true },
);

// Filter teks diketik cepat -> tunda sebentar; filter lain langsung.
let debounce = null;
watch(() => [filters.q, filters.purchase_order], () => {
  clearTimeout(debounce);
  debounce = setTimeout(() => props.open && load(1), 300);
});
watch(() => [filters.vendor_id, filters.line_type, filters.date_from, filters.date_to], () => props.open && load(1));

function toggle(row) {
  if (row.in_bom) return;
  if (selected.has(row.purchase_order_item_id)) {
    selected.delete(row.purchase_order_item_id);
    return;
  }
  // Mode ganti: hanya satu pilihan pada satu waktu.
  if (props.mode === 'replace') selected.clear();
  selected.set(row.purchase_order_item_id, row);
}

function pickSelected() {
  const [id] = [...selected.keys()];
  if (id === undefined) return;
  emit('picked', id);
  emit('close');
}

async function addSelected() {
  if (!selected.size) return;
  adding.value = true;
  try {
    const payload = await addBomItems(props.variantId, [...selected.keys()].map((id) => ({ purchase_order_item_id: id })));
    toast.success(t('master_data.bom_added', { count: selected.size }));
    emit('added', payload);
    emit('close');
  } catch (err) {
    // 422/409 sudah ditoast interceptor bersama; daftar dimuat ulang supaya
    // baris yang baru saja terpakai ditandai "Sudah di BOM".
    await load(meta.value.current_page);
  } finally {
    adding.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="mode === 'replace' ? t('master_data.bom_replace_title') : t('master_data.bom_select_title')" max-width-class="max-w-[920px]" @close="emit('close')">
    <div class="flex flex-col gap-4 px-6 py-5">
      <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
        <BaseInput v-model="filters.q" :label="t('master_data.bom_filter_search')" data-autofocus />
        <BaseInput v-model="filters.purchase_order" :label="t('master_data.bom_filter_po')" />
        <BaseSelect v-model="filters.vendor_id" :label="t('master_data.bom_filter_vendor')" :options="vendorOptions" />
        <BaseSelect v-model="filters.line_type" :label="t('master_data.bom_filter_type')" :options="typeOptions" />
        <BaseInput v-model="filters.date_from" type="date" :label="t('master_data.bom_filter_date_from')" />
        <BaseInput v-model="filters.date_to" type="date" :label="t('master_data.bom_filter_date_to')" />
      </div>

      <div v-if="loading && !rows.length" class="py-10 text-center text-[13px] text-muted-3">{{ t('common.loading_data') }}</div>
      <div v-else-if="loadError" role="alert" class="flex flex-col items-center gap-3 rounded-lg border border-dashed border-danger-border px-4 py-9 text-center">
        <span class="text-[13px] font-semibold text-danger-text">{{ loadError }}</span>
        <BaseButton variant="secondary" size="sm" @click="load(meta.current_page)">{{ t('common.retry') }}</BaseButton>
      </div>
      <div v-else-if="!rows.length" class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-disabled-2 px-4 py-9 text-center">
        <span class="text-[13px] font-semibold text-muted-4">{{ t('master_data.bom_no_eligible_lines') }}</span>
        <span v-if="!hasFilters" class="text-[12px] text-muted-3">{{ t('master_data.bom_no_eligible_hint') }}</span>
        <router-link v-if="!hasFilters" to="/purchase-orders" class="text-[12.5px] font-semibold text-brand-active hover:underline" @click="emit('close')">{{ t('master_data.bom_go_to_po') }}</router-link>
      </div>
      <div v-else class="overflow-hidden rounded-lg border border-line-2">
        <table class="w-full border-collapse text-[13px]">
          <thead>
            <tr class="bg-surface-subtle text-left">
              <th class="w-[40px] px-3 py-2"><span class="sr-only">{{ t('master_data.bom_col_select') }}</span></th>
              <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.bom_col_item') }}</th>
              <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.bom_col_type') }}</th>
              <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.bom_col_po') }}</th>
              <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.bom_col_vendor') }}</th>
              <th class="px-3 py-2 text-right font-bold text-muted-2">{{ t('master_data.bom_col_unit_price') }}</th>
              <th class="px-3 py-2 text-right font-bold text-muted-2">{{ t('master_data.bom_col_po_qty') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in rows"
              :key="row.purchase_order_item_id"
              class="border-t border-line-5 transition-colors"
              :class="row.in_bom ? 'opacity-60' : 'cursor-pointer hover:bg-line-7'"
              @click="toggle(row)"
            >
              <td class="px-3 py-2">
                <input
                  type="checkbox"
                  class="h-4 w-4 accent-brand"
                  :checked="row.in_bom || selected.has(row.purchase_order_item_id)"
                  :disabled="row.in_bom"
                  :aria-label="row.item_name"
                  @click.stop
                  @change="toggle(row)"
                />
              </td>
              <td class="px-3 py-2 font-semibold">
                {{ row.item_name }}
                <StatusPill v-if="row.in_bom" variant="neutral" class="ml-1.5">{{ t('master_data.bom_in_bom') }}</StatusPill>
              </td>
              <td class="px-3 py-2">{{ row.line_type === 'service' ? t('master_data.bom_type_service') : t('master_data.bom_type_material') }}</td>
              <td class="px-3 py-2">
                <span class="font-mono text-[12px] font-bold text-brand-active">{{ row.po_number }}</span>
                <span class="block text-[11.5px] text-muted-3">{{ formatDateTime(row.po_date) }}</span>
              </td>
              <td class="px-3 py-2">{{ row.vendor_name }}</td>
              <td class="px-3 py-2 text-right font-semibold">{{ formatIDR(row.unit_price) }}</td>
              <td class="px-3 py-2 text-right">{{ row.po_qty }}</td>
            </tr>
          </tbody>
        </table>
        <TablePagination :meta="meta" @change="load" />
      </div>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton v-if="mode === 'replace'" :disabled="!selectedCount" @click="pickSelected">{{ t('master_data.bom_use_line') }}</BaseButton>
        <BaseButton v-else :loading="adding" :disabled="!selectedCount" @click="addSelected">{{ t('master_data.bom_add_selected', { count: selectedCount }) }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
