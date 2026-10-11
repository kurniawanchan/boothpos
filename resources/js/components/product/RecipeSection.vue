<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listRecipes, recalculateRecipeCost } from '../../api/recipes';
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
 * 043 (US2/US8) — "Resep (BOM)". Ringkasan resep AKTIF dari `GET /products/{id}` (`recipe`; nilai dari server, tidak
 * dihitung ulang di sini), ringkasan BOM lama bila ada varian ber-BOM, dan daftar VERSI resep
 * (`GET /products/{id}/recipes`: versi, tanggal berlaku, "berlaku sekarang", biaya porsi / food cost %).
 *
 * - "Edit resep" dan "Hitung ulang biaya" dirender HANYA untuk pemegang `menu.recipe.edit` (tersembunyi, bukan
 *   nonaktif); server tetap menolak yang lain (403). Mengubah resep selalu membuat versi baru lewat RecipeDrawer.
 * - Angka biaya dirender hanya bila payload membawanya: server menghilangkan biaya untuk pengguna tanpa izin biaya,
 *   jadi tampilan TIDAK boleh menganggap bidang itu ada dan tidak menampilkan "0" sebagai pengganti.
 * - Daftar versi punya keadaan galat sendiri (+ Coba lagi): gagal memuat versi tidak mengosongkan ringkasan yang
 *   sudah ada, dan bukan "belum ada versi".
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
const title = computed(() => (props.recipe?.version ? `${t('products.sec_recipe')} v${props.recipe.version}` : t('products.sec_recipe')));

// --- versi -------------------------------------------------------------------------------------------------------
const versions = ref([]);
const versionsState = ref('loading'); // loading | ready | error
let seq = 0;

async function loadVersions() {
  if (props.productId === null || props.productId === undefined) {
    versionsState.value = 'ready';
    return;
  }
  const mine = ++seq;
  versionsState.value = 'loading';
  try {
    const res = await listRecipes(props.productId);
    if (mine !== seq) return;
    versions.value = res?.data ?? [];
    versionsState.value = 'ready';
  } catch {
    if (mine !== seq) return;
    versionsState.value = 'error';
  }
}
onMounted(loadVersions);
watch(() => props.productId, loadVersions);

const hasCost = (v) => v.portion_cost !== undefined && v.portion_cost !== null;
const variantsText = (v) => (v.variants ?? []).map((x) => variantName(x)).join(', ') || '—';

// --- edit & hitung ulang ---------------------------------------------------------------------------------------------
const showDrawer = ref(false);
const drawerProduct = computed(() => ({ id: props.productId, name: props.productName, variants: props.variants }));
const showRecalc = ref(false);
const recalculating = ref(false);

async function afterSaved() {
  showDrawer.value = false;
  await loadVersions();
  emit('changed');
}

async function performRecalc() {
  recalculating.value = true;
  try {
    const res = await recalculateRecipeCost(props.productId);
    showRecalc.value = false;
    toast.success(t('products.rc_recalc_done', { count: res?.updated?.length ?? 0 }));
    if (res?.skipped?.length) toast.warning(t('products.rc_recalc_skipped', { count: res.skipped.length }));
    await loadVersions();
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
  <ItemSection :title="title" testid="section-recipe">
    <template v-if="canEditRecipe" #action>
      <BaseButton v-if="versions.length" variant="secondary" size="sm" data-testid="recipe-recalc" @click="showRecalc = true">{{ t('products.rc_recalc') }}</BaseButton>
      <BaseButton variant="secondary" size="sm" data-testid="recipe-edit" @click="showDrawer = true">{{ versions.length ? t('products.rc_edit') : t('products.rc_create') }}</BaseButton>
    </template>

    <div v-if="recipe" class="flex flex-col gap-3 px-5 py-4" data-testid="recipe-summary">
      <p class="text-[13.5px] font-semibold">
        {{ t('products.recipe_cost', { cost: recipe.portion_cost === null || recipe.portion_cost === undefined ? '—' : formatIDR(recipe.portion_cost), pct: recipe.food_cost_pct ?? '—' }) }}
        <span v-if="recipe.incomplete" class="ml-2 text-warn-text">{{ t('products.recipe_incomplete') }}</span>
      </p>
      <p v-if="recipe.gross_margin !== null && recipe.gross_margin !== undefined" class="text-[13px] text-muted-4">{{ t('products.recipe_margin', { margin: formatIDR(recipe.gross_margin) }) }}</p>
      <ul v-if="recipe.lines?.length" class="flex flex-col divide-y divide-line border border-line text-[12.5px]">
        <li v-for="(l, i) in recipe.lines" :key="l.material_id ?? i" class="flex justify-between gap-3 px-3 py-2">
          <span>{{ l.name }} · {{ l.quantity }} {{ l.unit }}</span>
          <span class="tabular-nums text-muted-4">{{ l.line_cost === null || l.line_cost === undefined ? '—' : formatIDR(l.line_cost) }}</span>
        </li>
      </ul>
    </div>
    <p v-else-if="bom" class="px-5 py-5 text-[13.5px] text-muted-5" data-testid="bom-summary">
      {{ bomCostText ? t('products.recipe_bom_summary', { count: bom.count, cost: bomCostText }) : t('products.recipe_bom_summary_nocost', { count: bom.count }) }}
    </p>
    <p v-else class="px-5 py-6 text-[13px] text-muted-4" data-testid="recipe-empty">{{ t('products.recipe_none') }}</p>

    <div class="border-t border-line" data-testid="recipe-versions">
      <h3 class="px-5 pb-1 pt-3 text-[13px] font-semibold text-muted-5">{{ t('products.rc_versions') }}</h3>
      <p v-if="versionsState === 'loading'" class="px-5 py-4 text-[13px] text-muted-4" role="status">{{ t('common.loading_data') }}</p>
      <div v-else-if="versionsState === 'error'" class="flex flex-wrap items-center gap-3 px-5 py-4" role="alert" data-testid="recipe-versions-error">
        <span class="text-[13px] text-danger-text">{{ t('products.rc_versions_failed') }}</span>
        <BaseButton variant="secondary" size="sm" @click="loadVersions">{{ t('common.retry') }}</BaseButton>
      </div>
      <p v-else-if="!versions.length" class="px-5 py-4 text-[13px] text-muted-4" data-testid="recipe-versions-empty">{{ t('products.rc_versions_empty') }}</p>
      <SectionTable v-else>
        <template #head>
          <tr>
            <th scope="col">{{ t('products.rc_col_version') }}</th>
            <th scope="col">{{ t('products.rc_col_from') }}</th>
            <th scope="col">{{ t('products.rc_col_variants') }}</th>
            <th v-if="versions.some(hasCost)" scope="col">{{ t('products.rc_col_cost') }}</th>
            <th scope="col">{{ t('products.rc_note') }}</th>
          </tr>
        </template>
        <tr v-for="v in versions" :key="v.id" data-testid="recipe-version-row">
          <td class="font-semibold">
            <span>v{{ v.version }}</span>
            <StatusPill v-if="v.is_active_now" variant="success" class="ml-2" data-testid="version-active">{{ t('products.rc_active_now') }}</StatusPill>
          </td>
          <td class="tabular-nums">{{ formatDate(v.effective_from) }}</td>
          <td class="text-muted-4">{{ variantsText(v) }}</td>
          <td v-if="versions.some(hasCost)" class="tabular-nums" data-testid="version-cost">
            <template v-if="hasCost(v)">
              {{ t('products.rc_version_cost', { cost: formatIDR(v.portion_cost), pct: v.food_cost_pct ?? '—' }) }}
              <span v-if="v.incomplete" class="ml-1 text-warn-text">{{ t('products.recipe_incomplete') }}</span>
            </template>
            <template v-else>—</template>
          </td>
          <td class="text-muted-4">
            <span v-if="v.note">{{ v.note }}</span>
            <span v-if="v.created_by" class="block text-[11.5px]">{{ t('products.rc_by', { name: v.created_by }) }}</span>
          </td>
        </tr>
      </SectionTable>
    </div>

    <template v-if="canEditRecipe">
      <RecipeDrawer :open="showDrawer" :product="drawerProduct" :versions="versions" @close="showDrawer = false" @saved="afterSaved" />
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
