<script setup>
import { nextTick, onBeforeUnmount, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * 043 (T034) — satu tombol "Impor / ekspor" di kepala halaman (desain) yang membuka dua tindakan yang sebelumnya
 * dua tombol terpisah: Ekspor .xlsx dan Impor massal. Perilaku keduanya TIDAK berubah — komponen ini hanya
 * memancarkan `export` / `import`; halaman yang menjalankannya. Pola menu ARIA: tombol `aria-haspopup`, daftar
 * `role="menu"`, Escape menutup dan mengembalikan fokus ke tombol, klik di luar menutup. Tidak di-Teleport: kepala
 * halaman tidak memotong luapan.
 */
defineProps({
  exporting: { type: Boolean, default: false },
});
const emit = defineEmits(['export', 'import']);
const { t } = useI18n();

const open = ref(false);
const root = ref(null);
const trigger = ref(null);
const menuId = useId();

function onDocClick(e) {
  if (root.value && !root.value.contains(e.target)) close();
}
function toggle() {
  open.value = !open.value;
  if (open.value) {
    document.addEventListener('click', onDocClick);
    nextTick(() => root.value?.querySelector('[role="menuitem"]')?.focus());
  } else {
    document.removeEventListener('click', onDocClick);
  }
}
function close(returnFocus = false) {
  open.value = false;
  document.removeEventListener('click', onDocClick);
  if (returnFocus) trigger.value?.focus();
}
function pick(event) {
  close(true);
  emit(event);
}
function onKeydown(e) {
  if (e.key === 'Escape' && open.value) {
    e.stopPropagation();
    close(true);
  }
}
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));
</script>

<template>
  <div ref="root" class="relative" @keydown="onKeydown">
    <button
      ref="trigger"
      type="button"
      aria-haspopup="menu"
      :aria-expanded="open"
      :aria-controls="menuId"
      class="inline-flex h-[46px] items-center justify-center gap-2 border border-line bg-white px-4 text-[14px] font-bold text-muted-5 transition-colors hover:border-brand hover:text-brand-active focus-visible:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-mint-100"
      @click="toggle"
    >
      <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
      {{ t('products.import_export') }}
      <i class="ph-duotone ph-caret-down text-[12px]" aria-hidden="true"></i>
    </button>
    <div v-if="open" :id="menuId" role="menu" class="absolute right-0 z-20 mt-1 flex min-w-[200px] flex-col border-2 border-line bg-white py-1 shadow-lg">
      <button type="button" role="menuitem" :disabled="exporting" class="flex items-center gap-2 px-4 py-2.5 text-left text-[13px] font-semibold hover:bg-line-7 focus-visible:bg-line-7 focus-visible:outline-none disabled:opacity-50" @click="pick('export')">
        <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
        {{ t('common.export_xlsx') }}
      </button>
      <button type="button" role="menuitem" class="flex items-center gap-2 px-4 py-2.5 text-left text-[13px] font-semibold hover:bg-line-7 focus-visible:bg-line-7 focus-visible:outline-none" @click="pick('import')">
        <i class="ph-duotone ph-file-arrow-up text-[16px]" aria-hidden="true"></i>
        {{ t('common.bulk_import') }}
      </button>
    </div>
  </div>
</template>
