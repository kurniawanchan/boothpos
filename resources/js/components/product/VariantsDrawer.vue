<script setup>
import { computed, reactive, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { saveVariants } from '../../api/products';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import { variantName, variantPrice } from '../../utils/itemDetail';
import FormDrawer from '../page/FormDrawer.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import { useVariantDelete } from '../../composables/useVariantDelete';
import BaseInput from '../ui/BaseInput.vue';
import { CELL_ERROR, CELL_HEAD, ROW_REMOVE, TABLE_LINK, cellInputClass } from './cellStyles';

/**
 * 043 (US6) — laci "Varian & harga" halaman item: mengubah SEMUA varian sebuah item yang sudah ada dalam satu
 * permintaan `PUT /products/{id}/variants` (semua-atau-tidak-sama-sekali di server).
 *
 * Mengapa laci terpisah dari ProductFormDrawer: yang lama memuat seluruh formulir item + kartu varian + stok + BOM
 * dalam satu layar panjang, dan menyimpan harga lewat jalur lain. Di sini harga dasar SELALU lewat PriceService di
 * server (riwayat harga, mode rilis, ambang persetujuan) — klien hanya mengirim, tidak menghitung apa pun.
 *
 * - SKU varian baru dibangkitkan server (tidak ada kolom SKU yang bisa diisi); baris baru boleh dibuang sebelum
 *   disimpan. Varian tersimpan boleh dihapus PERMANEN (2026-10-31) hanya bila belum pernah dipakai — server yang
 *   menilai (DELETE /variants/{id}/permanent, useVariantDelete); yang punya riwayat tetap hanya dinonaktifkan.
 * - "Berlaku mulai" satu tanggal untuk seluruh batch (kontrak server): kosong = seketika (rilis mati) / rilis
 *   berikutnya (rilis hidup).
 * - Kolom angka/tanggal tanpa atribut `min`/`max`: atribut itu memicu validasi bawaan browser yang menahan submit tanpa
 *   pesan kita. Aturannya (harga ≥ 0; tanggal tidak di masa lalu) dicek di sini dan oleh server (422 per kolom).
 * - Teks aturan ambang persetujuan hanya tampil bila rilis menu aktif; angka ambang tidak dikirim ke klien, jadi
 *   teksnya tidak menyebut angka.
 * - Tampilan: SATU tabel berbingkai (gaya sel bersama di ./cellStyles.js) — input datar di dalam sel, tautan "+ Tambah
 *   varian" di dalam bingkai. Tanpa kolom/teks outlet: itu tahap B (dimensi outlet belum ada).
 * - Label simpan mengikuti mode: "Simpan" (langsung) vs "Ajukan perubahan" (masuk antrean rilis).
 * - 422 per kolom (`variants.N.variant_name`, `variants.N.sell_price`, `effective_from`) ditampilkan di kolomnya;
 *   409 (mis. harga modal dikunci BOM/resep) ditampilkan sebagai catatan di dalam laci dan tidak menutupnya.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  product: { type: Object, default: null }, // { id, name, variants }
});
const emit = defineEmits(['close', 'saved', 'changed']);
const { t } = useI18n();
const settings = useSettingsStore();
const toast = useToastStore();

const tableLabelId = `vd-table-${useId()}`;
const releaseOn = computed(() => settings.menuReleaseEnabled === true);
const submitLabel = computed(() => (releaseOn.value ? t('products.submit_request') : t('common.save')));

let keySeq = 0;
const rows = ref([]);
const effectiveFrom = ref('');
const submitting = ref(false);
const alertMessage = ref('');
const rowErrors = reactive({}); // { [index]: { name, price } }
const dateError = ref('');

function resetForm() {
  rows.value = (props.product?.variants ?? []).map((v) => ({
    key: `v${v.id}`,
    id: v.id,
    sku: v.sku ?? '',
    name: variantName(v),
    price: variantPrice(v) === null ? '' : String(Number(variantPrice(v))),
    active: v.is_active !== false,
  }));
  effectiveFrom.value = '';
  alertMessage.value = '';
  dateError.value = '';
  Object.keys(rowErrors).forEach((k) => delete rowErrors[k]);
}

// Dibangun ulang tiap laci dibuka: perubahan yang dibatalkan tidak boleh "tertinggal" di pembukaan berikutnya.
watch(() => props.open, (isOpen) => { if (isOpen) resetForm(); }, { immediate: true });

function addRow() {
  rows.value.push({ key: `n${++keySeq}`, id: null, sku: '', name: '', price: '', active: true });
}

function removeNew(index) {
  rows.value.splice(index, 1);
  // Indeks galat bergeser mengikuti baris; mengosongkannya lebih aman daripada menampilkan galat di baris yang salah.
  Object.keys(rowErrors).forEach((k) => delete rowErrors[k]);
}

// Hapus permanen varian tersimpan: baris dibuang dari tabel dan halaman di belakang dimuat ulang (`changed`).
const variantDelete = useVariantDelete((variant) => {
  rows.value = rows.value.filter((r) => r.id !== variant.id);
  Object.keys(rowErrors).forEach((k) => delete rowErrors[k]);
  emit('changed');
});

const newNumber = (index) => rows.value.slice(0, index + 1).filter((r) => r.id === null).length;
const rowLabel = (r) => r.name || r.sku || '';

/** Cermin aturan server (nama wajib, harga angka ≥ 0) agar kesalahan jelas tertangkap sebelum kirim. */
function validate() {
  Object.keys(rowErrors).forEach((k) => delete rowErrors[k]);
  let ok = true;
  rows.value.forEach((r, i) => {
    const e = {};
    if (!r.name.trim()) e.name = t('products.vd_name_required');
    const price = r.price === '' ? NaN : Number(r.price);
    if (!Number.isFinite(price) || price < 0) e.price = t('products.vd_price_invalid');
    if (e.name || e.price) {
      rowErrors[i] = e;
      ok = false;
    }
  });
  return ok;
}

function applyServerErrors(errors) {
  Object.entries(errors ?? {}).forEach(([field, messages]) => {
    const message = Array.isArray(messages) ? messages[0] : messages;
    const m = /^variants\.(\d+)\.(variant_name|sell_price|id)$/.exec(field);
    if (m) {
      // `id` (varian bukan milik item ini) tidak punya kolomnya sendiri: ditempelkan ke kolom nama baris itu.
      const column = m[2] === 'sell_price' ? 'price' : 'name';
      rowErrors[Number(m[1])] = { ...(rowErrors[Number(m[1])] ?? {}), [column]: message };
    } else if (field === 'effective_from') {
      dateError.value = message;
    } else {
      alertMessage.value = message;
    }
  });
}

async function submit() {
  alertMessage.value = '';
  dateError.value = '';
  if (!validate()) return;

  const payload = {
    variants: rows.value.map((r) => {
      const row = { variant_name: r.name.trim(), sell_price: Number(r.price), is_active: r.active };
      if (r.id !== null) row.id = r.id;
      return row;
    }),
  };
  if (effectiveFrom.value) payload.effective_from = effectiveFrom.value;

  submitting.value = true;
  try {
    const result = await saveVariants(props.product.id, payload);
    const pending = result?.pending ?? [];
    const awaiting = pending.filter((p) => p.status === 'awaiting_approval').length;
    if (pending.length) toast.success(t('products.vd_saved_pending', { count: pending.length }));
    else toast.success(t('products.vd_saved'));
    if (awaiting) toast.warning(t('products.vd_saved_approval', { count: awaiting }));
    emit('saved', result);
  } catch (err) {
    if (err?.isValidation) applyServerErrors(err.errors);
    else if (err?.isConflict) alertMessage.value = String(err.code ?? '').startsWith('cost_price_locked') ? t('products.vd_cost_locked') : err.message;
    else if (err?.status !== 403) alertMessage.value = err?.message || t('products.vd_failed');
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <FormDrawer
    :open="open"
    :title="t('products.vd_title')"
    :subtitle="product?.name ?? ''"
    :submit-label="submitLabel"
    :submitting="submitting"
    max-width-class="max-w-[820px]"
    @close="emit('close')"
    @submit="submit"
  >
    <div class="flex flex-col gap-4">
      <p class="text-[12.5px] leading-relaxed text-muted-4" data-testid="vd-guidance">{{ t('products.vd_guidance') }}</p>
      <p v-if="releaseOn" class="border-2 border-line bg-surface-subtle px-3.5 py-2.5 text-[12.5px] leading-relaxed text-muted-5" data-testid="vd-threshold-rule">
        {{ t('products.vd_threshold_rule') }}
      </p>

      <p v-if="alertMessage" role="alert" class="border-2 border-danger-border bg-danger-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-danger-text" data-testid="vd-alert">{{ alertMessage }}</p>

      <div>
        <p :id="tableLabelId" class="mb-1.5 text-[13px] font-semibold text-ink">{{ t('products.vd_table_label') }}<span class="text-danger-text" aria-hidden="true"> *</span></p>
        <!-- Satu tabel berbingkai (rancangan Back Office): input di dalam sel datar sampai difokus, "+ Tambah varian" ada di
             dalam bingkai yang sama. Bingkai + overflow-x-auto agar layar sempit menggulir, bukan memecah kolom. -->
        <div class="overflow-x-auto border-2 border-line bg-white" data-testid="vd-table">
          <table class="w-full min-w-[560px] border-collapse text-left text-[13px]" :aria-labelledby="tableLabelId">
            <thead>
              <tr class="border-b border-line-2 bg-surface-subtle">
                <th scope="col" :class="CELL_HEAD">{{ t('products.vd_col_variant') }}</th>
                <th scope="col" :class="[CELL_HEAD, 'border-l border-line-3']">{{ t('products.col_sku') }}</th>
                <th scope="col" :class="[CELL_HEAD, 'border-l border-line-3 text-right']">{{ t('products.col_price') }}</th>
                <th scope="col" :class="[CELL_HEAD, 'w-20 border-l border-line-3 text-center']">{{ t('products.vd_col_active') }}</th>
                <th scope="col" aria-hidden="true" class="w-10 border-l border-line-3"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in rows" :key="r.key" class="border-b border-line-2 align-top" data-testid="vd-row">
                <td class="p-0">
                  <input v-model="r.name" type="text" maxlength="100" :class="cellInputClass({ error: !!rowErrors[i]?.name })" :aria-label="t('products.vd_name_aria', { n: i + 1 })" :aria-invalid="rowErrors[i]?.name ? 'true' : undefined" />
                  <p v-if="rowErrors[i]?.name" role="alert" :class="CELL_ERROR">{{ rowErrors[i].name }}</p>
                </td>
                <!-- SKU: teks biasa (dibangkitkan server, tidak bisa diisi); baris baru menampilkan "Otomatis". -->
                <td class="border-l border-line-3 px-3 py-3 font-mono text-[12px] text-brand-active">{{ r.sku || t('products.vd_sku_auto') }}</td>
                <td class="border-l border-line-3 p-0">
                  <input v-model="r.price" type="number" step="any" :class="cellInputClass({ number: true, error: !!rowErrors[i]?.price })" :aria-label="t('products.vd_price_aria', { name: rowLabel(r) || String(i + 1) })" :aria-invalid="rowErrors[i]?.price ? 'true' : undefined" />
                  <p v-if="rowErrors[i]?.price" role="alert" :class="[CELL_ERROR, 'text-right']">{{ rowErrors[i].price }}</p>
                </td>
                <td class="border-l border-line-3 px-3 py-3 text-center">
                  <input v-model="r.active" type="checkbox" class="h-4 w-4 accent-brand" :aria-label="t('products.vd_active_aria', { name: rowLabel(r) || String(i + 1) })" />
                </td>
                <td class="border-l border-line-3 px-1 py-1.5 text-center">
                  <!-- Baris BARU dibuang di klien; varian tersimpan dihapus permanen lewat server (hanya bila tanpa riwayat). -->
                  <button v-if="r.id === null" type="button" :class="ROW_REMOVE" :aria-label="t('products.vd_remove_new', { n: newNumber(i) })" @click="removeNew(i)">
                    <i class="ph-duotone ph-x text-[14px]" aria-hidden="true"></i>
                  </button>
                  <button v-else type="button" :class="ROW_REMOVE" :aria-label="t('products.variant_delete_aria', { name: rowLabel(r) })" data-testid="vd-delete" @click="variantDelete.ask({ id: r.id, name: rowLabel(r) })">
                    <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <div class="px-3 py-2.5">
            <button type="button" :class="TABLE_LINK" data-testid="vd-add" @click="addRow">{{ t('products.vd_add') }}</button>
          </div>
        </div>
      </div>

      <div class="max-w-[260px]">
        <BaseInput
          v-model="effectiveFrom"
          type="date"
          :label="t('products.vd_effective')"
          :hint="releaseOn ? t('products.vd_effective_hint_release') : t('products.vd_effective_hint_now')"
          :error="dateError"
        />
      </div>
    </div>
    <ConfirmDialog
      :open="variantDelete.target.value !== null"
      :title="t('products.variant_delete_title')"
      :message="t('products.variant_delete_message', { name: variantDelete.target.value?.name ?? '' })"
      :confirm-label="t('products.variant_delete_confirm')"
      :loading="variantDelete.deleting.value"
      @close="variantDelete.cancel()"
      @confirm="variantDelete.confirm()"
    />
  </FormDrawer>
</template>
