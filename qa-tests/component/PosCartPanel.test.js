import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import PosCartPanel from '../../resources/js/components/pos/PosCartPanel.vue';
import { usePosCartStore } from '../../resources/js/stores/posCart';

function renderPanel(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return { ...render(PosCartPanel, { props, global: { plugins: [pinia] } }), pinia };
}

describe('PosCartPanel', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('shows the empty-cart message when there are no items', () => {
    renderPanel();
    expect(screen.getByText(/pilih produk untuk mulai transaksi/i)).toBeInTheDocument();
  });

  it('disables the pay button while the cart is empty', () => {
    renderPanel();
    expect(screen.getByRole('button', { name: /bayar/i })).toBeDisabled();
  });

  it('enables the pay button and shows the total once an item is added', async () => {
    const { pinia } = renderPanel();
    const cart = usePosCartStore(pinia);
    cart.add({ variant_id: 1, sku: 'RYUKYSAK0007', name: 'Keychain Sakura', sell_price: '45000.00', current_stock: 10 });
    await Promise.resolve();
    expect(screen.getByRole('button', { name: /bayar/i })).toBeEnabled();
    expect(screen.getAllByText('Rp 45.000').length).toBeGreaterThan(0);
  });

  it('emits pay when the button is clicked with items in the cart', async () => {
    const user = userEvent.setup();
    const { pinia, emitted } = renderPanel();
    const cart = usePosCartStore(pinia);
    cart.add({ variant_id: 1, sku: 'SKU1', name: 'Produk A', sell_price: '10000.00', current_stock: 5 });
    await Promise.resolve();
    await user.click(screen.getByRole('button', { name: /bayar/i }));
    expect(emitted().pay).toBeTruthy();
  });

  it('shows the blocked-checkout reason when canCheckout is false', () => {
    renderPanel({ canCheckout: false, checkoutBlockedReason: 'Buka sesi kasir terlebih dahulu.' });
    expect(screen.getByText('Buka sesi kasir terlebih dahulu.')).toBeInTheDocument();
  });
});
