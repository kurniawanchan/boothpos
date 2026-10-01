<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import { splitPreorder } from '../../api/preorders';
import { formatIDR, parseMoney } from '../../utils/money';

/**
 * 027-preorder-duplicate-split (US3/US4) — dialog "Pisah pre-order".
 *
 * Pengguna menentukan berapa UNIT per baris yang pindah ke pre-order baru.
 * Angka pratinjau di sini murni tampilan: server menghitung ulang semuanya
 * (harga snapshot, subtotal, batas diskon) dan menjadi satu-satunya sumber
 * kebenaran. Ongkir, diskon, catatan, dan data pengiriman tetap di pesanan
 * asal (spec FR-015), jadi pratinjau "tersisa" memakai diskon/ongkir yang sama.
 *
 * 409 (status/pembayaran/diskon) sudah ditoast interceptor global; 422 (salah
 * nilai) ditampilkan inline di bawah daftar.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  // Payload detail pre-order (GET /preorders/{id}): id, preorder_number, items[], subtotal, shipping_cost, discount.
  preorder: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const { t } = useI18n();

const moveQty = reactive({}); // item.id → unit yang dipindah
const submitting = ref(false);
const errorMessage = ref('');

const lines = computed(() => props.preorder?.items ?? []);

watch(
  () => [props.open, props.preorder?.id],
  () => {
    Object.keys(moveQty).forEach((k) => delete moveQty[k]);
    lines.value.forEach((l) => { moveQty[l.id] = 0; });
    errorMessage.value = '';
  },
  { immediate: true },
);

function setQty(line, raw) {
  const n = Math.floor(Number(raw));
  moveQty[line.id] = Number.isFinite(n) ? Math.min(Math.max(n, 0), line.qty) : 0;
}
function bump(line, delta) {
  setQty(line, (moveQty[line.id] ?? 0) + delta);
}

const totalUnits = computed(() => lines.value.reduce((sum, l) => sum + l.qty, 0));
const movedUnits = computed(() => lines.value.reduce((sum, l) => sum + (moveQty[l.id] ?? 0), 0));
const canSubmit = computed(() => movedUnits.value >= 1 && movedUnits.value < totalUnits.value);

const movedSubtotal = computed(() =>
  lines.value.reduce((sum, l) => sum + parseMoney(l.sell_price) * (moveQty[l.id] ?? 0), 0),
);
const remainingSubtotal = computed(() => parseMoney(props.preorder?.subtotal) - movedSubtotal.value);
const shippingCost = computed(() => parseMoney(props.preorder?.shipping_cost));
const discount = computed(() => parseMoney(props.preorder?.discount));
const remainingTotal = computed(() => remainingSubtotal.value + shippingCost.value - discount.value);
const discountTooHigh = computed(() => discount.value > remainingSubtotal.value + shippingCost.value);

// "Pisah per penjual" hanya ditawarkan bila ada ≥ 2 penjual berbeda; server
// tetap menolak (422) bila ternyata hanya satu. Penjual dari baris pertama
// tetap di pesanan asal — ditentukan server, bukan di sini.
const sellerCount = computed(() => new Set(lines.value.map((l) => l.artist_id).filter((id) => id != null)).size);
const canSplitBySeller = computed(() => sellerCount.value >= 2);

async function submit(mode = 'items') {
  if (submitting.value) return;
  if (mode === 'items' && !canSubmit.value) return;
  submitting.value = true;
  errorMessage.value = '';
  try {
    const payload = mode === 'by_seller'
      ? { mode: 'by_seller' }
      : {
          mode: 'items',
          items: lines.value
            .filter((l) => (moveQty[l.id] ?? 0) > 0)
            .map((l) => ({ item_id: l.id, qty: moveQty[l.id] })),
        };
    const result = await splitPreorder(props.preorder.id, payload);
    emit('done', result);
  } catch (err) {
    if (err?.status === 422) errorMessage.value = err.message;
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <BaseModal
    :open="open"
    :title="t('preorders.split_title', { number: preorder?.preorder_number ?? '' })"
    max-width-class="max-w-[640px]"
    @close="emit('close')"
  >
    <div v-if="preorder" class="flex flex-col gap-4 px-6 py-5">
      <p class="text-[12.5px] leading-relaxed text-muted-3">
        {{ t('preorders.split_intro', { number: preorder.preorder_number }) }}
      </p>

      <ul class="flex flex-col divide-y divide-line-6 rounded-lg border border-line-2">
        <li v-for="line in lines" :key="line.id" class="flex items-center gap-3 px-3 py-2.5" data-testid="split-line">
          <div class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="truncate text-[13px] font-semibold">{{ line.name_snapshot }}</span>
            <span class="font-mono text-[11px] text-muted-3">
              {{ line.sku_snapshot }} · {{ formatIDR(line.sell_price) }}<span v-if="line.artist_name"> · {{ line.artist_name }}</span>
            </span>
          </div>
          <span class="whitespace-nowrap text-[11.5px] text-muted-3">{{ t('preorders.split_of', { qty: line.qty }) }}</span>
          <div class="flex items-center gap-0.5 overflow-hidden rounded-lg border border-line bg-white">
            <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('preorders.decrease')" @click="bump(line, -1)"><i class="ph-duotone ph-minus text-[13px]" aria-hidden="true"></i></button>
            <input
              type="number"
              min="0"
              :max="line.qty"
              class="h-[30px] w-[44px] border-x border-line text-center text-[13px] font-bold outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
              :aria-label="t('preorders.split_qty_input_aria', { name: line.name_snapshot })"
              :value="moveQty[line.id] ?? 0"
              @change="(e) => { setQty(line, e.target.value); e.target.value = moveQty[line.id]; }"
            />
            <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('preorders.increase')" @click="bump(line, 1)"><i class="ph-duotone ph-plus text-[13px]" aria-hidden="true"></i></button>
          </div>
        </li>
      </ul>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="flex flex-col gap-1 rounded-lg border border-line-2 px-3.5 py-3" data-testid="split-preview-stays">
          <span class="text-[11.5px] font-semibold text-muted-3">{{ t('preorders.split_preview_stays', { number: preorder.preorder_number }) }}</span>
          <span class="text-[11.5px] text-muted-3">{{ t('preorders.subtotal') }}: <strong class="text-muted-5">{{ formatIDR(remainingSubtotal) }}</strong></span>
          <span class="text-[15px] font-extrabold">{{ formatIDR(remainingTotal) }}</span>
        </div>
        <div class="flex flex-col gap-1 rounded-lg border border-mint-border bg-mint-50 px-3.5 py-3" data-testid="split-preview-moves">
          <span class="text-[11.5px] font-semibold text-brand-active">{{ t('preorders.split_preview_moves') }}</span>
          <span class="text-[11.5px] text-muted-3">{{ t('preorders.split_units_moved', { count: movedUnits }) }}</span>
          <span class="text-[15px] font-extrabold">{{ formatIDR(movedSubtotal) }}</span>
        </div>
      </div>

      <p v-if="discountTooHigh" role="alert" data-testid="split-discount-warning" class="rounded-lg border border-warn-border bg-warn-bg px-3 py-2 text-[12.5px] font-semibold text-warn-text">
        {{ t('preorders.split_discount_warning', { amount: formatIDR(discount) }) }}
      </p>
      <!-- Selalu tampil (hanya penekanannya yang berubah): dialog ini di-center secara
           vertikal, jadi menghilangkan baris ini saat klik pertama menggeser tombol
           +/- dan klik cepat berikutnya meleset (ditemukan di verifikasi browser). -->
      <p class="text-[11.5px] leading-relaxed" :class="canSubmit ? 'text-muted-3' : 'font-semibold text-muted-5'">{{ t('preorders.split_hint_must') }}</p>
      <p v-if="errorMessage" role="alert" class="rounded-lg bg-danger-bg px-3 py-2 text-[12.5px] font-semibold text-danger-text">{{ errorMessage }}</p>
    </div>

    <template #footer>
      <div class="flex flex-wrap items-center justify-between gap-2.5">
        <BaseButton v-if="canSplitBySeller" variant="secondary" :loading="submitting" data-testid="split-by-seller" @click="submit('by_seller')">
          <i class="ph-duotone ph-users-three" aria-hidden="true"></i>
          {{ t('preorders.split_by_seller') }}
        </BaseButton>
        <span v-else></span>
        <div class="flex gap-2.5">
          <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :disabled="!canSubmit" :loading="submitting" data-testid="split-confirm" @click="submit('items')">{{ t('preorders.split_confirm') }}</BaseButton>
        </div>
      </div>
    </template>
  </BaseModal>
</template>
