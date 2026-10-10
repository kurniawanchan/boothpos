<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { listModifierGroups, createModifierGroup, updateModifierGroup, deleteModifierGroup, setModifierGroupAttachments } from '../../api/modifierGroups';
import { listProducts } from '../../api/products';
import { listCategories } from '../../api/categories';
import { useToastStore } from '../../stores/toast';
import { describeRange, groupRange } from '../../utils/modifierRules';
import { formatIDR } from '../../utils/money';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import BaseMultiSelect from '../ui/BaseMultiSelect.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';

/**
 * 028 US3 — tab "Modifier" pada Pengaturan Restoran: grup + opsi (selisih harga bertanda)
 * dan lampirannya ke produk/kategori. Aturan turunan (mis. pilihan tunggal ⇒ maks 1)
 * dinormalisasi server; di sini form hanya menyembunyikan field yang tidak relevan.
 * Galat 409 (hapus grup yang pernah dipakai) sudah ditoast global.
 */
const { t } = useI18n();
const toast = useToastStore();

const groups = ref([]);
const loading = ref(false);
const products = ref([]);
const categories = ref([]);

async function load() {
  loading.value = true;
  try {
    groups.value = (await listModifierGroups()).data;
  } finally {
    loading.value = false;
  }
}
onMounted(async () => {
  await load();
  // Pilihan untuk dialog lampiran; dimuat sekali.
  products.value = (await listProducts({ per_page: 100 })).data;
  categories.value = (await listCategories({ per_page: 100 })).data;
});

const columns = computed(() => [
  { key: 'name', label: t('dining_setup.mod_col_name') },
  { key: 'rule', label: t('dining_setup.mod_col_rule') },
  { key: 'options', label: t('dining_setup.mod_col_options') },
  { key: 'attached', label: t('dining_setup.mod_col_attached') },
  { key: 'is_active', label: t('dining_setup.col_status') },
  { key: 'actions', label: '' },
]);

const ruleText = (g) => `${g.selection_type === 'single' ? t('dining_setup.mod_single') : t('dining_setup.mod_multiple')} · ${g.is_required ? t('floor.tab.mod_required') : t('floor.tab.mod_optional')} · ${describeRange(groupRange(g))}`;

// --- form --------------------------------------------------------------------
const showForm = ref(false);
const editing = ref(null);
const form = reactive({ name: '', selection_type: 'single', is_required: false, min_select: 0, max_select: '', is_active: true, options: [] });
const formErrors = reactive({});
const saving = ref(false);

const blankOption = () => ({ id: null, name: '', price_delta: '0', is_active: true });
const clearErrors = () => Object.keys(formErrors).forEach((k) => delete formErrors[k]);

function openCreate() {
  editing.value = null;
  Object.assign(form, { name: '', selection_type: 'single', is_required: false, min_select: 0, max_select: '', is_active: true, options: [blankOption()] });
  clearErrors();
  showForm.value = true;
}

function openEdit(group) {
  editing.value = group;
  Object.assign(form, {
    name: group.name, selection_type: group.selection_type, is_required: group.is_required,
    min_select: group.min_select, max_select: group.max_select ?? '', is_active: group.is_active,
    options: group.options.filter((o) => o.is_active).map((o) => ({ id: o.id, name: o.name, price_delta: o.price_delta, is_active: true })),
  });
  clearErrors();
  showForm.value = true;
}

const removeOption = (idx) => form.options.splice(idx, 1);

function payload() {
  const multiple = form.selection_type === 'multiple';
  return {
    name: form.name,
    selection_type: form.selection_type,
    is_required: form.is_required,
    ...(multiple ? { min_select: Number(form.min_select) || 0, max_select: form.max_select === '' ? null : Number(form.max_select) } : {}),
    is_active: form.is_active,
    options: form.options.filter((o) => o.name.trim() !== '').map((o, i) => ({
      ...(o.id ? { id: o.id } : {}), name: o.name, price_delta: Number(o.price_delta) || 0, display_order: i,
    })),
  };
}

async function save() {
  saving.value = true;
  clearErrors();
  try {
    if (editing.value) {
      await updateModifierGroup(editing.value.id, payload());
      toast.success(t('dining_setup.mod_updated'));
    } else {
      await createModifierGroup(payload());
      toast.success(t('dining_setup.mod_created'));
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

// --- lampiran -----------------------------------------------------------------
const attachGroup = ref(null);
const attachProducts = ref([]);
const attachCategories = ref([]);
const attaching = ref(false);

function openAttach(group) {
  attachGroup.value = group;
  attachProducts.value = [...group.product_ids];
  attachCategories.value = [...group.category_ids];
}

async function saveAttachments() {
  attaching.value = true;
  try {
    await setModifierGroupAttachments(attachGroup.value.id, { product_ids: attachProducts.value, category_ids: attachCategories.value });
    toast.success(t('dining_setup.mod_attached'));
    attachGroup.value = null;
    await load();
  } catch {
    // 422 (mis. grup belum punya opsi aktif) sudah ditoast global.
  } finally {
    attaching.value = false;
  }
}

// --- hapus ----------------------------------------------------------------------
const deleteTarget = ref(null);
const deleting = ref(false);
async function performDelete() {
  deleting.value = true;
  try {
    await deleteModifierGroup(deleteTarget.value.id);
    toast.success(t('dining_setup.mod_deleted'));
    await load();
  } catch {
    // 409 (grup pernah dipakai) sudah ditoast global.
  } finally {
    deleteTarget.value = null;
    deleting.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5">
    <div class="flex justify-end">
      <BaseButton @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        {{ t('dining_setup.mod_new') }}
      </BaseButton>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="groups" :loading="loading" :empty-message="t('dining_setup.mod_none')">
        <template #cell-name="{ row }"><span class="font-bold text-ink">{{ row.name }}</span></template>
        <template #cell-rule="{ row }"><span class="text-[12.5px] text-muted-4">{{ ruleText(row) }}</span></template>
        <template #cell-options="{ row }">
          <span class="text-[12.5px] text-muted-4">{{ row.options.filter((o) => o.is_active).map((o) => (Number(o.price_delta) ? `${o.name} (${Number(o.price_delta) > 0 ? '+' : '−'}${formatIDR(Math.abs(Number(o.price_delta)))})` : o.name)).join(', ') }}</span>
        </template>
        <template #cell-attached="{ row }">{{ t('dining_setup.mod_attached_count', { products: row.product_ids.length, categories: row.category_ids.length }) }}</template>
        <template #cell-is_active="{ row }"><StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</StatusPill></template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openAttach(row)">{{ t('dining_setup.mod_attach') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text" @click="deleteTarget = row">{{ t('common.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal :open="showForm" :title="editing ? t('dining_setup.mod_edit') : t('dining_setup.mod_new')" max-width-class="max-w-[560px]" @close="showForm = false">
      <form class="flex max-h-[70vh] flex-col gap-3.5 overflow-auto px-6 py-5" @submit.prevent="save">
        <BaseInput v-model="form.name" :label="t('dining_setup.mod_name')" required maxlength="100" :error="formErrors.name" />
        <BaseSelect
          v-model="form.selection_type"
          :label="t('dining_setup.mod_type')"
          :options="[{ value: 'single', label: t('dining_setup.mod_single') }, { value: 'multiple', label: t('dining_setup.mod_multiple') }]"
          :error="formErrors.selection_type"
        />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_required" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          {{ t('dining_setup.mod_required') }}
        </label>
        <div v-if="form.selection_type === 'multiple'" class="grid grid-cols-2 gap-2.5">
          <BaseInput v-model="form.min_select" type="number" min="0" :label="t('dining_setup.mod_min')" :error="formErrors.min_select" />
          <BaseInput v-model="form.max_select" type="number" min="1" :label="t('dining_setup.mod_max')" :hint="t('dining_setup.mod_max_hint')" :error="formErrors.max_select" />
        </div>
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          {{ t('common.active') }}
        </label>

        <div class="flex flex-col gap-2">
          <span class="text-[12.5px] font-bold text-muted-4">{{ t('dining_setup.mod_options') }}</span>
          <p v-if="formErrors.options" class="text-[12px] font-semibold text-danger-text">{{ formErrors.options }}</p>
          <div v-for="(o, idx) in form.options" :key="o.id ?? `new-${idx}`" class="grid grid-cols-[1fr_130px_auto] items-end gap-2" data-testid="option-row">
            <BaseInput v-model="o.name" :label="idx === 0 ? t('dining_setup.mod_option_name') : ''" :aria-label="t('dining_setup.mod_option_name')" maxlength="100" :error="formErrors[`options.${idx}.name`]" />
            <BaseInput v-model="o.price_delta" type="number" :label="idx === 0 ? t('dining_setup.mod_option_price') : ''" :aria-label="t('dining_setup.mod_option_price')" :error="formErrors[`options.${idx}.price_delta`]" />
            <button type="button" class="mb-0.5 flex h-[42px] w-[42px] items-center justify-center rounded-lg border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('common.delete')" @click="removeOption(idx)">
              <i class="ph-duotone ph-trash text-[15px]" aria-hidden="true"></i>
            </button>
          </div>
          <BaseButton variant="secondary" size="sm" class="self-start" @click="form.options.push(blankOption())">{{ t('dining_setup.mod_add_option') }}</BaseButton>
          <p class="text-[11.5px] text-muted-3">{{ t('dining_setup.mod_options_hint') }}</p>
        </div>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <BaseModal :open="!!attachGroup" :title="t('dining_setup.mod_attach_title', { name: attachGroup?.name })" max-width-class="max-w-[520px]" @close="attachGroup = null">
      <div class="flex flex-col gap-4 px-6 py-5">
        <p class="text-[12.5px] text-muted-3">{{ t('dining_setup.mod_attach_hint') }}</p>
        <BaseMultiSelect v-model="attachCategories" :label="t('dining_setup.mod_attach_categories')" :options="categories.map((c) => ({ value: c.id, label: c.name }))" :placeholder="t('dining_setup.mod_attach_none')" />
        <BaseMultiSelect v-model="attachProducts" :label="t('dining_setup.mod_attach_products')" :options="products.map((p) => ({ value: p.id, label: p.name }))" :placeholder="t('dining_setup.mod_attach_none')" />
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="attachGroup = null">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="attaching" @click="saveAttachments">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="!!deleteTarget"
      :title="t('dining_setup.mod_delete')"
      :message="t('dining_setup.mod_delete_confirm', { name: deleteTarget?.name })"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @close="deleteTarget = null"
      @confirm="performDelete"
    />
  </div>
</template>
