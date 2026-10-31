<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePosCartStore, cartLineKey } from '../../stores/posCart';
import { useSettingsStore } from '../../stores/settings';
import { formatIDR, parseMoney, toMoneyString } from '../../utils/money';
import { cartTotals } from '../../utils/posTotals';
import EmptyState from '../ui/EmptyState.vue';
import BaseButton from '../ui/BaseButton.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';

const props = defineProps({
  discount: { type: Number, default: 0 },
  customerName: { type: String, default: '' },
  canCheckout: { type: Boolean, default: true },
  checkoutBlockedReason: { type: String, default: '' },
});
const emit = defineEmits(['update:discount', 'pick-customer', 'pay']);

const cart = usePosCartStore();
const settings = useSettingsStore();
const { t } = useI18n();

// 048 — pratinjau subtotal, diskon, pajak, dan total; rumusnya sama dengan server (lihat utils/posTotals.js).
const totals = computed(() => cartTotals({ subtotal: cart.subtotal, discount: props.discount, taxRate: settings.effectiveTaxRate }));
const taxName = computed(() => settings.taxLabel || t('pos.tax'));

const lightboxSrc = ref(null);
const lightboxAlt = ref('');
function openImageLightbox(item) {
  if (!item.image_url) return;
  lightboxSrc.value = item.image_url;
  lightboxAlt.value = item.name;
}
</script>

<template>
  <aside aria-label="Keranjang" class="flex h-full w-[372px] flex-none flex-col border-l border-line-2 bg-white">
    <div class="flex items-center justify-between gap-2.5 border-b border-line-3 px-[18px] pb-3 pt-[18px]">
      <div class="flex flex-col gap-0.5">
        <span class="text-[15px] font-bold tracking-tight">{{ t('pos.cart') }}</span>
        <span class="text-[11.5px] text-muted-3">{{ t('pos.item_count', { count: cart.count }) }}</span>
      </div>
      <button
        type="button"
        class="rounded-md px-2 py-1.5 text-[12.5px] font-semibold text-danger-text transition-colors hover:bg-danger-bg disabled:opacity-40"
        :disabled="cart.isEmpty"
        @click="cart.clear()"
      >
        {{ t('pos.empty_cart') }}
      </button>
    </div>

    <div class="flex-1 overflow-auto px-3 py-2">
      <EmptyState v-if="cart.isEmpty" icon="ph-shopping-cart-simple" :message="t('pos.empty_cart_message')" />
      <div v-for="item in cart.items" :key="cartLineKey(item)" class="flex gap-2.5 border-b border-line-6 py-2.5 last:border-b-0">
        <button
          v-if="item.image_url"
          type="button"
          class="h-11 w-11 flex-none cursor-zoom-in"
          :aria-label="t('master_data.enlarge_variant_image', { name: item.name })"
          @click="openImageLightbox(item)"
        >
          <img :src="item.image_url" :alt="item.name" class="h-11 w-11 rounded-md border border-line-2 object-cover" />
        </button>
        <div class="flex min-w-0 flex-1 flex-col gap-1">
          <span class="text-[13.5px] font-semibold leading-tight">{{ item.name }}</span>
          <!-- 084 — pilihan modifier di bawah nama item (struk dan detail pesanan membaca snapshot server yang sama) -->
          <span v-if="item.modifiers?.length" class="text-[11.5px] leading-snug text-muted-4" data-testid="cart-modifiers">{{ item.modifiers.map((m) => m.option).join(', ') }}</span>
          <span class="font-mono text-[10.5px] text-muted-3">{{ item.sku }}</span>
          <span v-if="item.home_outlet_name" class="text-[10.5px] text-muted-3">{{ item.home_outlet_name }}</span>
          <span class="text-[12px] text-muted">{{ formatIDR(item.sell_price) }}</span>
        </div>
        <div class="flex flex-col items-end gap-1.5">
          <span class="text-[13.5px] font-bold">{{ formatIDR(parseMoney(item.sell_price) * item.qty) }}</span>
          <div class="flex items-center gap-0.5 overflow-hidden rounded-lg border border-line">
            <button type="button" class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('pos.decrease_item', { name: item.name })" @click="cart.decrement(cartLineKey(item))">
              <i class="ph-duotone ph-minus text-[14px]" aria-hidden="true"></i>
            </button>
            <span class="min-w-[26px] text-center text-[13.5px] font-bold">{{ item.qty }}</span>
            <button
              type="button"
              class="flex h-[30px] w-[30px] items-center justify-center text-muted-5 hover:bg-line-7 disabled:opacity-30"
              :disabled="item.current_stock !== null && item.current_stock !== undefined && item.qty >= item.current_stock"
              :aria-label="t('pos.increase_item', { name: item.name })"
              @click="cart.increment(cartLineKey(item))"
            >
              <i class="ph-duotone ph-plus text-[14px]" aria-hidden="true"></i>
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="flex flex-col gap-3 border-t border-line-3 bg-surface-subtle px-[18px] pb-[18px] pt-3.5">
      <button
        type="button"
        class="flex items-center gap-2 self-start rounded-md px-2 py-1.5 text-[12.5px] font-semibold text-muted-4 transition-colors hover:bg-line-7"
        @click="emit('pick-customer')"
      >
        <i class="ph-duotone ph-user-plus text-[16px]" aria-hidden="true"></i>
        {{ customerName || t('pos.walkin_customer') }}
      </button>

      <label class="flex items-center justify-between gap-3 text-[13px]">
        <span class="text-muted">{{ t('pos.discount_rp') }}</span>
        <input
          type="number"
          min="0"
          class="w-28 rounded-md border border-line px-2.5 py-1.5 text-right text-[13px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          :value="discount"
          :aria-label="t('pos.discount_amount_label')"
          @input="emit('update:discount', Number($event.target.value) || 0)"
        />
      </label>
      <div class="flex items-center justify-between text-[13px]"><span class="text-muted">{{ t('pos.subtotal') }}</span><span class="font-semibold">{{ formatIDR(totals.subtotal) }}</span></div>
      <div class="flex items-center justify-between text-[13px]"><span class="text-muted">{{ t('pos.discount') }}</span><span class="font-semibold text-danger-text">{{ formatIDR(totals.discount) }}</span></div>
      <div v-if="settings.showTaxLine" class="flex items-center justify-between text-[13px]"><span class="text-muted">{{ taxName }} ({{ settings.taxRate }}%)</span><span class="font-semibold">{{ formatIDR(totals.tax) }}</span></div>
      <div class="flex items-baseline justify-between border-t border-dashed border-line-2 pt-2.5">
        <span class="text-[14px] font-bold">{{ t('pos.total') }}</span>
        <span class="text-[25px] font-extrabold tracking-tight">{{ formatIDR(totals.total) }}</span>
      </div>
      <BaseButton size="lg" class="w-full" :disabled="cart.isEmpty || !canCheckout" @click="emit('pay')">
        {{ t('pos.pay') }}<i class="ph-duotone ph-arrow-right text-[18px]" aria-hidden="true"></i>
      </BaseButton>
      <p v-if="!canCheckout && checkoutBlockedReason" class="text-center text-[11.5px] text-danger-text">{{ checkoutBlockedReason }}</p>
    </div>
  </aside>

  <ImageLightbox :open="!!lightboxSrc" :src="lightboxSrc" :alt="lightboxAlt" @close="lightboxSrc = null" />
</template>
