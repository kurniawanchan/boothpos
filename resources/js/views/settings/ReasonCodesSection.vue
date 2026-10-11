<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { listReasonCodes, createReasonCode, updateReasonCode } from '../../api/reasons';
import { useToastStore } from '../../stores/toast';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import DataTable from '../../components/ui/DataTable.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import TabBar from '../../components/page/TabBar.vue';
import SettingsSection from './SettingsSection.vue';

/**
 * 043 (T020) — daftar alasan yang dipilih pengguna saat menandai 86, mendiskontinu item, atau menolak persetujuan
 * harga. Satu daftar per kategori (tab). Kode TIDAK pernah dihapus — hanya dinonaktifkan — karena riwayat (86,
 * persetujuan) merujuknya; kategori dan kode juga tak bisa diubah setelah dibuat (server menolaknya), jadi pada
 * mode ubah keduanya hanya ditampilkan. Daftar dimuat dengan `include_inactive=1` supaya admin melihat alasan
 * yang sudah dinonaktifkan dan bisa mengaktifkannya lagi; dialog alasan di tempat lain hanya melihat yang aktif.
 * Halaman Pengaturan sudah digerbang menu `settings`; server menegakkannya lagi pada tulis.
 */
const { t } = useI18n();
const toast = useToastStore();

const CATEGORIES = ['sold_out', 'discontinue', 'approval_reject'];
const category = ref('sold_out');
const tabs = computed(() => CATEGORIES.map((key) => ({ key, label: t(`settings.reasons_cat_${key}`) })));

const rows = ref([]);
const loading = ref(true);
const loadFailed = ref(false);

async function load() {
  loading.value = true;
  loadFailed.value = false;
  try {
    rows.value = (await listReasonCodes({ category: category.value, include_inactive: 1 })).data ?? [];
  } catch {
    loadFailed.value = true;
  } finally {
    loading.value = false;
  }
}
onMounted(load);

function changeCategory(key) {
  category.value = key;
  closeForm();
  load();
}

const columns = computed(() => [
  { key: 'label_id', label: t('settings.reasons_col_label_id') },
  { key: 'label_en', label: t('settings.reasons_col_label_en') },
  { key: 'sort', label: t('settings.reasons_col_sort') },
  { key: 'is_active', label: t('settings.reasons_col_active') },
  { key: 'actions', label: '' },
]);

// --- tambah / ubah -----------------------------------------------------------------------------------------
const showForm = ref(false);
const editing = ref(null);
const form = reactive({ code: '', label_id: '', label_en: '', sort: '0' });
const errors = reactive({});
const saving = ref(false);
const clearErrors = () => Object.keys(errors).forEach((k) => delete errors[k]);

function openCreate() {
  editing.value = null;
  // Urutan awal = setelah yang terakhir, supaya alasan baru tidak menyalip daftar yang sudah disusun admin.
  const nextSort = rows.value.reduce((max, r) => Math.max(max, Number(r.sort) || 0), 0) + 10;
  Object.assign(form, { code: '', label_id: '', label_en: '', sort: String(nextSort) });
  clearErrors();
  showForm.value = true;
}

function openEdit(row) {
  editing.value = row;
  Object.assign(form, { code: row.code, label_id: row.label_id, label_en: row.label_en, sort: String(row.sort ?? 0) });
  clearErrors();
  showForm.value = true;
}

function closeForm() {
  showForm.value = false;
  editing.value = null;
  clearErrors();
}

function validate() {
  clearErrors();
  if (!editing.value && !/^[a-z0-9_]+$/.test(form.code)) errors.code = t('settings.reasons_invalid_code');
  if (!form.label_id.trim()) errors.label_id = t('settings.reasons_label_required');
  if (!form.label_en.trim()) errors.label_en = t('settings.reasons_label_required');
  const sort = Number(form.sort);
  if (form.sort === '' || !Number.isInteger(sort) || sort < 0) errors.sort = t('settings.reasons_invalid_sort');
  return Object.keys(errors).length === 0;
}

async function save() {
  if (!validate()) return;
  saving.value = true;
  try {
    const body = { label_id: form.label_id.trim(), label_en: form.label_en.trim(), sort: Number(form.sort) };
    if (editing.value) {
      await updateReasonCode(editing.value.id, body);
      toast.success(t('settings.reasons_updated'));
    } else {
      await createReasonCode({ category: category.value, code: form.code, ...body });
      toast.success(t('settings.reasons_created'));
    }
    closeForm();
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(errors, Object.fromEntries(Object.entries(err.errors ?? {}).map(([k, v]) => [k, v[0]])));
    else toast.error(err.message);
  } finally {
    saving.value = false;
  }
}

// --- aktif/nonaktif -----------------------------------------------------------------------------------------
const toggling = ref(null);
async function toggleActive(row) {
  toggling.value = row.id;
  try {
    await updateReasonCode(row.id, { is_active: !row.is_active });
    await load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    toggling.value = null;
  }
}
</script>

<template>
  <SettingsSection :title="t('settings.reasons_section_title')">
    <p class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.reasons_intro') }}</p>
    <TabBar :tabs="tabs" :model-value="category" :label="t('settings.reasons_tabs_aria')" class="-mx-5" @update:model-value="changeCategory" />

    <ErrorState v-if="loadFailed" @retry="load" />
    <template v-else>
      <div class="overflow-hidden border-2 border-line bg-white">
        <DataTable :columns="columns" :rows="rows" :loading="loading" :empty-message="t('settings.reasons_none')">
          <template #cell-label_id="{ row }">
            <div class="flex flex-col">
              <span class="font-semibold">{{ row.label_id }}</span>
              <span class="font-mono text-[11.5px] text-muted">{{ row.code }}</span>
            </div>
          </template>
          <template #cell-is_active="{ row }">
            <label class="flex cursor-pointer items-center gap-2 text-[12.5px] font-semibold" :class="row.is_active ? 'text-success-text' : 'text-muted'">
              <input
                type="checkbox"
                role="switch"
                class="h-4 w-4 cursor-pointer accent-brand"
                :checked="row.is_active"
                :disabled="toggling === row.id"
                :aria-label="t('settings.reasons_toggle', { name: row.label_id })"
                @change="toggleActive(row)"
              />
              {{ row.is_active ? t('common.active') : t('common.inactive') }}
            </label>
          </template>
          <template #cell-actions="{ row }">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" :aria-label="t('settings.reasons_edit_named', { name: row.label_id })" @click="openEdit(row)">{{ t('common.edit') }}</button>
          </template>
        </DataTable>
      </div>

      <BaseButton v-if="!showForm" size="sm" class="self-start" @click="openCreate">
        <i class="ph-duotone ph-plus text-[14px]" aria-hidden="true"></i>
        {{ t('settings.reasons_add') }}
      </BaseButton>

      <!-- novalidate: validasi diurus validate() supaya pesannya tampil di bawah field, bukan balon bawaan browser. -->
      <form v-else novalidate class="flex flex-col gap-3.5 border-2 border-line bg-surface-subtle p-4" data-testid="reason-form" @submit.prevent="save">
        <span class="text-[13.5px] font-bold">{{ editing ? t('settings.reasons_edit') : t('settings.reasons_add') }}</span>
        <BaseInput
          v-model="form.code"
          :label="t('settings.reasons_code')"
          maxlength="40"
          required
          :disabled="!!editing"
          :hint="editing ? t('master_data.permanent_after_creation') : t('settings.reasons_code_hint')"
          :error="errors.code"
        />
        <div class="grid grid-cols-2 gap-3">
          <BaseInput v-model="form.label_id" :label="t('settings.reasons_label_id')" maxlength="120" required :error="errors.label_id" />
          <BaseInput v-model="form.label_en" :label="t('settings.reasons_label_en')" maxlength="120" required :error="errors.label_en" />
        </div>
        <BaseInput v-model="form.sort" type="number" min="0" step="1" :label="t('settings.reasons_sort')" :hint="t('settings.reasons_sort_hint')" :error="errors.sort" />
        <div class="flex gap-2.5">
          <BaseButton type="submit" size="sm" :loading="saving">{{ t('common.save') }}</BaseButton>
          <BaseButton type="button" size="sm" variant="secondary" @click="closeForm">{{ t('common.cancel') }}</BaseButton>
        </div>
      </form>
    </template>
  </SettingsSection>
</template>
