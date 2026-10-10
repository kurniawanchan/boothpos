<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import PaymentPanel from '../payment/PaymentPanel.vue';
import { formatIDR, toMoneyString } from '../../utils/money';

/**
 * 028 US5 — pisah tagihan: pilih baris/unit yang dibayar sekarang, lalu bayar. Angka total di sini hanya
 * tampilan (dihitung dalam sen supaya tidak ada selisih pembulatan); penjualan terpisah dan sisanya
 * dihitung ulang server, yang juga menegakkan aturannya (tab bersaldo, baris yang sedang dimasak, dll.).
 *
 * Aturan sebagian-unit: baris yang sudah dikirim tetapi BELUM diantar hanya boleh dipisah UTUH.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  tab: { type: Object, default: null },
  submitting: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'submit']);
const { t } = useI18n();

const qtys = reactive({}); // line.id → jumlah yang dipisah (0 = tidak)
const panelKey = ref(0);

const lines = computed(() => props.tab?.lines ?? []);
const blocked = computed(() => Number(props.tab?.paid_amount ?? 0) > 0);

// Baris terkirim yang belum diantar: tidak boleh sebagian unit.
const wholeOnly = (line) => line.sent && line.prep_status !== 'served';

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    Object.keys(qtys).forEach((k) => delete qtys[k]);
    lines.value.forEach((l) => { qtys[l.id] = 0; });
    panelKey.value += 1;
  },
  { immediate: true }
);

const cents = (money) => Math.round(parseFloat(money) * 100);
const picked = computed(() => lines.value.filter((l) => (qtys[l.id] ?? 0) > 0).map((l) => ({ item_id: l.id, qty: qtys[l.id] })));
const totalCents = computed(() => lines.value.reduce((sum, l) => sum + cents(l.unit_price) * (qtys[l.id] ?? 0), 0));
const total = computed(() => toMoneyString(totalCents.value / 100));
const selectedUnits = computed(() => picked.value.reduce((n, p) => n + p.qty, 0));

function setQty(line, value) {
  let next = Math.max(0, Math.min(line.qty, Number(value) || 0));
  if (wholeOnly(line) && next > 0 && next < line.qty) next = line.qty; // satu klik = baris utuh
  qtys[line.id] = next;
  panelKey.value += 1; // jumlah jatuh tempo berubah → panel pembayaran dimulai dari awal
}

const step = (line, dir) => {
  const cur = qtys[line.id] ?? 0;
  if (wholeOnly(line)) return setQty(line, dir > 0 ? line.qty : 0);
  return setQty(line, cur + dir);
};
</script>

<template>
  <BaseModal :open="open" :title="t('floor.tab.split_title')" max-width-class="max-w-[900px]" @close="emit('close')">
    <p v-if="blocked" role="alert" class="px-[26px] py-6 text-[13.5px] font-semibold text-warn-text">{{ t('floor.tab.split_blocked') }}</p>

    <div v-else class="grid grid-cols-1 md:grid-cols-[1.1fr_1fr]">
      <div class="flex max-h-[65vh] flex-col gap-3 overflow-auto px-[26px] py-6">
        <p class="text-[12.5px] text-muted-3">{{ t('floor.tab.split_hint') }}</p>
        <ul class="flex flex-col divide-y divide-line-3">
          <li v-for="line in lines" :key="line.id" class="flex items-center gap-3 py-2.5" :data-testid="`split-line-${line.id}`">
            <div class="min-w-0 flex-1">
              <div class="text-[13.5px] font-bold leading-snug text-ink">{{ line.name }}</div>
              <div class="text-[12px] text-muted-3">{{ formatIDR(line.unit_price) }} · {{ t('floor.tab.split_on_tab', { n: line.qty }) }}</div>
              <div v-if="wholeOnly(line) && line.qty > 1" class="text-[11.5px] font-semibold text-warn-text">{{ t('floor.tab.split_whole_only') }}</div>
            </div>
            <div class="inline-flex items-center rounded-lg border border-line">
              <button type="button" class="flex h-8 w-8 items-center justify-center text-muted-4 hover:text-brand-active disabled:opacity-40" :aria-label="t('floor.tab.qty_dec', { name: line.name })" :disabled="(qtys[line.id] ?? 0) === 0" @click="step(line, -1)">
                <i class="ph-duotone ph-minus text-[14px]" aria-hidden="true"></i>
              </button>
              <span class="min-w-[28px] text-center text-[13px] font-bold" :data-testid="`split-qty-${line.id}`">{{ qtys[line.id] ?? 0 }}</span>
              <button type="button" class="flex h-8 w-8 items-center justify-center text-muted-4 hover:text-brand-active disabled:opacity-40" :aria-label="t('floor.tab.qty_inc', { name: line.name })" :disabled="(qtys[line.id] ?? 0) >= line.qty" @click="step(line, 1)">
                <i class="ph-duotone ph-plus text-[14px]" aria-hidden="true"></i>
              </button>
            </div>
          </li>
        </ul>
      </div>

      <div class="flex flex-col gap-4 border-t border-line-3 bg-surface-subtle px-[26px] py-6 md:border-l md:border-t-0">
        <div class="flex items-baseline justify-between">
          <span class="text-[13px] font-bold">{{ t('floor.tab.split_selected', { n: selectedUnits }) }}</span>
          <span class="text-[22px] font-extrabold tracking-tight" data-testid="split-total">{{ formatIDR(total) }}</span>
        </div>
        <p v-if="!picked.length" class="text-[12.5px] text-muted-3">{{ t('floor.tab.split_pick_first') }}</p>
        <PaymentPanel
          v-else
          :key="panelKey"
          mode="checkout"
          :due-amount="total"
          :submitting="submitting"
          :submit-label="t('floor.tab.split_confirm')"
          @submit="(payments) => emit('submit', { items: picked, payments })"
        />
      </div>
    </div>
  </BaseModal>
</template>
