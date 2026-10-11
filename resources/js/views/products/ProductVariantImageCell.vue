<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { imageFileError } from './imageGuard';

/**
 * 043 — sel "Gambar" di tabel varian (mode buat DAN ubah): kotak 40px yang sekaligus tombol pemilih berkas.
 * `row` adalah objek reaktif milik drawer dan sengaja DIMUTASI langsung (`image_file`, `image_error`; `image_url`
 * hanya dibaca) — drawer membaca baris yang sama saat mengunggah setelah simpan, pola yang sama dengan sel tabel lain.
 *
 * - Pratinjau lokal memakai object URL yang WAJIB dilepas (revoke) saat berkas diganti/dibuang/sel dibongkar,
 *   kalau tidak setiap pilihan menahan memori berkas sampai tab ditutup.
 * - Validasi memakai `imageGuard` yang sama dengan gambar produk: berkas buruk TIDAK disimpan di baris, galatnya
 *   tampil kecil di bawah kotak, dan input dikosongkan supaya memilih berkas yang sama lagi tetap memicu `change`.
 * - Gambar yang sudah tersimpan bisa diperbesar (lightbox milik drawer) selama belum diganti berkas baru.
 */
const props = defineProps({
  row: { type: Object, required: true },
  index: { type: Number, required: true }, // 0-based; nomor tampil = index + 1
});
const emit = defineEmits(['enlarge']);
const { t } = useI18n();

const inputEl = ref(null);
const preview = ref(null);

function release() {
  if (preview.value && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(preview.value);
  preview.value = null;
}

// Baris dipakai ulang per indeks (kunci v-for = indeks), jadi pantau juga objek barisnya, bukan hanya berkasnya.
watch(
  () => props.row.image_file,
  (file) => {
    release();
    // jsdom/peramban lama tidak punya createObjectURL: tanpa pratinjau, kotak tetap menunjukkan placeholder.
    if (file && typeof URL.createObjectURL === 'function') preview.value = URL.createObjectURL(file);
  },
  { immediate: true },
);
onBeforeUnmount(release);

const shown = computed(() => preview.value || props.row.image_url || null);
const n = computed(() => props.index + 1);

function choose() {
  inputEl.value?.click();
}

function onChange(e) {
  const file = e.target.files?.[0] ?? null;
  props.row.image_error = '';
  props.row.image_file = null;
  if (!file) return;
  const errKey = imageFileError(file);
  if (errKey) {
    props.row.image_error = t(errKey);
    e.target.value = '';
    return;
  }
  props.row.image_file = file;
}

function clearChosen() {
  props.row.image_file = null;
  props.row.image_error = '';
  if (inputEl.value) inputEl.value.value = '';
}
</script>

<template>
  <div class="flex flex-col gap-1 px-2 py-1.5" data-testid="variant-image-cell">
    <div class="flex items-center gap-1.5">
      <button
        type="button"
        class="flex h-10 w-10 flex-none items-center justify-center overflow-hidden border border-line-2 bg-surface-subtle text-muted-3 hover:border-brand"
        :aria-label="t('products.f_variant_image_pick_aria', { n })"
        @click="choose"
      >
        <img v-if="shown" :src="shown" :alt="t('products.f_variant_image_alt', { n })" class="h-full w-full object-cover" />
        <i v-else class="ph-duotone ph-image text-[18px]" aria-hidden="true"></i>
      </button>
      <div class="flex flex-col gap-1">
        <!-- Berkas baru yang BELUM disimpan bisa dibatalkan; gambar tersimpan hanya bisa diperbesar. -->
        <button
          v-if="row.image_file"
          type="button"
          class="flex h-4 w-4 items-center justify-center text-muted-3 hover:text-danger-text"
          :aria-label="t('products.f_variant_image_clear_aria', { n })"
          @click="clearChosen"
        >
          <i class="ph-duotone ph-x text-[12px]" aria-hidden="true"></i>
        </button>
        <button
          v-else-if="row.image_url"
          type="button"
          class="flex h-4 w-4 items-center justify-center text-muted-3 hover:text-brand-active"
          :aria-label="t('master_data.enlarge_variant_image', { name: row.variant_name || n })"
          @click="emit('enlarge', row)"
        >
          <i class="ph-duotone ph-magnifying-glass-plus text-[12px]" aria-hidden="true"></i>
        </button>
      </div>
    </div>
    <input ref="inputEl" type="file" accept="image/*" class="hidden" :aria-label="t('products.f_variant_image_aria', { n })" @change="onChange" />
    <p v-if="row.image_error" role="alert" class="text-[11px] font-medium leading-tight text-danger-text">{{ row.image_error }}</p>
  </div>
</template>
