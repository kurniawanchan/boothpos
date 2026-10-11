<script setup>
import { onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { listPermissionCatalog } from '../../api/roles';

const idPrefix = useId();
const { t } = useI18n();

/**
 * 043 (T020) — "Izin aksi": daftar centang atas katalog izin tingkat-aksi (GET /permission-catalog, sumber tunggal
 * App\Support\PermissionCatalog), v-model sebagai array kunci izin. Berdampingan dengan RoleMenuPicker, bukan
 * pengganti: menu menjawab "layar mana yang boleh dibuka", izin menjawab "tindakan apa yang boleh dilakukan di
 * dalamnya". Daftar dimuat dari server (tidak di-hardcode) supaya izin baru tidak butuh perubahan di sini; label
 * datang dari server. Kegagalan memuat tidak menghalangi menyimpan peran — blok ini cukup menampilkan pesan.
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const options = ref([]);
const loading = ref(false);
const failed = ref(false);

async function load() {
  loading.value = true;
  failed.value = false;
  try {
    options.value = (await listPermissionCatalog()).data ?? [];
  } catch {
    failed.value = true;
  } finally {
    loading.value = false;
  }
}
onMounted(load);

function toggle(key) {
  const selected = new Set(props.modelValue);
  if (selected.has(key)) selected.delete(key);
  else selected.add(key);
  emit('update:modelValue', Array.from(selected));
}
</script>

<template>
  <fieldset class="flex flex-col gap-2" data-testid="role-permission-picker">
    <legend class="text-[12.5px] font-semibold text-muted-4">{{ t('roles.action_permissions') }}</legend>
    <p class="text-[12px] text-muted-3">{{ t('roles.action_permissions_hint') }}</p>
    <p v-if="loading" class="text-[12.5px] text-muted-3">{{ t('roles.loading_permission_list') }}</p>
    <p v-else-if="failed" class="text-[12.5px] text-danger-text" role="alert">
      {{ t('roles.permission_list_failed') }}
      <button type="button" class="font-bold underline decoration-dotted" @click="load">{{ t('page.retry') }}</button>
    </p>
    <div v-else class="flex flex-col gap-2">
      <label
        v-for="opt in options"
        :key="opt.key"
        :for="`${idPrefix}-${opt.key}`"
        class="flex cursor-pointer items-center gap-2 border border-line bg-white px-3 py-2 text-[13px] transition-colors has-[:checked]:border-brand has-[:checked]:bg-mint-100"
      >
        <input
          :id="`${idPrefix}-${opt.key}`"
          type="checkbox"
          class="h-4 w-4 border-line text-brand focus:ring-mint-100"
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
