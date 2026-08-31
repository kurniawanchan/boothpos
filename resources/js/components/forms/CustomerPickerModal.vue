<script setup>
import { ref, reactive } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseButton from '../ui/BaseButton.vue';
import EmptyState from '../ui/EmptyState.vue';
import { listCustomers, createCustomer } from '../../api/customers';
import { useDebouncedFn } from '../../composables/useDebouncedFn';
import { useToastStore } from '../../stores/toast';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'select']);

const toast = useToastStore();
const search = ref('');
const results = ref([]);
const loading = ref(false);
const creating = ref(false);
const newCustomer = reactive({ name: '', phone: '' });
const saving = ref(false);

async function runSearch() {
  loading.value = true;
  try {
    const res = await listCustomers({ search: search.value, per_page: 10 });
    results.value = res.data;
  } finally {
    loading.value = false;
  }
}
const debouncedSearch = useDebouncedFn(runSearch, 300);

function onInput() {
  debouncedSearch();
}

function pick(customer) {
  emit('select', customer);
  emit('close');
}

function clearSelection() {
  emit('select', null);
  emit('close');
}

async function saveNewCustomer() {
  if (!newCustomer.name.trim()) return;
  saving.value = true;
  try {
    const customer = await createCustomer({ name: newCustomer.name, phone: newCustomer.phone || null });
    toast.success('Pelanggan baru tersimpan.');
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
</script>

<template>
  <BaseModal :open="open" title="Pilih pelanggan" max-width-class="max-w-[440px]" @close="emit('close')">
    <div class="flex flex-col gap-3.5 px-6 py-5">
      <button type="button" class="self-start text-[12.5px] font-semibold text-muted-4 underline decoration-dotted" @click="clearSelection">
        Lanjutkan sebagai walk-in (tanpa pelanggan)
      </button>

      <BaseInput v-model="search" label="Cari nama / telepon" placeholder="Ketik untuk mencari…" @input="onInput" />

      <div class="flex max-h-[240px] flex-col gap-1 overflow-auto">
        <EmptyState v-if="!loading && results.length === 0 && search" icon="ph-magnifying-glass" message="Tidak ditemukan." />
        <button
          v-for="c in results"
          :key="c.id"
          type="button"
          class="flex flex-col gap-0.5 rounded-md px-3 py-2 text-left transition-colors hover:bg-line-7"
          @click="pick(c)"
        >
          <span class="text-[13.5px] font-semibold">{{ c.name }}</span>
          <span class="text-[12px] text-muted-3">{{ c.phone || '—' }}</span>
        </button>
      </div>

      <div class="border-t border-line-3 pt-3.5">
        <button v-if="!creating" type="button" class="flex items-center gap-2 text-[12.5px] font-bold text-brand-active" @click="creating = true">
          <i class="ph-duotone ph-plus-circle text-[16px]" aria-hidden="true"></i>
          Tambah pelanggan baru
        </button>
        <div v-else class="flex flex-col gap-2.5">
          <BaseInput v-model="newCustomer.name" label="Nama" required />
          <BaseInput v-model="newCustomer.phone" label="Telepon" />
          <div class="flex gap-2">
            <BaseButton variant="secondary" size="sm" @click="creating = false">Batal</BaseButton>
            <BaseButton size="sm" :loading="saving" @click="saveNewCustomer">Simpan &amp; pilih</BaseButton>
          </div>
        </div>
      </div>
    </div>
  </BaseModal>
</template>
