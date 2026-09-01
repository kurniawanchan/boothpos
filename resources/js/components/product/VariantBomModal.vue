<script setup>
import { reactive, ref, watch } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import StatusPill from '../ui/StatusPill.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import { listMaterials } from '../../api/materials';
import { listBomLines, addBomLine, updateBomLine, deleteBomLine, getCostBreakdown } from '../../api/materials';
import { formatIDR } from '../../utils/money';
import { useToastStore } from '../../stores/toast';

/**
 * BOM (bill of materials) + rincian modal bahan untuk satu varian produk —
 * dibuka dari baris varian di ProductDetailModal. Sengaja jadi modal
 * tersendiri (bukan bagian dari drawer edit produk) supaya form
 * produk/varian yang sudah padat tidak makin sesak, dan karena BOM adalah
 * data per-varian yang paling wajar dilihat setelah user sudah melihat
 * daftar varian di Detail.
 *
 * `bom_cost` di sini SELALU read-only dan terpisah dari `cost_price` —
 * tidak pernah ditulis otomatis (lihat App\Services\BomCostCalculator).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  variantId: { type: [Number, String, null], default: null },
  variantSku: { type: String, default: '' },
  variantName: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const toast = useToastStore();
const loading = ref(false);
const bomLines = ref([]);
const breakdown = ref(null);
const materials = ref([]);

const showForm = ref(false);
const editingLine = ref(null);
const form = reactive({ material_id: '', qty_needed: '', notes: '' });
const formErrors = reactive({});
const saving = ref(false);

function resetForm() {
  Object.assign(form, { material_id: '', qty_needed: '', notes: '' });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
}

async function reload() {
  if (!props.variantId) return;
  loading.value = true;
  try {
    const [linesRes, breakdownRes, materialsRes] = await Promise.all([
      listBomLines(props.variantId),
      getCostBreakdown(props.variantId),
      listMaterials({ per_page: 100, is_active: 1 }),
    ]);
    bomLines.value = linesRes.data;
    breakdown.value = breakdownRes;
    materials.value = materialsRes.data;
  } catch (err) {
    toast.error(err.message || 'Gagal memuat BOM varian.');
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.open, props.variantId],
  ([open]) => {
    if (open) reload();
    else {
      bomLines.value = [];
      breakdown.value = null;
      resetForm();
    }
  },
  { immediate: true }
);

const materialOptions = ref([]);
watch(materials, (m) => {
  materialOptions.value = m.map((x) => ({ value: x.id, label: `${x.name} (${x.unit})` }));
});

function openCreate() {
  editingLine.value = null;
  resetForm();
  showForm.value = true;
}

function openEdit(line) {
  editingLine.value = line;
  Object.assign(form, { material_id: line.material_id, qty_needed: line.qty_needed, notes: line.notes ?? '' });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveLine() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  try {
    if (editingLine.value) {
      await updateBomLine(editingLine.value.id, { qty_needed: form.qty_needed, notes: form.notes || null });
      toast.success('Baris BOM diperbarui.');
    } else {
      await addBomLine(props.variantId, {
        material_id: Number(form.material_id),
        qty_needed: form.qty_needed,
        notes: form.notes || null,
      });
      toast.success('Baris BOM ditambahkan.');
    }
    showForm.value = false;
    await reload();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function confirmDelete(line) {
  deleteTarget.value = line;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteBomLine(deleteTarget.value.id);
    toast.success('Baris BOM dihapus.');
    showDelete.value = false;
    await reload();
  } catch {
    // error umum sudah ditoast interceptor bersama.
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="`BOM &amp; modal bahan — ${variantSku || variantName}`" max-width-class="max-w-[640px]" @close="emit('close')">
    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">Memuat BOM…</div>
    <div v-else class="flex flex-col gap-4 px-6 py-5">
      <div v-if="breakdown" class="flex items-center gap-3 rounded-lg border border-line-2 bg-surface-subtle px-4 py-3.5">
        <div class="flex flex-1 flex-col gap-0.5">
          <span class="text-[11px] font-bold uppercase tracking-wide text-muted-3">Harga modal manual (cost_price)</span>
          <span class="text-[17px] font-extrabold tracking-tight">{{ formatIDR(breakdown.cost_price) }}</span>
        </div>
        <i class="ph-duotone ph-arrows-left-right text-[18px] text-muted-3" aria-hidden="true"></i>
        <div class="flex flex-1 flex-col items-end gap-0.5">
          <span class="text-[11px] font-bold uppercase tracking-wide text-muted-3">Modal bahan dari BOM (bom_cost)</span>
          <span class="text-[17px] font-extrabold tracking-tight text-brand-active">{{ formatIDR(breakdown.bom_cost) }}</span>
        </div>
      </div>
      <p class="text-[12px] leading-relaxed text-muted-3">
        <span class="font-mono">bom_cost</span> tidak pernah menimpa <span class="font-mono">cost_price</span> secara otomatis —
        bandingkan sendiri lalu ubah harga modal manual lewat form Edit produk bila perlu.
      </p>

      <div v-if="!bomLines.length" class="rounded-lg border border-dashed border-disabled-2 px-4 py-8 text-center text-[13px] text-muted-3">
        Belum ada bahan pada BOM varian ini.
      </div>
      <div v-else class="overflow-hidden rounded-lg border border-line-2">
        <table class="w-full border-collapse text-[13px]">
          <thead>
            <tr class="bg-surface-subtle text-left">
              <th class="px-3 py-2 font-bold text-muted-2">Bahan</th>
              <th class="px-3 py-2 text-right font-bold text-muted-2">Qty</th>
              <th class="px-3 py-2 text-right font-bold text-muted-2">Harga satuan</th>
              <th class="px-3 py-2 text-right font-bold text-muted-2">Biaya</th>
              <th class="px-3 py-2 font-bold text-muted-2">Vendor acuan</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in breakdown?.lines ?? []" :key="line.bom_line_id" class="border-t border-line-5">
              <td class="px-3 py-2">{{ line.material_name }} <span class="text-muted-3">({{ line.unit }})</span></td>
              <td class="px-3 py-2 text-right">{{ line.qty_needed }}</td>
              <td class="px-3 py-2 text-right">{{ formatIDR(line.unit_cost) }}</td>
              <td class="px-3 py-2 text-right font-semibold">{{ formatIDR(line.line_cost) }}</td>
              <td class="px-3 py-2">
                <span v-if="!line.has_price" class="inline-flex items-center gap-1 text-[11.5px] font-bold text-warn-text">
                  <i class="ph-duotone ph-warning text-[14px]" aria-hidden="true"></i>
                  Belum ada harga vendor
                </span>
                <span v-else class="inline-flex items-center gap-1.5">
                  {{ line.reference_vendor_name }}
                  <StatusPill v-if="line.reference_is_preferred" variant="mint">Preferred</StatusPill>
                </span>
              </td>
              <td class="px-3 py-2">
                <div class="flex justify-end gap-2">
                  <button
                    type="button"
                    class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active"
                    @click="openEdit(bomLines.find((l) => l.id === line.bom_line_id))"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    class="text-[12.5px] font-semibold text-danger-text"
                    @click="confirmDelete(bomLines.find((l) => l.id === line.bom_line_id))"
                  >
                    Hapus
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <BaseButton variant="secondary" @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        Tambah bahan ke BOM
      </BaseButton>
    </div>

    <BaseModal :open="showForm" :title="editingLine ? 'Ubah baris BOM' : 'Bahan baru di BOM'" max-width-class="max-w-[420px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveLine">
        <BaseSelect
          v-if="!editingLine"
          v-model="form.material_id"
          label="Bahan"
          required
          :options="materialOptions"
          :error="formErrors.material_id"
        />
        <div v-else class="flex flex-col gap-1.5">
          <span class="text-[12.5px] font-semibold text-muted-4">Bahan</span>
          <span class="text-[13.5px] font-bold">{{ editingLine.material_name }}</span>
        </div>
        <BaseInput v-model="form.qty_needed" type="number" min="0" step="0.0001" label="Jumlah dibutuhkan per unit" required :error="formErrors.qty_needed" />
        <BaseInput v-model="form.notes" label="Catatan" :error="formErrors.notes" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">Batal</BaseButton>
          <BaseButton :loading="saving" @click="saveLine">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      title="Hapus baris BOM"
      :message="`Hapus ${deleteTarget?.material_name} dari BOM varian ini?`"
      confirm-label="Ya, hapus"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </BaseModal>
</template>
