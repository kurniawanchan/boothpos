<script setup>
import { reactive, ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listDiningTables, createDiningTable, updateDiningTable, deleteDiningTable, qrBatch, rotateTableQr } from '../../api/diningTables';
import { useToastStore } from '../../stores/toast';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import { useOutletStore } from '../../stores/outlet';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import TableQrSheet from './TableQrSheet.vue';
import TableQrBulkSheet from './TableQrBulkSheet.vue';
import TablePagination from '../ui/TablePagination.vue';

/**
 * 071 — prop `outletIds` (filter Outlet halaman, [] = semua) menyaring daftar; daftar diminta TANPA header outlet bilah
 * atas (filter halaman menggantikannya) dan tabel menampilkan kolom Outlet bila ada beberapa outlet.
 *
 * 028 US1 — daftar + form meja (tab "Meja" pada Pengaturan Restoran). Layar ini
 * sendiri sudah digerbang rute `dining_setup`; server tetap memeriksa ulang.
 * Galat 409 (hapus meja yang pernah dipakai, nonaktifkan meja bertab terbuka)
 * sudah ditoast oleh penangan galat pusat.
 */
const outletStore = useOutletStore();
const emit = defineEmits(['reset-outlet']);
const props = defineProps({
  outletIds: { type: Array, default: () => [] },
  // Filter Outlet halaman berbeda dari keadaan awal (dipegang induk): ikut memunculkan tombol Atur ulang bersama.
  outletDirty: { type: Boolean, default: false },
});
const { t } = useI18n();
const toast = useToastStore();

const rows = ref([]);
const loading = ref(false);

// 090 / US7 — filter + paginasi daftar meja (opt-in di server: `page` memicu amplop {data, meta}). Pilihan meja bertahan
// antar halaman, tetapi DIKOSONGKAN saat filter berubah supaya tidak ada meja tersembunyi yang ikut dibuatkan QR.
const PER_PAGE = 25;
const filter = reactive({ q: '', area: '', active: '' });
const page = ref(1);
const meta = ref({ current_page: 1, per_page: PER_PAGE, total: 0, last_page: 1, areas: [] });

function listParams() {
  return {
    page: page.value,
    per_page: PER_PAGE,
    ...(filter.q.trim() ? { q: filter.q.trim() } : {}),
    ...(filter.area ? { area: filter.area } : {}),
    ...(filter.active !== '' ? { is_active: filter.active === 'active' ? 1 : 0 } : {}),
    ...(props.outletIds.length ? { outlet_id: props.outletIds } : {}),
  };
}

async function load() {
  loading.value = true;
  try {
    const res = await listDiningTables(listParams(), { skipOutletHeader: true });
    rows.value = res.data;
    // Server lama/tanpa paginasi tidak mengirim `meta`: tampil sebagai satu halaman penuh.
    meta.value = res.meta ?? { current_page: 1, per_page: res.data.length || PER_PAGE, total: res.data.length, last_page: 1, areas: [] };
  } finally {
    loading.value = false;
  }
}

function applyFilters() {
  page.value = 1;
  clearSelection();
  return load();
}

let searchTimer = null;
watch(() => filter.q, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(applyFilters, 300);
});
watch(() => [filter.area, filter.active], applyFilters);
onBeforeUnmount(() => clearTimeout(searchTimer));

function goToPage(next) {
  page.value = next;
  return load();
}

const hasFilter = computed(() => filter.q.trim() !== '' || filter.area !== '' || filter.active !== '');
function resetFilters() {
  clearTimeout(searchTimer);
  Object.assign(filter, { q: '', area: '', active: '' });
  emit('reset-outlet');
}

const areaOptions = computed(() => [
  { value: '', label: t('dining_setup.filter_area_all') },
  ...(meta.value.areas ?? []).map((a) => ({ value: a, label: a })),
]);
const activeOptions = computed(() => [
  { value: '', label: t('dining_setup.filter_status_all') },
  { value: 'active', label: t('common.active') },
  { value: 'inactive', label: t('common.inactive') },
]);

// Pilihan meja untuk QR massal. `allFiltered` = "semua hasil filter" (server menyaring sendiri, tak bergantung halaman).
const selected = ref(new Set());
const allFiltered = ref(false);
const pageIds = computed(() => rows.value.map((r) => r.id));
const pageAllSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.has(id)));
const selectionCount = computed(() => (allFiltered.value ? meta.value.total : selected.value.size));
const offerAllFiltered = computed(() => !allFiltered.value && pageAllSelected.value && meta.value.total > rows.value.length);

function clearSelection() {
  selected.value = new Set();
  allFiltered.value = false;
}
function toggleRow(id) {
  const next = new Set(selected.value);
  next.has(id) ? next.delete(id) : next.add(id);
  selected.value = next;
  allFiltered.value = false;
}
function togglePage() {
  const next = new Set(selected.value);
  if (pageAllSelected.value) pageIds.value.forEach((id) => next.delete(id));
  else pageIds.value.forEach((id) => next.add(id));
  selected.value = next;
  allFiltered.value = false;
}

const showBulk = ref(false);
const bulkItems = ref([]);
const bulkSkipped = ref(0);
const bulkBusy = ref(false);

async function makeBulkQr() {
  const MAX = 200;
  if (!allFiltered.value && selected.value.size > MAX) {
    toast.error(t('dining_setup.qr_too_many', { max: MAX }));
    return;
  }
  bulkBusy.value = true;
  try {
    const payload = allFiltered.value
      ? { filter: {
          ...(filter.q.trim() ? { q: filter.q.trim() } : {}),
          ...(filter.area ? { area: filter.area } : {}),
          ...(filter.active !== '' ? { is_active: filter.active === 'active' } : {}),
          ...(props.outletIds.length ? { outlet_id: props.outletIds } : {}),
        } }
      : { ids: [...selected.value] };
    const res = await qrBatch(payload, { skipOutletHeader: true });
    if (!res.items.length) {
      toast.error(t('dining_setup.qr_none'));
      return;
    }
    bulkItems.value = res.items;
    bulkSkipped.value = res.skipped?.inactive ?? 0;
    showBulk.value = true;
  } catch {
    // 409 `qr_base_url_invalid` / 422 `qr_batch_too_large` sudah ditoast oleh penangan galat pusat.
  } finally {
    bulkBusy.value = false;
  }
}

onMounted(load);
// Filter Outlet halaman berubah → kembali ke halaman 1 dan buang pilihan (meja outlet lain tak boleh ikut).
watch(() => props.outletIds.join(','), applyFilters);

const columns = computed(() => [
  { key: 'select', label: '' },
  { key: 'name', label: t('dining_setup.col_name') },
  ...(outletStore.multi ? [{ key: 'outlet', label: t('frame.outlet_label') }] : []),
  { key: 'area', label: t('dining_setup.col_area') },
  { key: 'seats', label: t('dining_setup.col_seats') },
  { key: 'is_active', label: t('dining_setup.col_status') },
  { key: 'actions', label: '' },
]);

const outletOf = (row) => outletStore.outlets.find((o) => o.id === row.outlet_id) ?? null;
const emptyMessage = computed(() => (props.outletIds.length || hasFilter.value ? t('dining_setup.no_tables_filtered') : t('dining_setup.no_tables')));

const showForm = ref(false);
const editing = ref(null);
const form = reactive({ name: '', area: '', seats: 4, display_order: 0, is_active: true, outlet_id: '' });
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

// 090 / US2 — QR pesan mandiri per meja. Panggilan memakai `skipOutletHeader` seperti daftar: filter Outlet halaman
// menggantikan header bilah atas, dan server tetap memotong dengan outlet yang boleh dilihat pengguna.
const qrBusyId = ref(null);
const qrItem = ref(null);
const showQr = ref(false);
const showRotate = ref(false);
const rotating = ref(false);
// Tidak ada satu baris pun siap berarti alamat dasar belum bisa dijangkau ponsel pelanggan (server yang memutuskan).
const qrNotReady = computed(() => rows.value.length > 0 && rows.value.every((r) => r.qr_ready === false));

async function makeQr(table) {
  qrBusyId.value = table.id;
  try {
    const res = await qrBatch({ ids: [table.id] }, { skipOutletHeader: true });
    if (!res.items.length) {
      toast.error(t('dining_setup.qr_none'));
      return;
    }
    qrItem.value = res.items[0];
    showQr.value = true;
  } catch {
    // 409 `qr_base_url_invalid` sudah ditoast oleh penangan galat pusat; banner di atas menjelaskan cara memperbaikinya.
  } finally {
    qrBusyId.value = null;
  }
}

async function performRotate() {
  rotating.value = true;
  try {
    const res = await rotateTableQr(qrItem.value.table_id, { skipOutletHeader: true });
    qrItem.value = { ...qrItem.value, url: res.url };
    toast.success(t('dining_setup.qr_remade'));
    showRotate.value = false;
  } catch {
    showRotate.value = false;
  } finally {
    rotating.value = false;
  }
}

function clearErrors() {
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
}

function openCreate() {
  editing.value = null;
  Object.assign(form, { name: '', area: '', seats: 4, display_order: 0, is_active: true, outlet_id: outletStore.selected ?? '' });
  clearErrors();
  showForm.value = true;
}

function openEdit(table) {
  editing.value = table;
  // 071 — outlet saat ini terisi; hanya dikirim bila diganti (pemindahan, diperiksa server).
  Object.assign(form, { name: table.name, area: table.area, seats: table.seats, display_order: table.display_order, is_active: table.is_active, outlet_id: table.outlet_id ?? '' });
  clearErrors();
  showForm.value = true;
}

async function save() {
  saving.value = true;
  clearErrors();
  const payload = {
    name: form.name,
    area: form.area || null,
    seats: Number(form.seats) || 1,
    display_order: Number(form.display_order) || 0,
    is_active: form.is_active,
    // 045 Stage 2 — saat membuat, outlet dikirim; server menolak yang di luar outlet pengguna. 071 — saat mengubah, hanya
    // bila berbeda dari outlet saat ini: itu pemindahan, dan server memeriksa tab terbuka/izin/nama di outlet tujuan.
    ...(form.outlet_id && (!editing.value || Number(form.outlet_id) !== editing.value.outlet_id) ? { outlet_id: Number(form.outlet_id) } : {}),
  };
  try {
    if (editing.value) {
      await updateDiningTable(editing.value.id, payload);
      toast.success(t('dining_setup.table_updated'));
    } else {
      await createDiningTable(payload);
      toast.success(t('dining_setup.table_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

function confirmDelete(table) {
  deleteTarget.value = table;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteDiningTable(deleteTarget.value.id);
    toast.success(t('dining_setup.table_deleted'));
    showDelete.value = false;
    await load();
  } catch {
    // 409 sudah ditoast global; dialog tetap tertutup agar daftar bisa dibaca ulang.
    showDelete.value = false;
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5">
    <div class="flex justify-end">
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('dining_setup.new_table') }}
      </BaseButton>
    </div>

    <div class="flex flex-wrap items-end gap-3" data-testid="tables-filter-bar">
      <slot name="outlet-filter" />
      <label class="flex flex-col gap-1.5">
        <span class="text-[12.5px] font-semibold text-muted-4">{{ t('dining_setup.filter_search') }}</span>
        <input
          v-model="filter.q"
          type="search"
          :placeholder="t('dining_setup.filter_search_placeholder')"
          class="h-[46px] w-60 rounded-lg border border-line bg-white px-3.5 text-[14px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
        />
      </label>
      <BaseSelect v-model="filter.area" :label="t('dining_setup.col_area')" :options="areaOptions" class="w-48" />
      <BaseSelect v-model="filter.active" :label="t('dining_setup.col_status')" :options="activeOptions" class="w-44" />
      <button v-if="hasFilter || outletDirty" type="button" class="pb-3 text-[12.5px] font-bold text-brand-active underline decoration-dotted hover:text-brand" @click="resetFilters">
        {{ t('page.reset_filters') }}
      </button>
    </div>

    <div v-if="selectionCount > 0 || rows.length" class="flex flex-wrap items-center gap-3 text-[12.5px]" data-testid="tables-selection-bar">
      <label class="flex items-center gap-2 font-semibold text-muted-4">
        <input type="checkbox" class="h-4 w-4 accent-brand" :checked="pageAllSelected" :disabled="!rows.length" :aria-label="t('dining_setup.select_page')" @change="togglePage" />
        {{ t('dining_setup.select_page') }}
      </label>
      <template v-if="selectionCount > 0">
        <span class="font-bold text-ink" data-testid="tables-selection-count">{{ t('dining_setup.selected_count', { count: selectionCount }) }}</span>
        <button v-if="offerAllFiltered" type="button" class="font-bold text-brand-active underline" data-testid="select-all-filtered" @click="allFiltered = true">
          {{ t('dining_setup.select_all_filtered', { total: meta.total }) }}
        </button>
        <span v-if="allFiltered" class="text-muted-3">{{ t('dining_setup.all_filtered_selected', { total: meta.total }) }}</span>
        <button type="button" class="font-semibold text-muted-4 underline" @click="clearSelection">{{ t('dining_setup.clear_selection') }}</button>
        <BaseButton size="sm" :loading="bulkBusy" data-testid="bulk-qr" @click="makeBulkQr">{{ t('dining_setup.qr_make_selected', { count: selectionCount }) }}</BaseButton>
      </template>
    </div>

    <p v-if="qrNotReady" class="border border-warn-border bg-warn-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-warn-text" role="status" data-testid="qr-not-ready">
      {{ t('dining_setup.qr_not_ready') }}
    </p>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="rows" :loading="loading" :empty-message="emptyMessage">
        <template #cell-select="{ row }">
          <input type="checkbox" class="h-4 w-4 accent-brand" :checked="selected.has(row.id) || allFiltered" :aria-label="t('dining_setup.select_row', { name: row.name })" @change="toggleRow(row.id)" />
        </template>
        <template #cell-name="{ row }"><span class="font-bold text-ink">{{ row.name }}</span></template>
        <template #cell-outlet="{ row }">
          <template v-if="outletOf(row)"><span class="font-mono text-[11.5px] font-bold text-ink">{{ outletOf(row).code }}</span> <span class="text-muted-4">{{ outletOf(row).name }}</span></template>
          <template v-else>—</template>
        </template>
        <template #cell-area="{ row }">{{ row.area || '—' }}</template>
        <template #cell-is_active="{ row }">
          <div class="flex flex-wrap gap-1.5">
            <StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
            <StatusPill v-if="row.has_open_tab" variant="warn">{{ t('dining_setup.has_open_tab') }}</StatusPill>
          </div>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button
              v-if="row.is_active"
              type="button"
              class="text-[12.5px] font-semibold text-brand-active disabled:opacity-50"
              :disabled="qrBusyId === row.id"
              :aria-label="t('dining_setup.qr_make_for', { name: row.name })"
              @click="makeQr(row)"
            >
              {{ t('dining_setup.qr_make') }}
            </button>
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text" @click="confirmDelete(row)">{{ t('common.delete') }}</button>
          </div>
        </template>
      </DataTable>
      <TablePagination v-if="meta.total > 0 && meta.last_page > 1" :meta="meta" @change="goToPage" />
    </div>

    <BaseModal :open="showForm" :title="editing ? t('dining_setup.edit_table') : t('dining_setup.new_table')" max-width-class="max-w-[460px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="save">
        <BaseSelect
          v-if="outletStore.multi"
          v-model="form.outlet_id"
          :label="t('frame.outlet_label')"
          required
          :options="outletStore.outlets.map((o) => ({ value: o.id, label: o.name }))"
          :error="formErrors.outlet_id"
        />
        <BaseInput v-model="form.name" :label="t('dining_setup.table_name')" required maxlength="60" :error="formErrors.name" />
        <BaseInput v-model="form.area" :label="t('dining_setup.table_area')" maxlength="60" :hint="t('dining_setup.table_area_hint')" :error="formErrors.area" />
        <BaseInput v-model="form.seats" type="number" min="1" max="99" :label="t('dining_setup.table_seats')" :error="formErrors.seats" />
        <BaseInput v-model="form.display_order" type="number" :label="t('dining_setup.table_display_order')" :error="formErrors.display_order" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          {{ t('common.active') }}
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <TableQrBulkSheet :open="showBulk" :items="bulkItems" :skipped="bulkSkipped" @close="showBulk = false" />
    <TableQrSheet :open="showQr" :item="qrItem" :rotating="rotating" @close="showQr = false" @rotate="showRotate = true" />

    <ConfirmDialog
      :open="showRotate"
      :title="t('dining_setup.qr_remake')"
      :message="t('dining_setup.qr_remake_confirm', { name: qrItem?.table_name })"
      :confirm-label="t('dining_setup.qr_remake')"
      :loading="rotating"
      @close="showRotate = false"
      @confirm="performRotate"
    />

    <ConfirmDialog
      :open="showDelete"
      :title="t('dining_setup.delete_table')"
      :message="t('dining_setup.delete_table_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
