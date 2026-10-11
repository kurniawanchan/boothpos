<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { saveModifierLinks } from '../../api/products';
import { listModifierGroups } from '../../api/modifierGroups';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import { modifierRuleText } from '../../utils/itemDetail';
import FormDrawer from '../page/FormDrawer.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';

/**
 * 043 (US7) — laci "Grup modifier" halaman item: menautkan grup modifier ke item ini dengan aturan PER ITEM
 * (wajib, minimum, maksimum, urutan). `PUT /products/{id}/modifier-links` MENGGANTI seluruh tautan item itu, jadi
 * laci mengirim semua baris tautan produk, bukan hanya yang berubah.
 *
 * - Isi grup (opsi, harga) hanya dibaca: diubah di layar Setup restoran. Laci ini tidak menyentuhnya.
 * - Baris yang datang dari KATEGORI (`source: 'category'`) hanya ditampilkan: itu cadangan di server dan bukan
 *   tautan produk, jadi tidak ikut dikirim (mengirimnya akan mengubahnya diam-diam menjadi tautan produk).
 * - Validasi klien mencerminkan server (0 ≤ min ≤ max; maks kosong = tanpa batas atau ≥ 1). Server tetap yang
 *   memutuskan; 422 per kolom (`links.N.max_select`, …) ditampilkan di baris yang bersangkutan.
 * - Grup PILIHAN TUNGGAL: server memakai aturan tautan apa adanya, sedangkan dialog POS menampilkan grup tunggal
 *   sebagai radio (satu pilihan). Maksimum > 1 pada grup tunggal akan membuat grup wajib tak mungkin terpenuhi di
 *   POS, jadi laci menahannya di maks 1 / min ≤ 1.
 * - Kolom angka tanpa atribut `min`/`max`: atribut itu memicu validasi bawaan browser yang menahan submit tanpa pesan
 *   kita; aturannya dicek `validate()` per baris.
 * - Daftar grup (`GET /modifier-groups`) hanya untuk pemegang menu setup restoran. Bila gagal dimuat, laci tetap
 *   bisa mengubah/melepas tautan yang ada; hanya "tautkan grup baru" yang tidak tersedia (dengan Coba lagi).
 * - Bila rilis menu aktif server menjawab `{ applied: false }` (perubahan diantrekan): laci memberi tahu
 *   "menunggu rilis" alih-alih seolah sudah berlaku.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  product: { type: Object, default: null }, // { id, name }
  links: { type: Array, default: () => [] }, // modifier_links dari GET /products/{id}
});
const emit = defineEmits(['close', 'saved']);
const { t } = useI18n();
const settings = useSettingsStore();
const toast = useToastStore();

const releaseOn = computed(() => settings.menuReleaseEnabled === true);
const submitLabel = computed(() => (releaseOn.value ? t('products.submit_request') : t('products.ml_save')));

const rows = ref([]); // tautan PRODUK yang dapat diubah
const rowErrors = reactive({}); // { [index]: { min, max } }
const alertMessage = ref('');
const submitting = ref(false);
const addValue = ref('');

const groups = ref([]);
const groupsState = ref('idle'); // idle | loading | ready | error
let groupsSeq = 0;

const inherited = computed(() => (props.links ?? []).filter((l) => l.source === 'category'));
const groupById = computed(() => new Map(groups.value.map((g) => [g.id, g])));
const isSingle = (groupId) => groupById.value.get(groupId)?.selection_type === 'single';

const addOptions = computed(() => {
  const linked = new Set(rows.value.map((r) => r.group_id));
  return groups.value.filter((g) => g.is_active !== false && !linked.has(g.id)).map((g) => ({ value: g.id, label: g.name }));
});

function resetForm() {
  rows.value = (props.links ?? [])
    .filter((l) => l.source !== 'category')
    .map((l) => ({
      group_id: l.group_id,
      name: l.name,
      is_required: !!l.is_required,
      min: String(l.min_select ?? 0),
      max: l.max_select === null || l.max_select === undefined ? '' : String(l.max_select),
    }));
  Object.keys(rowErrors).forEach((k) => delete rowErrors[k]);
  alertMessage.value = '';
  addValue.value = '';
}

async function loadGroups() {
  const mine = ++groupsSeq;
  groupsState.value = 'loading';
  try {
    const res = await listModifierGroups();
    if (mine !== groupsSeq) return;
    groups.value = res?.data ?? [];
    groupsState.value = 'ready';
  } catch {
    if (mine !== groupsSeq) return;
    groupsState.value = 'error';
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    resetForm();
    loadGroups();
  },
  { immediate: true },
);

// Menautkan grup: nilai awal diambil dari aturan grup itu sendiri; untuk grup tunggal selalu 0/1–1 (lihat docblock).
watch(addValue, (id) => {
  if (id === '' || id === null || id === undefined) return;
  const g = groupById.value.get(id);
  if (g) {
    const single = g.selection_type === 'single';
    const required = !!g.is_required;
    rows.value.push({
      group_id: g.id,
      name: g.name,
      is_required: required,
      min: single ? (required ? '1' : '0') : String(g.min_select ?? 0),
      max: single ? '1' : g.max_select === null || g.max_select === undefined ? '' : String(g.max_select),
    });
  }
  addValue.value = '';
});

function setRequired(row, value) {
  row.is_required = value;
  // Server mengangkat minimum wajib ke ≥ 1; menampilkannya apa adanya mencegah angka di layar berbeda dari yang berlaku.
  if (value && (row.min === '' || Number(row.min) < 1)) row.min = '1';
}

function move(index, delta) {
  const target = index + delta;
  if (target < 0 || target >= rows.value.length) return;
  const copy = [...rows.value];
  [copy[index], copy[target]] = [copy[target], copy[index]];
  rows.value = copy;
  // Galat melekat pada indeks; setelah urutan berubah posisinya tidak lagi cocok, jadi dikosongkan.
  Object.keys(rowErrors).forEach((k) => delete rowErrors[k]);
}

function remove(index) {
  rows.value.splice(index, 1);
  Object.keys(rowErrors).forEach((k) => delete rowErrors[k]);
}

const isWhole = (s) => /^\d+$/.test(String(s).trim());

function validate() {
  Object.keys(rowErrors).forEach((k) => delete rowErrors[k]);
  let ok = true;
  rows.value.forEach((r, i) => {
    const e = {};
    const minOk = isWhole(r.min);
    const min = minOk ? Number(r.min) : NaN;
    const maxEmpty = String(r.max).trim() === '';
    const maxOk = maxEmpty || (isWhole(r.max) && Number(r.max) >= 1);
    if (!minOk) e.min = t('products.ml_err_min');
    if (!maxOk || (!maxEmpty && minOk && Number(r.max) < min)) e.max = t('products.ml_err_max');
    if (isSingle(r.group_id) && !e.min && !e.max && (min > 1 || maxEmpty || Number(r.max) > 1)) e.max = t('products.ml_err_single');
    if (e.min || e.max) {
      rowErrors[i] = e;
      ok = false;
    }
  });
  return ok;
}

function applyServerErrors(errors) {
  Object.entries(errors ?? {}).forEach(([field, messages]) => {
    const message = Array.isArray(messages) ? messages[0] : messages;
    const m = /^links\.(\d+)\.(group_id|min_select|max_select)$/.exec(field);
    if (m) {
      const i = Number(m[1]);
      const column = m[2] === 'min_select' ? 'min' : 'max';
      rowErrors[i] = { ...(rowErrors[i] ?? {}), [column]: message };
    } else {
      alertMessage.value = message;
    }
  });
}

async function submit() {
  alertMessage.value = '';
  if (!validate()) return;
  const payload = rows.value.map((r, i) => ({
    group_id: r.group_id,
    is_required: r.is_required,
    min_select: Number(r.min),
    max_select: String(r.max).trim() === '' ? null : Number(r.max),
    display_order: i + 1,
  }));

  submitting.value = true;
  try {
    const result = await saveModifierLinks(props.product.id, payload);
    toast.success(result?.applied === false ? t('products.ml_pending') : t('products.ml_saved'));
    emit('saved', result);
  } catch (err) {
    if (err?.isValidation) applyServerErrors(err.errors);
    else if (err?.status !== 403) alertMessage.value = err?.message || t('products.ml_failed');
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <FormDrawer
    :open="open"
    :title="t('products.ml_title')"
    :subtitle="product?.name ?? ''"
    :submit-label="submitLabel"
    :submitting="submitting"
    max-width-class="max-w-[760px]"
    @close="emit('close')"
    @submit="submit"
  >
    <div class="flex flex-col gap-4">
      <p class="text-[12.5px] leading-relaxed text-muted-4" data-testid="ml-hint">{{ t('products.ml_hint') }}</p>
      <p v-if="alertMessage" role="alert" class="border-2 border-danger-border bg-danger-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-danger-text" data-testid="ml-alert">{{ alertMessage }}</p>

      <p v-if="!rows.length" class="border-2 border-line px-4 py-5 text-[13px] text-muted-4" data-testid="ml-empty">{{ t('products.ml_empty') }}</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full border-collapse text-left text-[13px]">
          <thead>
            <tr class="border-b-2 border-line text-[11.5px] font-bold uppercase tracking-wide text-muted">
              <th scope="col" class="py-2.5 pr-3">{{ t('products.col_group') }}</th>
              <th scope="col" class="px-3 py-2.5 text-center">{{ t('products.ml_col_required') }}</th>
              <th scope="col" class="px-3 py-2.5">{{ t('products.ml_col_min') }}</th>
              <th scope="col" class="px-3 py-2.5">{{ t('products.ml_col_max') }}</th>
              <th scope="col" aria-hidden="true" class="w-[120px] py-2.5 pl-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="r.group_id" class="border-b border-line-2 align-top last:border-b-0" data-testid="ml-row">
              <td class="py-3 pr-3 pt-[22px] font-semibold">
                {{ r.name }}
                <span v-if="isSingle(r.group_id)" class="ml-1 text-[11.5px] font-normal text-muted-4">· {{ t('products.ml_single_note') }}</span>
              </td>
              <td class="px-3 py-3 pt-[22px] text-center">
                <input
                  type="checkbox"
                  class="h-4 w-4 accent-brand"
                  :checked="r.is_required"
                  :aria-label="t('products.ml_required_aria', { name: r.name })"
                  @change="setRequired(r, $event.target.checked)"
                />
              </td>
              <td class="w-[88px] px-3 py-3">
                <BaseInput v-model="r.min" type="number" step="1" :aria-label="t('products.ml_min_aria', { name: r.name })" :error="rowErrors[i]?.min" />
              </td>
              <td class="w-[150px] px-3 py-3">
                <BaseInput
                  v-model="r.max"
                  type="number"
                  step="1"
                  :placeholder="t('products.ml_unlimited')"
                  :aria-label="t('products.ml_max_aria', { name: r.name })"
                  :error="rowErrors[i]?.max"
                />
              </td>
              <td class="py-3 pl-3 pt-[14px]">
                <div class="flex justify-end gap-1">
                  <BaseButton variant="plain" size="icon" :aria-label="t('products.ml_move_up', { name: r.name })" @click="move(i, -1)">
                    <i class="ph-duotone ph-arrow-up text-[16px]" aria-hidden="true"></i>
                  </BaseButton>
                  <BaseButton variant="plain" size="icon" :aria-label="t('products.ml_move_down', { name: r.name })" @click="move(i, 1)">
                    <i class="ph-duotone ph-arrow-down text-[16px]" aria-hidden="true"></i>
                  </BaseButton>
                  <BaseButton variant="plain" size="icon" :aria-label="t('products.ml_remove', { name: r.name })" @click="remove(i)">
                    <i class="ph-duotone ph-x text-[16px]" aria-hidden="true"></i>
                  </BaseButton>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex flex-col gap-2" data-testid="ml-add">
        <p v-if="groupsState === 'loading'" class="text-[12.5px] text-muted-4" role="status">{{ t('common.loading_data') }}</p>
        <div v-else-if="groupsState === 'error'" class="flex flex-wrap items-center gap-3 border-2 border-warn-border bg-warn-bg px-3.5 py-2.5 text-[12.5px] text-warn-text" role="alert" data-testid="ml-groups-error">
          <span class="flex-1">{{ t('products.ml_groups_failed') }}</span>
          <BaseButton variant="secondary" size="sm" @click="loadGroups">{{ t('common.retry') }}</BaseButton>
        </div>
        <template v-else-if="groupsState === 'ready'">
          <div v-if="addOptions.length" class="max-w-[320px]">
            <BaseSelect v-model="addValue" searchable :label="t('products.ml_add_label')" :placeholder="t('products.ml_add_placeholder')" :options="addOptions" />
          </div>
          <p v-else class="text-[12.5px] text-muted-4">{{ t('products.ml_none_available') }}</p>
        </template>
      </div>

      <section v-if="inherited.length" class="flex flex-col gap-2 border-2 border-line bg-surface-subtle px-4 py-3" data-testid="ml-inherited">
        <h3 class="text-[11.5px] font-bold uppercase tracking-wider text-muted">{{ t('products.ml_from_category') }}</h3>
        <ul class="flex flex-col gap-1 text-[12.5px]">
          <li v-for="l in inherited" :key="l.group_id" class="flex justify-between gap-3">
            <span class="font-semibold">{{ l.name }}</span>
            <span class="text-muted-4">{{ modifierRuleText(l, t) }}</span>
          </li>
        </ul>
      </section>
    </div>
  </FormDrawer>
</template>
