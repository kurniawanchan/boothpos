<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * 041-back-office-ui-redesign (US1) — pemilih outlet di topbar. DISEMBUNYIKAN bila pengguna hanya punya
 * satu outlet (instalasi satu toko tidak melihat kendali yang tak berguna) dan baru terisi data nyata di US3
 * (outlet + lingkup pengguna dari /me/scope). Komponen ini hanya prop-driven: tidak memanggil API.
 *
 * `modelValue` = id outlet terpilih, atau null untuk "Semua outlet". Opsi "Semua outlet" hanya ada bila
 * outlet yang boleh diakses lebih dari satu. Menyimpan pilihan & mengirim header X-Outlet-Id adalah tugas
 * store outlet (US3) — server tetap memotong dengan lingkup pengguna, jadi memilih di sini tak melebarkan akses.
 */
const props = defineProps({
  outlets: { type: Array, default: () => [] }, // [{ id, name, code? }]
  modelValue: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const visible = computed(() => props.outlets.length > 1);
const value = computed(() => (props.modelValue === null || props.modelValue === undefined ? '' : String(props.modelValue)));

function onChange(e) {
  const raw = e.target.value;
  emit('update:modelValue', raw === '' ? null : isNaN(Number(raw)) ? raw : Number(raw));
}
</script>

<template>
  <label v-if="visible" class="flex items-center gap-2 border-2 border-line bg-white px-3 py-1.5 text-[13px] font-bold transition-colors focus-within:border-brand hover:border-brand">
    <i class="ph-duotone ph-map-pin text-[16px] text-brand" aria-hidden="true"></i>
    <span class="sr-only">{{ t('frame.outlet_label') }}</span>
    <select :value="value" class="cursor-pointer appearance-none bg-transparent pr-1 outline-none" :aria-label="t('frame.outlet_label')" @change="onChange">
      <option value="">{{ t('frame.outlet_all') }} ({{ outlets.length }})</option>
      <option v-for="o in outlets" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
    </select>
    <i class="ph-duotone ph-caret-down text-[12px] text-muted" aria-hidden="true"></i>
  </label>
</template>
