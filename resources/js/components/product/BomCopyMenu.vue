<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import { copyBomFrom, copyBomOut } from '../../api/materials';
import { useToastStore } from '../../stores/toast';

/**
 * 034-seller-po-bom (US3) — menyalin BOM antar varian satu produk: ke semua,
 * ke varian berikutnya, atau dari varian lain. Panel inline (bukan dropdown
 * melayang) supaya tidak terpotong overflow modal. Target yang sudah punya
 * baris BOM dikonfirmasi DULU di sini (memakai flag has_bom saudara); server
 * tetap menegakkan aturan yang sama dengan 409 bila datanya sudah basi.
 */
const props = defineProps({
  variantId: { type: [Number, String], required: true },
  // Varian satu produk: [{ id, sku, variant_name, has_bom }] (boleh memuat varian ini sendiri).
  siblings: { type: Array, default: () => [] },
  // BOM varian ini punya baris (syarat menyalin KELUAR dari varian ini).
  hasRows: { type: Boolean, default: false },
  // 036: penjaga perubahan yang belum disimpan milik dialog induk — menyalin memuat ulang BOM,
  // jadi setiap aksi salin lewat sini dulu (default: langsung jalan).
  guard: { type: Function, default: (action) => action() },
});
const emit = defineEmits(['copied']);

const { t } = useI18n();
const toast = useToastStore();

const open = ref(false);
const sourceId = ref('');
const working = ref(false);
const pending = ref(null); // { run: (confirm) => Promise, names: string|null, fromSource: string|null }

const others = computed(() => props.siblings.filter((v) => Number(v.id) !== Number(props.variantId)));
const nextVariant = computed(() => others.value.filter((v) => Number(v.id) > Number(props.variantId)).sort((a, b) => a.id - b.id)[0] ?? null);
const copySources = computed(() => others.value.filter((v) => v.has_bom).map((v) => ({ value: v.id, label: `${v.sku} — ${v.variant_name}` })));
const nameOf = (v) => `${v.sku} — ${v.variant_name}`;

function ask(run, targets, fromSource = null) {
  const withRows = targets.filter((v) => v.has_bom);
  if (!withRows.length && !fromSource) return execute(run, false);
  pending.value = { run, names: withRows.map(nameOf).join(', '), fromSource };
}

async function execute(run, confirm) {
  working.value = true;
  try {
    const res = await run(confirm);
    toast.success(t('master_data.bom_copied', { count: res.results?.length ?? 1 }));
    pending.value = null;
    open.value = false;
    sourceId.value = '';
    emit('copied', res);
  } catch {
    // 409/422 sudah ditoast interceptor bersama.
    pending.value = null;
  } finally {
    working.value = false;
  }
}

const toAll = () => props.guard(() => ask((c) => copyBomOut(props.variantId, 'all', c), others.value));
const toNext = () => props.guard(() => ask((c) => copyBomOut(props.variantId, 'next', c), nextVariant.value ? [nextVariant.value] : []));
function fromOther() {
  if (!sourceId.value) return;
  props.guard(doFromOther);
}
function doFromOther() {
  const source = others.value.find((v) => Number(v.id) === Number(sourceId.value));
  // Menyalin KE varian ini mengganti barisnya sendiri bila sudah ada.
  const run = (c) => copyBomFrom(props.variantId, Number(sourceId.value), c);
  if (props.hasRows) pending.value = { run, names: null, fromSource: nameOf(source) };
  else execute(run, false);
}
function confirmPending() {
  execute(pending.value.run, true);
}
</script>

<template>
  <div v-if="others.length" class="flex flex-col gap-3">
    <div>
      <BaseButton variant="secondary" @click="open = !open">
        <i class="ph-duotone ph-copy text-[16px]" aria-hidden="true"></i>
        {{ t('master_data.bom_copy') }}
      </BaseButton>
    </div>

    <div v-if="open" class="flex flex-col gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
      <p class="text-[12px] text-muted-3">{{ t('master_data.bom_copy_hint') }}</p>
      <div v-if="hasRows" class="flex flex-wrap gap-2">
        <BaseButton size="sm" variant="secondary" :loading="working" @click="toAll">{{ t('master_data.bom_copy_to_all') }}</BaseButton>
        <BaseButton v-if="nextVariant" size="sm" variant="secondary" :loading="working" @click="toNext">
          {{ t('master_data.bom_copy_to_next', { name: nextVariant.variant_name }) }}
        </BaseButton>
      </div>
      <div v-if="copySources.length" class="flex items-end gap-2">
        <div class="flex-1">
          <BaseSelect v-model="sourceId" :label="t('master_data.bom_copy_from')" :options="copySources" :placeholder="t('master_data.bom_copy_pick')" searchable />
        </div>
        <BaseButton size="sm" :loading="working" :disabled="!sourceId" @click="fromOther">{{ t('master_data.bom_copy_go') }}</BaseButton>
      </div>
    </div>

    <ConfirmDialog
      :open="!!pending"
      :title="t('master_data.bom_copy_replace_title')"
      :message="pending?.fromSource ? t('master_data.bom_copy_replace_from_confirm', { source: pending.fromSource }) : t('master_data.bom_copy_replace_confirm', { variants: pending?.names })"
      :confirm-label="t('master_data.bom_copy_go')"
      :loading="working"
      @close="pending = null"
      @confirm="confirmPending"
    />
  </div>
</template>
