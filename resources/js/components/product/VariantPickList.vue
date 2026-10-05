<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * 037 — daftar centang varian TUJUAN salin BOM ("Salin ke varian pilihan"): satu baris per varian dengan
 * gambar (placeholder bila tidak ada), SKU, nama, dan penanda nonaktif / sudah punya BOM; kotak cari,
 * "pilih semua / kosongkan", dan hitungan terpilih.
 *
 * Sengaja daftar INLINE yang menggulung sendiri (max-h + overflow), bukan dropdown melayang: tidak bisa
 * terpotong tepi layar atau dialog, dan banyak centang sekaligus memang butuh daftar terbuka. "Pilih
 * semua" hanya menambahkan baris yang SEDANG tampil (hasil pencarian), tidak menyentuh yang tersaring.
 */
const props = defineProps({
  // [{ id, sku, variant_name, image_url, is_active, has_bom }]
  variants: { type: Array, default: () => [] },
  modelValue: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();
const query = ref('');

const visible = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return props.variants;
  return props.variants.filter((v) => `${v.sku} ${v.variant_name}`.toLowerCase().includes(q));
});
const selected = computed(() => new Set(props.modelValue.map(Number)));

function toggle(id, checked) {
  const next = new Set(selected.value);
  if (checked) next.add(Number(id));
  else next.delete(Number(id));
  emit('update:modelValue', [...next]);
}

function selectAll() {
  emit('update:modelValue', [...new Set([...selected.value, ...visible.value.map((v) => Number(v.id))])]);
}

function clear() {
  emit('update:modelValue', []);
}
</script>

<template>
  <div class="flex flex-col gap-2.5">
    <div class="flex flex-wrap items-center gap-2">
      <input
        v-model="query"
        type="text"
        autocomplete="off"
        :aria-label="t('common.search_placeholder')"
        :placeholder="t('common.search_placeholder')"
        class="h-9 min-w-[160px] flex-1 rounded-md border border-line bg-white px-2.5 text-[13.5px] outline-none focus:border-brand"
      />
      <button type="button" class="text-[12.5px] font-semibold text-brand-active hover:underline disabled:opacity-40" :disabled="!visible.length" @click="selectAll">{{ t('master_data.bom_pick_select_all') }}</button>
      <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:underline disabled:opacity-40" :disabled="!modelValue.length" @click="clear">{{ t('master_data.bom_pick_clear') }}</button>
      <span class="text-[12px] font-semibold text-muted-3">{{ t('master_data.bom_pick_selected', { count: modelValue.length }) }}</span>
    </div>

    <div data-testid="pick-scroll" class="max-h-[260px] overflow-y-auto overscroll-contain rounded-lg border border-line-2 bg-white">
      <label
        v-for="v in visible"
        :key="v.id"
        class="flex cursor-pointer items-center gap-3 border-b border-line-5 px-3 py-2 last:border-b-0 hover:bg-line-7"
      >
        <input type="checkbox" class="h-4 w-4 flex-none accent-brand" :checked="selected.has(Number(v.id))" @change="toggle(v.id, $event.target.checked)" />
        <img v-if="v.image_url" :src="v.image_url" alt="" class="h-9 w-9 flex-none rounded-md border border-line-2 object-cover" />
        <span v-else class="flex h-9 w-9 flex-none items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3"><i class="ph-duotone ph-image text-[16px]" aria-hidden="true"></i></span>
        <span class="flex min-w-0 flex-1 flex-col">
          <span class="truncate font-mono text-[12px] font-semibold">{{ v.sku }}</span>
          <span class="truncate text-[13px] text-muted-4">{{ v.variant_name }}</span>
        </span>
        <span v-if="v.has_bom" class="flex-none rounded-md bg-warn-bg px-2 py-0.5 text-[11px] font-bold text-warn-text">{{ t('master_data.variant_has_bom_marker') }}</span>
        <span v-if="v.is_active === false" class="flex-none rounded-md bg-line-5 px-2 py-0.5 text-[11px] font-bold text-muted-3">{{ t('master_data.variant_inactive_marker') }}</span>
      </label>
      <p v-if="!visible.length" class="px-3 py-4 text-center text-[13px] text-muted-3">{{ t('master_data.bom_pick_none') }}</p>
    </div>
  </div>
</template>
