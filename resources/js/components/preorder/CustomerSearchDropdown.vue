<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listCustomers, createCustomer as createCustomerApi } from '../../api/customers';
import { useDebouncedFn } from '../../composables/useDebouncedFn';
import { useToastStore } from '../../stores/toast';

/**
 * 021-preorder-form-updates (US1) — dropdown inline satu-pilih dengan
 * pencarian jarak-jauh, menggantikan alur "tombol buka modal, modal itu
 * punya kotak cari sendiri" milik CustomerPickerModal.vue KHUSUS di form
 * New Preorder. Mekanika posisi/Teleport/klik-di-luar meniru
 * BaseMultiSelect.vue apa adanya (research.md Decision 8) — hanya
 * filteredOptions lokalnya diganti pencarian debounce ke server, dan
 * logika "lanjut sebagai walk-in"/"tambah pelanggan baru" dipindah dari
 * CustomerPickerModal.vue ke sini.
 *
 * CustomerPickerModal.vue SENGAJA TIDAK disentuh/dihapus — komponen itu
 * masih dipakai PosView.vue, di luar cakupan fitur ini.
 */
const props = defineProps({
  modelValue: { type: Object, default: null }, // customer object atau null
});
const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();
const toast = useToastStore();

const id = useId();
const rootEl = ref(null);
const triggerEl = ref(null);
const panelEl = ref(null);
const isOpen = ref(false);
const panelStyle = ref({});

const search = ref('');
const results = ref([]);
const loading = ref(false);
const creating = ref(false);
const newCustomer = reactive({ name: '', phone: '' });
const saving = ref(false);

// 022-preorder-invoice-crud-overhaul (US6, FR-016) — panggilan yang SAMA
// dipakai baik untuk pencarian maupun daftar default: GET /customers
// dengan `search` kosong sudah mengembalikan halaman TANPA filter apa pun
// (data-model.md Decision 6), jadi tidak perlu endpoint baru — cukup
// dipanggil lebih awal (saat dropdown dibuka), bukan hanya setelah
// mengetik.
async function runSearch() {
  loading.value = true;
  try {
    const res = await listCustomers({ search: search.value.trim(), per_page: 10 });
    results.value = res.data;
  } finally {
    loading.value = false;
  }
}
const debouncedSearch = useDebouncedFn(runSearch, 300);

function updatePanelPosition() {
  if (!triggerEl.value) return;
  const r = triggerEl.value.getBoundingClientRect();
  panelStyle.value = { position: 'fixed', top: `${r.bottom + 6}px`, left: `${r.left}px`, width: `${r.width}px` };
}

function open() {
  isOpen.value = true;
  search.value = '';
  creating.value = false;
  updatePanelPosition();
  runSearch();
  nextTick(() => panelEl.value?.querySelector('input')?.focus());
}
function close() {
  isOpen.value = false;
}
function toggle() {
  isOpen.value ? close() : open();
}

function pick(customer) {
  emit('update:modelValue', customer);
  close();
}
function clearSelection() {
  emit('update:modelValue', null);
  close();
}

async function saveNewCustomer() {
  if (!newCustomer.name.trim()) return;
  saving.value = true;
  try {
    const customer = await createCustomerApi({ name: newCustomer.name, phone: newCustomer.phone || null });
    toast.success(t('events_sessions.new_customer_saved'));
    pick(customer);
    creating.value = false;
    newCustomer.name = '';
    newCustomer.phone = '';
  } catch (err) {
    toast.error(err.message);
  } finally {
    saving.value = false;
  }
}

function onClickOutside(e) {
  const clickedTrigger = rootEl.value && rootEl.value.contains(e.target);
  const clickedPanel = panelEl.value && panelEl.value.contains(e.target);
  if (isOpen.value && !clickedTrigger && !clickedPanel) close();
}
function onScrollOrResize() {
  if (isOpen.value) close();
}
watch(isOpen, (open) => {
  if (open) {
    document.addEventListener('mousedown', onClickOutside);
    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('resize', onScrollOrResize);
  } else {
    document.removeEventListener('mousedown', onClickOutside);
    window.removeEventListener('scroll', onScrollOrResize, true);
    window.removeEventListener('resize', onScrollOrResize);
  }
});
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onClickOutside);
  window.removeEventListener('scroll', onScrollOrResize, true);
  window.removeEventListener('resize', onScrollOrResize);
});

const displayLabel = computed(() => props.modelValue?.name ?? t('preorders.pick_customer_ellipsis'));
</script>

<template>
  <div ref="rootEl" class="relative flex flex-col gap-1.5">
    <button
      ref="triggerEl"
      :id="id"
      type="button"
      aria-haspopup="listbox"
      :aria-expanded="isOpen"
      class="flex h-[46px] items-center justify-between gap-3 rounded-lg border border-line bg-white px-3.5 text-left text-[13.5px] outline-none transition-colors focus:border-brand focus:ring-[3px] focus:ring-mint-100"
      :class="modelValue ? 'font-semibold text-ink' : 'text-muted-3'"
      @click="toggle"
    >
      <span class="truncate">{{ displayLabel }}</span>
      <i class="ph-duotone ph-caret-down shrink-0 text-[13px] text-muted-3 transition-transform" :class="{ 'rotate-180': isOpen }" aria-hidden="true"></i>
    </button>

    <Teleport to="body">
      <div v-if="isOpen" ref="panelEl" role="listbox" :style="panelStyle" class="z-[95] flex max-h-[360px] flex-col overflow-hidden rounded-lg border border-line bg-white shadow-lg">
        <div class="flex flex-col gap-2.5 border-b border-line-6 p-2.5">
          <button type="button" class="self-start text-[12px] font-semibold text-muted-4 underline decoration-dotted" @click="clearSelection">
            {{ t('events_sessions.continue_as_walkin') }}
          </button>
          <input
            v-model="search"
            type="text"
            :placeholder="t('events_sessions.type_to_search')"
            class="h-9 w-full rounded-md border border-line px-2.5 text-[13.5px] outline-none focus:border-brand"
            @input="debouncedSearch"
          />
        </div>

        <div class="flex-1 overflow-y-auto p-1">
          <div v-if="!loading && results.length === 0 && search" class="px-3 py-2 text-[13px] text-muted-3">{{ t('events_sessions.not_found') }}</div>
          <button
            v-for="c in results"
            :key="c.id"
            type="button"
            role="option"
            class="flex w-full flex-col gap-0.5 rounded-md px-3 py-2 text-left transition-colors hover:bg-line-7"
            @click="pick(c)"
          >
            <span class="text-[13.5px] font-semibold">{{ c.name }}</span>
            <span class="text-[12px] text-muted-3">{{ c.phone || '—' }}</span>
          </button>
        </div>

        <div class="border-t border-line-3 p-2.5">
          <button v-if="!creating" type="button" class="flex items-center gap-2 text-[12.5px] font-bold text-brand-active" @click="creating = true">
            <i class="ph-duotone ph-plus-circle text-[16px]" aria-hidden="true"></i>
            {{ t('events_sessions.add_new_customer') }}
          </button>
          <div v-else class="flex flex-col gap-2">
            <input v-model="newCustomer.name" type="text" :placeholder="t('events_sessions.name')" class="h-9 rounded-md border border-line px-2.5 text-[13px] outline-none focus:border-brand" />
            <input v-model="newCustomer.phone" type="text" :placeholder="t('events_sessions.phone')" class="h-9 rounded-md border border-line px-2.5 text-[13px] outline-none focus:border-brand" />
            <div class="flex gap-2">
              <button type="button" class="rounded-md border border-line px-2.5 py-1.5 text-[12px] font-semibold" @click="creating = false">{{ t('common.cancel') }}</button>
              <button type="button" class="rounded-md bg-brand px-2.5 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50" :disabled="saving" @click="saveNewCustomer">{{ t('events_sessions.save_and_pick') }}</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
