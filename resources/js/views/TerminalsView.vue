<script setup>
import { computed, reactive, ref, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../composables/usePaginatedList';
import { useOutletPageFilter } from '../composables/useOutletPageFilter';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import {
  listDevices,
  createDevice,
  updateDevice,
  deactivateDevice,
  reactivateDevice,
  deleteDevicePermanently,
  bulkDevices,
} from '../api/devices';
import { useOutletStore } from '../stores/outlet';
import { useToastStore } from '../stores/toast';
import { formatDateTime } from '../utils/date';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BulkBar from '../components/page/BulkBar.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseMultiSelect from '../components/ui/BaseMultiSelect.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import PageHeader from '../components/page/PageHeader.vue';
import FilterBar from '../components/page/FilterBar.vue';

/**
 * 079 — manajemen terminal (tablet kasir) dari web untuk owner/admin: daftar berfilter (outlet, status, nama), tambah di
 * outlet pilihan, ganti nama, pindah outlet, nonaktif/aktifkan lagi, dan hapus permanen bila belum pernah dipakai.
 * Semua aturan (nama unik per outlet, pindah ditolak saat ada shift terbuka, hapus ditolak bila ada order/shift)
 * ditegakkan server; layar hanya menampilkan 409/422-nya lewat penangan galat bersama.
 */
const { t } = useI18n();
const toast = useToastStore();
const outletStore = useOutletStore();
const outletFilter = useOutletPageFilter();

const status = ref('');
const search = ref('');

// ---- pilihan massal (FR-025–FR-031) ----
// Pilihan hanya berlaku untuk baris di halaman ini; mengubah filter, pencarian, atau halaman mengosongkannya supaya aksi
// tidak pernah mengenai baris yang sudah tak terlihat.
const selected = ref([]);
const bulkResult = ref(null);
const bulkBusy = ref(false);
function clearSelection() {
  selected.value = [];
  bulkResult.value = null;
}

const fetcher = (params) => listDevices(params, { skipOutletHeader: true });
const { items, meta, loading, error, load, setPage: setListPage, setFilter } = usePaginatedList(fetcher);
function setPage(page) {
  clearSelection();
  setListPage(page);
}

function queryFilters() {
  return {
    outlet_id: outletFilter.ids.value.length ? outletFilter.ids.value : undefined,
    status: status.value || undefined,
    q: search.value.trim() || undefined,
  };
}

// Satu pintu untuk semua perubahan filter (cari, status, outlet, atur ulang): permintaan beruntun dilebur jadi satu.
const applyFilters = useDebouncedFn(() => {
  clearSelection();
  setFilter(queryFilters());
}, 250);
function onSearch(value) {
  search.value = value;
  applyFilters();
}

const outletModel = computed({
  get: () => outletFilter.ids.value,
  set: (ids) => outletFilter.set(ids),
});
watch(() => outletFilter.ids.value.join(','), applyFilters);

const statusOptions = computed(() => [
  { value: '', label: t('terminals.status_all') },
  { value: 'active', label: t('terminals.status_active') },
  { value: 'revoked', label: t('terminals.status_revoked') },
]);
watch(status, applyFilters);

const filtersActive = computed(() => !!search.value.trim() || !!status.value || outletFilter.active.value);
function resetFilters() {
  search.value = '';
  status.value = '';
  outletFilter.reset();
  applyFilters();
}

// Titik awal filter outlet = outlet di bilah atas (useOutletPageFilter); muatan pertama langsung, tanpa jeda.
onMounted(() => setFilter(queryFilters()));

const columns = computed(() => [
  { key: 'name', label: t('terminals.col_name') },
  { key: 'outlet', label: t('terminals.col_outlet') },
  { key: 'status', label: t('terminals.col_status') },
  { key: 'last_seen_at', label: t('terminals.col_last_seen') },
  { key: 'actions', label: '' },
]);

// ---- tambah / ubah ----
const showForm = ref(false);
const editing = ref(null);
const form = reactive({ name: '', outlet_id: '' });
const formErrors = reactive({});
const saving = ref(false);

const outletChoices = computed(() => outletStore.outlets.map((o) => ({ value: o.id, label: o.name })));
const multiOutlet = computed(() => outletStore.multi === true);
const moving = computed(() => !!editing.value && multiOutlet.value && form.outlet_id !== '' && Number(form.outlet_id) !== editing.value.outlet_id);

function clearErrors() {
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
}

function openCreate() {
  editing.value = null;
  const preset = outletStore.selected ?? (outletChoices.value.length === 1 ? outletChoices.value[0].value : '');
  Object.assign(form, { name: '', outlet_id: preset });
  clearErrors();
  showForm.value = true;
}

function openEdit(row) {
  editing.value = row;
  Object.assign(form, { name: row.name, outlet_id: row.outlet_id });
  clearErrors();
  showForm.value = true;
}

async function save() {
  clearErrors();
  if (multiOutlet.value && !editing.value && form.outlet_id === '') {
    formErrors.outlet_id = t('terminals.outlet_placeholder');
    return;
  }
  saving.value = true;
  try {
    if (editing.value) {
      const payload = { name: form.name };
      if (moving.value) payload.outlet_id = Number(form.outlet_id);
      await updateDevice(editing.value.id, payload);
      toast.success(t('terminals.updated'));
    } else {
      // Outlet lewat header (bukan body); instalasi satu outlet: server memakai satu-satunya outlet.
      await createDevice({ name: form.name }, multiOutlet.value ? Number(form.outlet_id) : null);
      toast.success(t('terminals.created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
    // 409 (shift terbuka, terminal dicabut) dan 403 sudah ditoast oleh penangan galat bersama.
  } finally {
    saving.value = false;
  }
}

// ---- nonaktif / aktif / hapus ----
const confirm = reactive({ kind: null, row: null, loading: false });
const confirmOpen = computed(() => confirm.kind !== null);

function ask(kind, row) {
  confirm.kind = kind;
  confirm.row = row;
}

async function runConfirm() {
  if (confirm.kind === 'bulk_deactivate' || confirm.kind === 'bulk_delete') {
    const action = confirm.kind === 'bulk_delete' ? 'delete' : 'deactivate';
    confirm.kind = null;
    await runBulk(action);
    return;
  }
  confirm.loading = true;
  try {
    if (confirm.kind === 'deactivate') {
      await deactivateDevice(confirm.row.id);
      toast.success(t('terminals.deactivated'));
    } else {
      await deleteDevicePermanently(confirm.row.id);
      toast.success(t('terminals.deleted'));
    }
    confirm.kind = null;
    await load();
  } catch {
    // 409 (mis. masih punya order/shift) sudah ditoast; dialog tetap terbuka supaya pengguna bisa memilih menonaktifkan.
  } finally {
    confirm.loading = false;
  }
}

// Aksi massal: tiap terminal berhasil atau ditolak sendiri-sendiri (server tidak semua-atau-tidak-sama-sekali). Yang
// ditolak tetap terpilih dan disebut dengan alasannya, supaya jelas mana yang belum berubah.
async function runBulk(action) {
  const ids = [...selected.value];
  if (!ids.length || bulkBusy.value) return;
  bulkBusy.value = true;
  bulkResult.value = null;
  try {
    const res = await bulkDevices(action, ids);
    const failed = res.data.filter((r) => r.status === 'refused' || r.status === 'not_found');
    const { changed, skipped, refused, not_found: notFound } = res.summary;
    await load();
    const onPage = new Set(items.value.map((r) => r.id));
    selected.value = failed.map((r) => r.id).filter((id) => onPage.has(id));
    bulkResult.value = failed.length
      ? { succeeded: changed, failed: failed.map((r) => ({ label: r.name ?? `#${r.id}`, message: r.reason ?? '' })) }
      : null;
    const summary = { changed, skipped, refused: refused + notFound };
    if (failed.length) toast.error(t('terminals.bulk_summary_refused', summary));
    else toast.success(t('terminals.bulk_summary', summary));
  } catch {
    // 403/422 sudah ditoast oleh penangan galat bersama; pilihan dibiarkan agar bisa dicoba lagi.
  } finally {
    bulkBusy.value = false;
  }
}

const bulkActions = computed(() => [
  { key: 'activate', label: t('terminals.bulk_activate') },
  { key: 'deactivate', label: t('terminals.bulk_deactivate') },
  { key: 'delete', label: t('terminals.bulk_delete'), danger: true },
]);
function onBulkAction(key) {
  if (key === 'activate') runBulk('activate');
  else confirm.kind = key === 'delete' ? 'bulk_delete' : 'bulk_deactivate';
}

async function reactivate(row) {
  try {
    await reactivateDevice(row.id);
    toast.success(t('terminals.reactivated'));
    await load();
  } catch (err) {
    if (err.isValidation) toast.error(Object.values(err.errors)[0]?.[0] ?? err.message);
  }
}

const isDeleteKind = computed(() => confirm.kind === 'delete' || confirm.kind === 'bulk_delete');
const isBulkKind = computed(() => confirm.kind === 'bulk_delete' || confirm.kind === 'bulk_deactivate');
const confirmTitle = computed(() => {
  if (isBulkKind.value) return t(isDeleteKind.value ? 'terminals.bulk_delete_title' : 'terminals.bulk_deactivate_title');
  return isDeleteKind.value ? t('terminals.delete_title') : t('terminals.deactivate_title');
});
const confirmMessage = computed(() => {
  if (isBulkKind.value) return t(isDeleteKind.value ? 'terminals.bulk_delete_confirm' : 'terminals.bulk_deactivate_confirm', { count: selected.value.length });
  return t(isDeleteKind.value ? 'terminals.delete_confirm' : 'terminals.deactivate_confirm', { name: confirm.row?.name });
});
const confirmLabel = computed(() => (isDeleteKind.value ? t('terminals.yes_delete') : t('terminals.yes_deactivate')));
</script>

<template>
  <div class="pb-10">
    <PageHeader :title="t('terminals.title')" :description="t('terminals.subtitle')">
      <template #actions>
        <BaseButton data-testid="add-terminal" @click="openCreate">
          <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
          {{ t('terminals.add') }}
        </BaseButton>
      </template>
    </PageHeader>

    <section class="mx-[26px] border-2 border-line bg-white">
      <FilterBar
        :search="search"
        :placeholder="t('terminals.search_placeholder')"
        :search-label="t('terminals.search_label')"
        :count="meta.total"
        :active="filtersActive"
        @update:search="onSearch"
        @reset="resetFilters"
      >
        <BaseMultiSelect
          v-if="outletFilter.visible.value"
          v-model="outletModel"
          :options="outletFilter.options.value"
          :all-label="t('terminals.filter_outlet_all')"
          class="w-60"
          data-testid="outlet-filter"
        />
        <BaseSelect v-model="status" :options="statusOptions" class="w-48" data-testid="status-filter" />
      </FilterBar>

      <BulkBar :count="selected.length" :actions="bulkActions" :result="bulkResult" :busy="bulkBusy" @action="onBulkAction" @clear="clearSelection" />

      <DataTable
        v-model:selected="selected"
        selectable
        :row-label="(row) => t('terminals.select_row', { name: row.name })"
        :columns="columns"
        :rows="items"
        :loading="loading"
        :error="error ? (error.message || t('page.load_failed')) : ''"
        :empty-message="filtersActive ? t('terminals.empty_filtered') : t('terminals.empty')"
        @retry="load"
      >
        <template #cell-name="{ row }"><span class="text-[13.5px] font-semibold">{{ row.name }}</span></template>
        <template #cell-outlet="{ row }">
          <span class="text-[12.5px] text-muted-4">
            <span class="font-mono font-bold text-brand-active">{{ row.outlet?.code }}</span>
            <template v-if="row.outlet?.name"> · {{ row.outlet.name }}</template>
          </span>
        </template>
        <template #cell-status="{ row }">
          <StatusPill :variant="row.revoked ? 'neutral' : 'mint'">{{ row.revoked ? t('terminals.status_revoked') : t('terminals.status_active') }}</StatusPill>
        </template>
        <template #cell-last_seen_at="{ row }">
          <span class="text-[12.5px] text-muted-4">{{ row.last_seen_at ? formatDateTime(row.last_seen_at) : t('terminals.never_seen') }}</span>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-3">
            <button v-if="!row.revoked" type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button v-if="row.revoked" type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" data-testid="reactivate" @click="reactivate(row)">{{ t('terminals.reactivate') }}</button>
            <button v-else type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" data-testid="deactivate" @click="ask('deactivate', row)">{{ t('terminals.deactivate') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text hover:text-danger-text" data-testid="delete" @click="ask('delete', row)">{{ t('terminals.delete') }}</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </section>

    <BaseModal :open="showForm" :title="editing ? t('terminals.edit_title') : t('terminals.new_title')" max-width-class="max-w-[460px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="save">
        <BaseSelect
          v-if="multiOutlet"
          v-model="form.outlet_id"
          :label="t('terminals.outlet_label')"
          :placeholder="t('terminals.outlet_placeholder')"
          :options="outletChoices"
          :error="formErrors.outlet_id"
          required
        />
        <p v-if="moving" class="border border-warn-border bg-warn-bg px-3 py-2 text-[12.5px] leading-relaxed text-warn-text" data-testid="move-warning">{{ t('terminals.move_warning') }}</p>
        <BaseInput v-model="form.name" :label="t('terminals.name_label')" required maxlength="80" :error="formErrors.name" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="confirmOpen"
      :title="confirmTitle"
      :message="confirmMessage"
      :confirm-label="confirmLabel"
      :loading="confirm.loading"
      @close="confirm.kind = null"
      @confirm="runConfirm"
    />
  </div>
</template>
