import { defineStore } from 'pinia';
import { parseMoney, toMoneyString } from '../utils/money';

/**
 * The POS cart is shared between the sidebar badge (AppSidebar) and the
 * Kasir screen (PosView + PosCartPanel + PosPaymentModal) — three
 * components with no closer common ancestor than the app root, and it
 * deliberately survives navigating away from /pos and back so a cashier
 * doesn't lose an in-progress sale by accidentally clicking another nav
 * item. That combination is the practical justification for a Pinia
 * store here rather than colocated component state.
 */
export const usePosCartStore = defineStore('posCart', {
  state: () => ({
    items: [], // { variant_id, sku, name, artist_name, sell_price, current_stock, qty }
  }),
  getters: {
    count: (state) => state.items.reduce((sum, i) => sum + i.qty, 0),
    isEmpty: (state) => state.items.length === 0,
    subtotal: (state) => toMoneyString(state.items.reduce((sum, i) => sum + parseMoney(i.sell_price) * i.qty, 0)),
  },
  actions: {
    add(variant) {
      const existing = this.items.find((i) => i.variant_id === variant.variant_id);
      if (existing) {
        existing.qty += 1;
        return;
      }
      this.items.push({ ...variant, qty: 1 });
    },
    increment(variantId) {
      const item = this.items.find((i) => i.variant_id === variantId);
      if (item) item.qty += 1;
    },
    decrement(variantId) {
      const item = this.items.find((i) => i.variant_id === variantId);
      if (!item) return;
      item.qty -= 1;
      if (item.qty <= 0) this.remove(variantId);
    },
    remove(variantId) {
      this.items = this.items.filter((i) => i.variant_id !== variantId);
    },
    clear() {
      this.items = [];
    },
  },
});
