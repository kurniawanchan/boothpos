<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { listMenuKeys } from '../../api/roles';

const idPrefix = useId();
const { t } = useI18n();

/**
 * Grid menu × aksi atas registry App\Support\MenuKeys + MenuActions (dimuat dari GET /menu-keys, tak di-hardcode).
 *   v-model         = kunci menu yang dipilih (seperti sebelum 057);
 *   v-model:actions = { menu: [aksi…] } aksi TAMBAHAN selain View (View implisit dan selalu menyala).
 * Memilih menu = akses penuh sebagai bawaan (sama dengan perilaku lama); "Hanya lihat" mengosongkan aksinya.
 * `locked` (peran Owner) = semua kontrol dimatikan, karena server menolak perubahan dan selalu memberi akses penuh.
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  actions: { type: Object, default: () => ({}) },
  locked: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'update:actions']);

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

const selected = computed(() => new Set(props.modelValue));
const actionKeys = (opt) => (opt.actions ?? []).map((a) => a.key);
const granted = (key) => new Set(props.actions[key] ?? []);

function toggleMenu(opt) {
  if (props.locked) return;
  const next = new Set(props.modelValue);
  const nextActions = { ...props.actions };
  if (next.has(opt.key)) {
    next.delete(opt.key);
    delete nextActions[opt.key];
  } else {
    next.add(opt.key);
    nextActions[opt.key] = actionKeys(opt);   // bawaan: akses penuh, sama dengan perilaku lama
  }
  emit('update:modelValue', Array.from(next));
  emit('update:actions', nextActions);
}

function toggleAction(opt, action) {
  if (props.locked) return;
  const set = granted(opt.key);
  if (set.has(action)) set.delete(action);
  else set.add(action);
  // urutan mengikuti registry supaya hasil simpan stabil
  emit('update:actions', { ...props.actions, [opt.key]: actionKeys(opt).filter((a) => set.has(a)) });
}

function preset(opt, full) {
  if (props.locked) return;
  emit('update:actions', { ...props.actions, [opt.key]: full ? actionKeys(opt) : [] });
}
</script>

<template>
  <fieldset class="flex flex-col gap-2">
    <legend class="text-[12.5px] font-semibold text-muted-4">{{ t('roles.menu_access') }}</legend>
    <p v-if="locked" class="rounded-lg bg-mint-100 px-3 py-2 text-[12.5px] text-muted-4" data-testid="role-locked">{{ t('roles.locked_owner') }}</p>
    <p v-if="loading" class="text-[12.5px] text-muted-3">{{ t('roles.loading_menu_list') }}</p>
    <div v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2">
      <div
        v-for="opt in options"
        :key="opt.key"
        class="flex flex-col gap-2 rounded-lg border border-line bg-white px-3 py-2 text-[13px] transition-colors"
        :class="selected.has(opt.key) ? 'border-brand bg-mint-100' : ''"
        :data-testid="`menu-${opt.key}`"
      >
        <div class="flex items-center justify-between gap-2">
          <label :for="`${idPrefix}-${opt.key}`" class="flex cursor-pointer items-center gap-2 font-semibold">
            <input
              :id="`${idPrefix}-${opt.key}`"
              type="checkbox"
              class="h-4 w-4 rounded border-line text-brand focus:ring-mint-100"
              :value="opt.key"
              :aria-label="opt.label"
              :checked="selected.has(opt.key)"
              :disabled="locked"
              @change="toggleMenu(opt)"
            />
            <span>{{ opt.label }}</span>
          </label>
          <span v-if="selected.has(opt.key) && (opt.actions ?? []).length && !locked" class="flex gap-2">
            <button type="button" class="whitespace-nowrap text-[11.5px] font-semibold text-muted-4 hover:text-brand-active" :aria-label="t('roles.view_only_for', { menu: opt.label })" @click="preset(opt, false)">{{ t('roles.view_only') }}</button>
            <button type="button" class="whitespace-nowrap text-[11.5px] font-semibold text-muted-4 hover:text-brand-active" :aria-label="t('roles.full_access_for', { menu: opt.label })" @click="preset(opt, true)">{{ t('roles.full_access') }}</button>
          </span>
        </div>
        <div v-if="selected.has(opt.key)" class="flex flex-wrap gap-x-4 gap-y-1.5 border-t border-dashed border-line-2 pt-2">
          <label class="flex items-center gap-1.5 text-[12.5px] text-muted-4">
            <input type="checkbox" class="h-3.5 w-3.5 rounded border-line text-brand" checked disabled :aria-label="`${opt.label} – ${t('roles.action_view')}`" />
            {{ t('roles.action_view') }}
          </label>
          <label v-for="a in opt.actions ?? []" :key="a.key" class="flex cursor-pointer items-center gap-1.5 text-[12.5px] text-muted-4">
            <input
              type="checkbox"
              class="h-3.5 w-3.5 rounded border-line text-brand focus:ring-mint-100"
              :aria-label="`${opt.label} – ${a.label}`"
              :checked="granted(opt.key).has(a.key)"
              :disabled="locked"
              @change="toggleAction(opt, a.key)"
            />
            {{ a.label }}
          </label>
        </div>
      </div>
    </div>
    <span v-if="error" class="text-[12px] font-medium text-danger-text">{{ error }}</span>
  </fieldset>
</template>
