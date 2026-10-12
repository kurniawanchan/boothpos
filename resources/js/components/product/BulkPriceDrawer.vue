<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { previewPriceChange, applyPriceChange } from '../../api/menu';
import { useSettingsStore } from '../../stores/settings';
import { useOutletStore } from '../../stores/outlet';
import { useToastStore } from '../../stores/toast';
import { toLocalDateKey } from '../../utils/date';
import { formatIDR } from '../../utils/formatters';
import FormDrawer from '../page/FormDrawer.vue';
import FormSection from '../page/FormSection.vue';
import BaseInput from '../ui/BaseInput.vue';
import LoadingState from '../ui/LoadingState.vue';
import ErrorState from '../ui/ErrorState.vue';

/**
 * 043 (US5, T057) — drawer "Ubah harga massal": Cakupan (kategori) -> Perubahan (jenis, nilai, pembulatan, tanggal
 * berlaku) -> pratinjau lama -> baru per varian -> terapkan.
 *
 * Kebenaran ada di server: pratinjau dan penerapan memakai satu validasi (BulkPriceService) dan penerapan SELALU
 * menghitung ulang dari masukan; tabel di sini hanya cermin pratinjau server, tidak pernah dikirim balik sebagai
 * harga. Galat 422 per kolom (nilai nol, tanggal lampau, harga hasil <= 0, cakupan kosong) ditampilkan di kolomnya.
 *
 * Pratinjau ditunda ~400 ms setelah masukan terakhir dan respons permintaan yang sudah tersusul diabaikan, supaya
 * mengetik "1", "12", "125" tidak menimpa hasil terbaru dengan hasil lama. Label tombol kirim mengikuti mode:
 * rilis menu aktif -> "Ajukan perubahan" (perubahan menunggu rilis/persetujuan), mati -> "Terapkan harga".
 *
 * Cakupan server adalah KATEGORI (beserta turunannya), bukan item. Bila dibuka dari baris terpilih, `fromSelection`
 * memunculkan catatan jujur bahwa seluruh item di kategori itu ikut berubah.
 *
 * 045 Stage 3: dengan lebih dari satu outlet terlihat muncul bagian "Harga yang diubah" — harga dasar (bawaan),
 * semua outlet, atau outlet terpilih. Saat rilis menu aktif harga outlet
 * menjadi perubahan tertunda seperti harga dasar; instalasi satu outlet tidak melihat bagian ini sama sekali.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  categories: { type: Array, default: () => [] }, // [{ id, name }]
  initialCategoryIds: { type: Array, default: () => [] },
  fromSelection: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'saved']);
const { t } = useI18n();
const settings = useSettingsStore();
const toast = useToastStore();
const outletStore = useOutletStore();

const PREVIEW_DELAY = 400;

const form = reactive({ categoryIds: [], type: 'percent', value: '', rounding: '500', effectiveFrom: '', priceScope: 'base', outletIds: [] });
const fieldErrors = ref({});
const preview = ref(null); // { items, count, warnings } dari server
const previewState = ref('idle'); // idle | loading | ready | error
const submitting = ref(false);
const blockedHint = ref(false);
let seq = 0;
let timer = null;

const releaseMode = computed(() => settings.menuReleaseEnabled);
const showOutletScope = computed(() => outletStore.multi);
const submitLabel = computed(() => (releaseMode.value ? t('products.bulk_submit_release') : t('products.bulk_submit_apply')));

const valueNumber = computed(() => (form.value === '' || form.value === null ? NaN : Number(form.value)));
// Masukan yang cukup untuk meminta pratinjau: ada kategori dan nilai angka bukan nol. Selebihnya (tanggal lampau,
// harga <= 0) sengaja diserahkan ke server supaya aturannya tidak ditulis dua kali.
const scopeComplete = computed(() => !showOutletScope.value || form.priceScope !== 'outlets' || form.outletIds.length > 0);
const inputComplete = computed(() => scopeComplete.value && form.categoryIds.length > 0 && Number.isFinite(valueNumber.value) && valueNumber.value !== 0 && !!form.effectiveFrom);

function payload() {
  return {
    category_ids: form.categoryIds,
    type: form.type,
    value: valueNumber.value,
    rounding: form.rounding,
    effective_from: form.effectiveFrom,
    ...(showOutletScope.value && form.priceScope !== 'base'
      ? { price_scope: form.priceScope, ...(form.priceScope === 'outlets' ? { outlet_ids: form.outletIds } : {}) }
      : {}),
  };
}

function setErrors(err) {
  const out = {};
  Object.entries(err?.errors ?? {}).forEach(([field, messages]) => {
    out[field] = Array.isArray(messages) ? messages : [messages];
  });
  fieldErrors.value = out;
}
const errorOf = (field) => (fieldErrors.value[field] ?? [])[0] ?? '';
// Galat "value" bisa berisi banyak pesan (satu per varian yang harganya jatuh <= 0): semuanya ditampilkan.
const valueErrors = computed(() => fieldErrors.value.value ?? []);

async function runPreview() {
  const mine = ++seq;
  previewState.value = 'loading';
  try {
    const res = await previewPriceChange(payload());
    if (mine !== seq) return; // sudah tersusul permintaan yang lebih baru
    preview.value = res;
    fieldErrors.value = {};
    previewState.value = 'ready';
  } catch (err) {
    if (mine !== seq) return;
    preview.value = null;
    if (err?.isValidation) {
      setErrors(err);
      previewState.value = 'idle';
    } else {
      previewState.value = 'error';
    }
  }
}

function schedulePreview() {
  clearTimeout(timer);
  seq++; // respons yang masih terbang untuk masukan lama tidak boleh menimpa
  blockedHint.value = false;
  if (!props.open || !inputComplete.value) {
    preview.value = null;
    previewState.value = 'idle';
    fieldErrors.value = {};
    return;
  }
  previewState.value = 'loading';
  timer = setTimeout(runPreview, PREVIEW_DELAY);
}

watch(() => [form.categoryIds.slice(), form.type, form.value, form.rounding, form.effectiveFrom, form.priceScope, form.outletIds.slice()], schedulePreview);

// Setiap pembukaan memulai dari keadaan bersih; cakupan awal berasal dari pemanggil (filter kategori / baris terpilih).
watch(
  () => props.open,
  (isOpen) => {
    clearTimeout(timer);
    seq++;
    if (!isOpen) return;
    form.categoryIds = [...props.initialCategoryIds];
    form.type = 'percent';
    form.value = '';
    form.rounding = settings.priceRoundingDefault === 'none' ? 'none' : '500';
    form.effectiveFrom = toLocalDateKey(new Date());
    form.priceScope = 'base';
    form.outletIds = [];
    fieldErrors.value = {};
    preview.value = null;
    previewState.value = 'idle';
    blockedHint.value = false;
    submitting.value = false;
    if (inputComplete.value) schedulePreview();
  },
  { immediate: true }
);

const allSelected = computed(() => props.categories.length > 0 && form.categoryIds.length === props.categories.length);
function toggleCategory(id, checked) {
  const set = new Set(form.categoryIds);
  if (checked) set.add(id);
  else set.delete(id);
  form.categoryIds = props.categories.map((c) => c.id).filter((x) => set.has(x));
}
function toggleAll(checked) {
  form.categoryIds = checked ? props.categories.map((c) => c.id) : [];
}

function toggleOutlet(id, checked) {
  const set = new Set(form.outletIds);
  if (checked) set.add(id);
  else set.delete(id);
  form.outletIds = outletStore.outlets.map((o) => o.id).filter((x) => set.has(x));
}

const hasPreview = computed(() => previewState.value === 'ready' && preview.value?.count > 0);

async function submit() {
  if (submitting.value) return;
  // Tombol kirim di footer FormDrawer tidak bisa dinonaktifkan; penjaganya di sini. Pratinjau yang belum selesai
  // tidak memblokir: server menghitung ulang dan memvalidasi sendiri.
  const blocked = !inputComplete.value || (previewState.value === 'ready' && !hasPreview.value);
  if (blocked) {
    blockedHint.value = true;
    return;
  }
  blockedHint.value = false;
  clearTimeout(timer);
  submitting.value = true;
  try {
    const result = await applyPriceChange(payload());
    const parts = [];
    if (result.applied) parts.push(t('products.bulk_done_applied', { count: result.applied }));
    if (result.pending) parts.push(t('products.bulk_done_pending', { count: result.pending }));
    if (result.awaiting_approval) parts.push(t('products.bulk_done_awaiting', { count: result.awaiting_approval }));
    toast.success(parts.join(' · ') || t('products.bulk_done_applied', { count: 0 }));
    emit('saved', result);
    emit('close');
  } catch (err) {
    // 409 sudah di-toast handler pusat; 422 ditampilkan di kolomnya.
    if (err?.isValidation) setErrors(err);
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <FormDrawer
    :open="open"
    :title="t('products.bulk_title')"
    :subtitle="t('products.bulk_subtitle')"
    :submit-label="submitLabel"
    :submitting="submitting"
    max-width-class="max-w-[720px]"
    @close="emit('close')"
    @submit="submit"
  >
    <FormSection :title="t('products.bulk_scope')" :description="t('products.bulk_scope_desc')">
      <p v-if="fromSelection" class="border-2 border-line bg-surface-subtle px-3 py-2 text-[12px] leading-relaxed text-muted-4" data-testid="bulk-selection-note">
        {{ t('products.bulk_scope_from_selection') }}
      </p>
      <fieldset class="flex flex-col gap-2" :aria-describedby="errorOf('category_ids') ? 'bulk-scope-error' : undefined">
        <legend class="mb-1.5 text-[12.5px] font-semibold text-muted-4">{{ t('products.bulk_categories') }}</legend>
        <p v-if="!categories.length" class="text-[12.5px] text-muted-3">{{ t('products.bulk_categories_none') }}</p>
        <template v-else>
          <label class="flex cursor-pointer items-center gap-2.5 text-[13px] font-semibold">
            <input type="checkbox" class="h-4 w-4 accent-brand" :checked="allSelected" @change="toggleAll($event.target.checked)" />
            {{ t('products.bulk_select_all') }}
          </label>
          <div class="max-h-[200px] overflow-y-auto overscroll-contain border-2 border-line bg-white" data-testid="bulk-categories">
            <label v-for="c in categories" :key="c.id" class="flex cursor-pointer items-center gap-2.5 border-b border-line-5 px-3 py-2 text-[13px] last:border-b-0 hover:bg-line-7">
              <input type="checkbox" class="h-4 w-4 accent-brand" :checked="form.categoryIds.includes(c.id)" @change="toggleCategory(c.id, $event.target.checked)" />
              {{ c.name }}
            </label>
          </div>
        </template>
        <p v-if="errorOf('category_ids')" id="bulk-scope-error" role="alert" class="text-[12px] font-semibold text-danger-text">{{ errorOf('category_ids') }}</p>
      </fieldset>
    </FormSection>

    <FormSection :title="t('products.bulk_change')">
      <div role="radiogroup" :aria-label="t('products.bulk_type')" class="flex gap-0 self-start border-2 border-line">
        <button
          v-for="opt in ['percent', 'amount']"
          :key="opt"
          type="button"
          role="radio"
          :aria-checked="form.type === opt"
          class="px-4 py-2 text-[12.5px] font-bold transition-colors"
          :class="form.type === opt ? 'bg-brand text-white' : 'bg-white text-muted-5 hover:text-brand-active'"
          @click="form.type = opt"
        >
          {{ t(`products.bulk_type_${opt}`) }}
        </button>
      </div>
      <BaseInput
        v-model="form.value"
        type="number"
        step="any"
        :label="t('products.bulk_value')"
        :hint="t(form.type === 'percent' ? 'products.bulk_value_hint_percent' : 'products.bulk_value_hint_amount')"
        :error="errorOf('value')"
      />
      <BaseInput v-model="form.effectiveFrom" type="date" :label="t('products.bulk_effective_from')" :error="errorOf('effective_from')" />
      <label class="flex cursor-pointer items-center gap-2.5 text-[13px] font-semibold">
        <input
          type="checkbox"
          role="switch"
          class="h-4 w-4 accent-brand"
          :checked="form.rounding === '500'"
          @change="form.rounding = $event.target.checked ? '500' : 'none'"
        />
        {{ t('products.bulk_rounding') }}
      </label>
      <p v-if="errorOf('rounding')" role="alert" class="text-[12px] font-semibold text-danger-text">{{ errorOf('rounding') }}</p>
      <p v-if="releaseMode" class="text-[12px] leading-relaxed text-muted-4" data-testid="bulk-release-note">{{ t('products.bulk_release_note') }}</p>
    </FormSection>

    <FormSection v-if="showOutletScope" :title="t('products.bulk_price_scope')" :description="t('products.bulk_price_scope_desc')">
      <div role="radiogroup" :aria-label="t('products.bulk_price_scope')" class="flex flex-col gap-2" data-testid="bulk-price-scope">
        <label v-for="opt in ['base', 'all_outlets', 'outlets']" :key="opt" class="flex cursor-pointer items-center gap-2.5 text-[13px] font-semibold">
          <input v-model="form.priceScope" type="radio" name="bulk-price-scope" class="h-4 w-4 accent-brand" :value="opt" />
          {{ t(`products.bulk_scope_${opt}`) }}
        </label>
      </div>
      <div v-if="form.priceScope === 'outlets'" class="max-h-[160px] overflow-y-auto border-2 border-line bg-white" data-testid="bulk-outlets">
        <label v-for="o in outletStore.outlets" :key="o.id" class="flex cursor-pointer items-center gap-2.5 border-b border-line-5 px-3 py-2 text-[13px] last:border-b-0 hover:bg-line-7">
          <input type="checkbox" class="h-4 w-4 accent-brand" :checked="form.outletIds.includes(o.id)" @change="toggleOutlet(o.id, $event.target.checked)" />
          {{ o.code }} · {{ o.name }}
        </label>
      </div>
      <p v-if="errorOf('outlet_ids')" role="alert" class="text-[12px] font-semibold text-danger-text">{{ errorOf('outlet_ids') }}</p>
      <p v-if="errorOf('price_scope')" role="alert" class="text-[12px] font-semibold text-danger-text">{{ errorOf('price_scope') }}</p>
    </FormSection>

    <FormSection :title="t('products.bulk_preview')">
      <ul v-if="valueErrors.length > 1" role="alert" class="list-disc pl-5 text-[12px] font-semibold text-danger-text" data-testid="bulk-value-errors">
        <li v-for="(m, i) in valueErrors" :key="i">{{ m }}</li>
      </ul>

      <div aria-live="polite">
        <LoadingState v-if="previewState === 'loading'" :rows="3" />
        <ErrorState v-else-if="previewState === 'error'" :message="t('products.bulk_preview_failed')" @retry="runPreview" />
        <p v-else-if="previewState === 'idle'" class="text-[12.5px] text-muted-3" data-testid="bulk-preview-empty">{{ t('products.bulk_preview_empty') }}</p>
        <template v-else-if="preview">
          <p class="mb-2 text-[13px] font-bold" data-testid="bulk-preview-count">
            {{ preview.count > 0 ? t('products.bulk_preview_count', { count: preview.count }) : t('products.bulk_preview_none') }}
          </p>
          <div v-if="preview.items.length" class="max-h-[280px] overflow-auto border-2 border-line">
            <table class="w-full text-[12.5px]" :aria-label="t('products.bulk_preview_table')">
              <thead class="sticky top-0 bg-surface-subtle text-left text-[11.5px] uppercase tracking-wide text-muted-3">
                <tr>
                  <th scope="col" class="px-3 py-2">{{ t('products.bulk_col_item') }}</th>
                  <th scope="col" class="px-3 py-2 text-right">{{ t('products.bulk_col_old') }}</th>
                  <th scope="col" class="px-3 py-2 text-right">{{ t('products.bulk_col_new') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in preview.items" :key="`${row.variant_id}:${row.outlet_id ?? 'base'}`" class="border-t border-line-5" data-testid="bulk-preview-row">
                  <td class="px-3 py-2">
                    <span class="font-semibold">{{ row.product }}</span>
                    <span class="text-muted-3"> · {{ row.variant }}</span>
                    <span v-if="row.outlet_code" class="ml-1 font-semibold text-brand-active" data-testid="bulk-preview-outlet">@ {{ row.outlet_code }}</span>
                  </td>
                  <td class="px-3 py-2 text-right tabular-nums text-muted-4">{{ formatIDR(row.old_price) }}</td>
                  <td class="px-3 py-2 text-right font-bold tabular-nums">{{ formatIDR(row.new_price) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <details v-if="preview.warnings?.length" class="mt-2 text-[12px] text-muted-4">
            <summary class="cursor-pointer font-semibold">{{ t('products.bulk_warnings') }} ({{ preview.warnings.length }})</summary>
            <ul class="mt-1 list-disc pl-5">
              <li v-for="(w, i) in preview.warnings" :key="i">{{ w }}</li>
            </ul>
          </details>
        </template>
      </div>

      <p v-if="blockedHint" role="alert" class="text-[12px] font-semibold text-danger-text" data-testid="bulk-blocked">{{ t('products.bulk_submit_blocked') }}</p>
    </FormSection>
  </FormDrawer>
</template>
