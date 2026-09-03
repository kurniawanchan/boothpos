<script setup>
import { onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { listMenuKeys } from '../../api/roles';

const idPrefix = useId();
const { t } = useI18n();

/**
 * Grid checkbox atas registry menu App\Support\MenuKeys — v-model-able
 * sebagai array kunci menu yang dipilih, dipakai langsung oleh form
 * create/edit RolesView.vue. Daftar menu dimuat dari GET /menu-keys, bukan
 * di-hardcode di sini, supaya menambah layar baru ke aplikasi tidak pernah
 * butuh perubahan di komponen ini.
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const options = ref([]);
const loading = ref(false);

onMounted(async () => {
  loading.value = true;
  try {
    const res = await listMenuKeys();
    options.value = res.data ?? [];
  } finally {
    loading.value = false;
  }
});

function toggle(key) {
  const selected = new Set(props.modelValue);
  if (selected.has(key)) selected.delete(key);
  else selected.add(key);
  emit('update:modelValue', Array.from(selected));
}
</script>

<template>
  <fieldset class="flex flex-col gap-2">
    <legend class="text-[12.5px] font-semibold text-muted-4">{{ t('roles.menu_access') }}</legend>
    <p v-if="loading" class="text-[12.5px] text-muted-3">{{ t('roles.loading_menu_list') }}</p>
    <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3">
      <label
        v-for="opt in options"
        :key="opt.key"
        :for="`${idPrefix}-${opt.key}`"
        class="flex cursor-pointer items-center gap-2 rounded-lg border border-line bg-white px-3 py-2 text-[13px] transition-colors has-[:checked]:border-brand has-[:checked]:bg-mint-100"
      >
        <input
          :id="`${idPrefix}-${opt.key}`"
          type="checkbox"
          class="h-4 w-4 rounded border-line text-brand focus:ring-mint-100"
          :value="opt.key"
          :aria-label="opt.label"
          :checked="modelValue.includes(opt.key)"
          @change="toggle(opt.key)"
        />
        <span>{{ opt.label }}</span>
      </label>
    </div>
    <span v-if="error" class="text-[12px] font-medium text-danger-text">{{ error }}</span>
  </fieldset>
</template>
