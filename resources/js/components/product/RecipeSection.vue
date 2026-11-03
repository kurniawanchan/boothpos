<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listRecipes, recalculateRecipeCost, removeOneRecipe, removeRecipe } from '../../api/recipes';
import { useAuthStore } from '../../stores/auth';
import { useToastStore } from '../../stores/toast';
import { formatDate, formatIDR } from '../../utils/formatters';
import { bomSummary, variantName } from '../../utils/itemDetail';
import ItemSection from './ItemSection.vue';
import SectionTable from './SectionTable.vue';
import RecipeDrawer from './RecipeDrawer.vue';
import BaseButton from '../ui/BaseButton.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import StatusPill from '../ui/StatusPill.vue';

/**
 * 043 (US2/US8), diubah 089 — "Resep (BOM)". Resep TIDAK lagi berversi: daftar di bawah memuat SEMUA resep item
 * (`GET /products/{id}/recipes`: nama, Portions, tanggal berlaku, "berlaku sekarang", biaya porsi / food cost %), dan
 * satu varian boleh punya banyak resep aktif yang SEMUANYA dijumlahkan saat penjualan (potong stok bahan) dan harga modal.
 *
 * - "Tambah resep", "Ubah", "Hapus", dan "Hitung ulang biaya" dirender HANYA untuk pemegang `menu.recipe.edit`
 *   (tersembunyi, bukan nonaktif); server tetap menolak yang lain (403). Resep arsip (sisa versi lama hasil migrasi)
 *   hanya tampil, tanpa tombol ubah.
 * - Angka biaya dirender hanya bila payload membawanya: server menghilangkan biaya untuk pengguna tanpa izin biaya,
 *   jadi tampilan TIDAK boleh menganggap bidang itu ada dan tidak menampilkan "0" sebagai pengganti.
 * - Daftar punya keadaan galat sendiri (+ Coba lagi): gagal memuat tidak berarti "belum ada resep".
 * - "Hitung ulang biaya" menulis ulang harga modal varian dari harga bahan saat ini, maka diminta konfirmasi.
 */
const props = defineProps({
  productId: { type: Number, default: null },
  productName: { type: String, default: '' },
  recipe: { type: Object, default: null },
  variants: { type: Array, default: () => [] },
});
const emit = defineEmits(['changed']);
const { t } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const canEditRecipe = computed(() => auth.hasPermission('menu.recipe.edit'));

const bom = computed(() => bomSummary(props.variants));
const bomCostText = computed(() => {
  if (!bom.value || bom.value.cost === null) return '';
  return bom.value.cost === bom.value.costMax ? formatIDR(bom.value.cost) : `${formatIDR(bom.value.cost)} – ${formatIDR(bom.value.costMax)}`;
});

// --- daftar resep ------------------------------------------------------------------------------------------------
const recipes = ref([]);
const listState = ref('loading'); // loading | ready | error
let seq = 0;

async function loadRecipes() {
  if (props.productId === null || props.productId === undefined) {
    listState.value = 'ready';
    return;
  }
  const mine = ++seq;
  listState.value = 'loading';
  try {
    const res = await listRecipes(props.productId);
    if (mine !== seq) return;
    recipes.value = res?.data ?? [];
    listState.value = 'ready';
  } catch {
    if (mine !== seq) return;
    listState.value = 'error';
  }
}
onMounted(loadRecipes);
watch(() => props.productId, loadRecipes);

const hasCost = (v) => v.portion_cost !== undefined && v.portion_cost !== null;
const variantsText = (v) => (v.variants ?? []).map((x) => variantName(x)).join(', ') || '—';
const liveRecipes = computed(() => recipes.value.filter((r) => !r.archived));

// --- tambah / ubah / hapus ------------------------------------------------------------------------------------------
const showDrawer = ref(false);
const editing = ref(null); // baris daftar yang diubah; null = resep baru
const drawerProduct = computed(() => ({ id: props.productId, name: props.productName, variants: props.variants }));
const showRecalc = ref(false);
const recalculating = ref(false);

function openCreate() {
  editing.value = null;
  showDrawer.value = true;
}
function openEdit(r) {
  editing.value = r;
  showDrawer.value = true;
}

async function afterSaved() {
  showDrawer.value = false;
  await loadRecipes();
  emit('changed');
}

// Hapus SATU resep. Konfirmasi dulu: tidak bisa dibatalkan; bahan resep itu berhenti terpotong dan harga modal varian
// dihitung ulang dari resep yang tersisa. Penjualan yang sudah tercatat tidak berubah.
const removeTarget = ref(null);
const removingOne = ref(false);
async function performRemoveOne() {
  if (!removeTarget.value) return;
  removingOne.value = true;
  try {
    await removeOneRecipe(removeTarget.value.id);
    removeTarget.value = null;
    showDrawer.value = false;
    toast.success(t('products.rc_removed'));
    await loadRecipes();
    emit('changed');
  } catch {
    removeTarget.value = null;
  } finally {
    removingOne.value = false;
  }
}

// 2026-10-31 (permintaan pemilik) — hapus SEMUA resep item.
const showRemoveAll = ref(false);
const removingAll = ref(false);
async function performRemoveAll() {
  removingAll.value = true;
  try {
    await removeRecipe(props.productId);
    showRemoveAll.value = false;
    showDrawer.value = false;
    toast.success(t('products.rc_removed'));
    await loadRecipes();
    emit('changed');
  } catch {
    showRemoveAll.value = false;
  } finally {
    removingAll.value = false;
  }
}

async function performRecalc() {
  recalculating.value = true;
  try {
    const res = await recalculateRecipeCost(props.productId);
    showRecalc.value = false;
    toast.success(t('products.rc_recalc_done', { count: res?.updated?.length ?? 0 }));
    if (res?.skipped?.length) toast.warning(t('products.rc_recalc_skipped', { count: res.skipped.length }));
    await loadRecipes();
    emit('changed');
  } catch {
    // 409/403 sudah di-toast handler pusat; dialog ditutup supaya pengguna tidak terjebak.
    showRecalc.value = false;
  } finally {
    recalculating.value = false;
  }
}
</script>

<template>
  <ItemSection :title="t('products.sec_recipe')" testid="section-recipe">
    <template v-if="canEditRecipe" #action>
      <BaseButton v-if="liveRecipes.length" variant="secondary" size="sm" data-testid="recipe-recalc" @click="showRecalc = true">{{ t('products.rc_recalc') }}</BaseButton>
      <BaseButton v-if="recipes.length" variant="danger" size="sm" data-testid="recipe-remove" @click="showRemoveAll = true">{{ t('products.rc_remove_all') }}</BaseButton>
      <BaseButton variant="secondary" size="sm" data-testid="recipe-add" @click="openCreate">{{ t('products.rc_create') }}</BaseButton>
    </template>

    <!-- Ringkasan resep aktif dari GET /products/{id} (`recipe`, nilai dari server). Dengan BANYAK resep aktif angkanya
         tampil per baris di daftar (menjumlahkannya di sini berarti menghitung biaya di klien). -->
    <div v-if="recipe && (recipe.recipe_count ?? 1) <= 1" class="flex flex-col gap-3 px-5 py-4" data-testid="recipe-summary">
      <p class="text-[13.5px] font-semibold">
        {{ t('products.recipe_cost', { cost: recipe.portion_cost === null || recipe.portion_cost === undefined ? '—' : formatIDR(recipe.portion_cost), pct: recipe.food_cost_pct ?? '—' }) }}
        <span v-if="recipe.incomplete" class="ml-2 text-warn-text">{{ t('products.recipe_incomplete') }}</span>
      </p>
      <p v-if="recipe.gross_margin !== null && recipe.gross_margin !== undefined" class="text-[13px] text-muted-4">{{ t('products.recipe_margin', { margin: formatIDR(recipe.gross_margin) }) }}</p>
    </div>
    <p v-if="listState === 'ready' && !recipes.length && bom" class="px-5 py-5 text-[13.5px] text-muted-5" data-testid="bom-summary">
      {{ bomCostText ? t('products.recipe_bom_summary', { count: bom.count, cost: bomCostText }) : t('products.recipe_bom_summary_nocost', { count: bom.count }) }}
    </p>
    <p v-else-if="listState === 'ready' && !recipes.length" class="px-5 py-6 text-[13px] text-muted-4" data-testid="recipe-empty">{{ t('products.recipe_none') }}</p>

    <div class="border-t border-line" data-testid="recipe-list">
      <p v-if="liveRecipes.length > 1" class="px-5 pt-3 text-[12.5px] text-muted-5" data-testid="recipe-sum-note">{{ t('products.rc_sum_note', { count: liveRecipes.length }) }}</p>
      <p v-if="listState === 'loading'" class="px-5 py-4 text-[13px] text-muted-4" role="status">{{ t('common.loading_data') }}</p>
      <div v-else-if="listState === 'error'" class="flex flex-wrap items-center gap-3 px-5 py-4" role="alert" data-testid="recipe-list-error">
        <span class="text-[13px] text-danger-text">{{ t('products.rc_list_failed') }}</span>
        <BaseButton variant="secondary" size="sm" @click="loadRecipes">{{ t('common.retry') }}</BaseButton>
      </div>
      <SectionTable v-else-if="recipes.length">
        <template #head>
          <tr>
            <th scope="col">{{ t('products.rc_col_name') }}</th>
            <th scope="col">{{ t('products.rc_yield') }}</th>
            <th scope="col">{{ t('products.rc_col_from') }}</th>
            <th scope="col">{{ t('products.rc_col_variants') }}</th>
            <th v-if="recipes.some(hasCost)" scope="col">{{ t('products.rc_col_cost') }}</th>
            <th v-if="canEditRecipe" scope="col"><span class="sr-only">{{ t('products.rc_col_actions') }}</span></th>
          </tr>
        </template>
        <tr v-for="r in recipes" :key="r.id" data-testid="recipe-row">
          <td class="font-semibold">
            <span>{{ r.name }}</span>
            <StatusPill v-if="r.archived" variant="neutral" class="ml-2" data-testid="recipe-archived">{{ t('products.rc_archived') }}</StatusPill>
            <StatusPill v-else-if="r.is_active_now" variant="success" class="ml-2" data-testid="recipe-active">{{ t('products.rc_active_now') }}</StatusPill>
            <span v-if="r.note" class="block text-[11.5px] font-normal text-muted-4">{{ r.note }}</span>
          </td>
          <td class="tabular-nums">{{ r.yield }}</td>
          <td class="tabular-nums">{{ formatDate(r.effective_from) }}</td>
          <td class="text-muted-4">{{ variantsText(r) }}</td>
          <td v-if="recipes.some(hasCost)" class="tabular-nums" data-testid="recipe-cost">
            <template v-if="hasCost(r)">
              {{ t('products.rc_recipe_cost', { cost: formatIDR(r.portion_cost), pct: r.food_cost_pct ?? '—' }) }}
              <span v-if="r.incomplete" class="ml-1 text-warn-text">{{ t('products.recipe_incomplete') }}</span>
            </template>
            <template v-else>—</template>
          </td>
          <td v-if="canEditRecipe" class="whitespace-nowrap text-right">
            <template v-if="!r.archived">
              <BaseButton variant="secondary" size="sm" data-testid="recipe-edit" @click="openEdit(r)">{{ t('products.rc_edit') }}</BaseButton>
              <BaseButton variant="danger" size="sm" class="ml-1.5" data-testid="recipe-remove-one" @click="removeTarget = r">{{ t('products.rc_remove_one') }}</BaseButton>
            </template>
          </td>
        </tr>
      </SectionTable>
    </div>

    <template v-if="canEditRecipe">
      <RecipeDrawer :open="showDrawer" :product="drawerProduct" :recipe="editing" @close="showDrawer = false" @saved="afterSaved" @remove="removeTarget = editing" />
      <ConfirmDialog
        :open="removeTarget !== null"
        :title="t('products.rc_remove_one_title')"
        :message="t('products.rc_remove_one_message', { name: removeTarget?.name ?? '' })"
        :confirm-label="t('products.rc_remove_one')"
        :loading="removingOne"
        @close="removeTarget = null"
        @confirm="performRemoveOne"
      />
      <ConfirmDialog
        :open="showRemoveAll"
        :title="t('products.rc_remove_title')"
        :message="t('products.rc_remove_message', { count: recipes.length })"
        :confirm-label="t('products.rc_remove_all')"
        :loading="removingAll"
        @close="showRemoveAll = false"
        @confirm="performRemoveAll"
      />
      <ConfirmDialog
        :open="showRecalc"
        :title="t('products.rc_recalc_title')"
        :message="t('products.rc_recalc_message')"
        :confirm-label="t('products.rc_recalc')"
        :loading="recalculating"
        @close="showRecalc = false"
        @confirm="performRecalc"
      />
    </template>
  </ItemSection>
</template>
