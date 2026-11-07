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

  it('shows the outlet name on a cart item when present', async () => {
    const { pinia } = renderPanel();
    const cart = usePosCartStore(pinia);
    cart.add({ variant_id: 1, sku: 'SKU1', name: 'Produk A', sell_price: '10000.00', current_stock: 5, home_outlet_name: 'Ryu Illustration' });
    await Promise.resolve();
    expect(screen.getByText('Ryu Illustration')).toBeInTheDocument();
  });

  it('shows and enlarges a cart item image when one is present', async () => {
    const user = userEvent.setup();
    const { pinia } = renderPanel();
    const cart = usePosCartStore(pinia);
    cart.add({ variant_id: 1, sku: 'SKU1', name: 'Produk A', sell_price: '10000.00', current_stock: 5, image_url: 'https://example.test/a.png' });
    await Promise.resolve();

    const thumb = screen.getByAltText('Produk A');
    expect(thumb).toHaveAttribute('src', 'https://example.test/a.png');

    await user.click(screen.getByRole('button', { name: 'Perbesar gambar Produk A' }));
    const enlarged = await screen.findAllByAltText('Produk A');
    expect(enlarged.length).toBeGreaterThan(1);
  });

  // 087 — baris tanpa stok sendiri (current_stock null) tidak dibatasi jumlahnya.
  it('never disables + for an item without its own stock', () => {
    const { pinia } = renderPanel();
    usePosCartStore(pinia).add({ variant_id: 9, sku: 'IKU', name: 'Indomie Kuah', sell_price: '15000.00', current_stock: null });
    return Promise.resolve().then(() => {
      expect(screen.getByRole('button', { name: 'Tambah Indomie Kuah' })).not.toBeDisabled();
    });
  });

  // 092 — kenapa tombol + berhenti: bahan resep penghambat (angka batas = stok menu dari server).
  describe('recipe stock limit', () => {
    const limiting = { material_id: 7, name: 'Telur', unit: 'butir', on_hand: '3.000', per_portion: '1.000' };

    it('names the limiting ingredient once the quantity reaches the menu stock, and stops the + button', async () => {
      const { pinia } = renderPanel();
      const cart = usePosCartStore(pinia);
      cart.add({ variant_id: 1, sku: 'SKU1', name: 'Nasi Goreng', sell_price: '20000.00', current_stock: 1, stock_basis: 'recipe', stock_limiting: limiting });
      await Promise.resolve();
      expect(screen.getByTestId('cart-recipe-limit')).toHaveTextContent(/maksimal 1 porsi.*telur/i);
      expect(screen.getByRole('button', { name: /tambah|increase/i })).toBeDisabled();
    });

    it('shows no hint while below the limit', async () => {
      const { pinia } = renderPanel();
      const cart = usePosCartStore(pinia);
      cart.add({ variant_id: 1, sku: 'SKU1', name: 'Nasi Goreng', sell_price: '20000.00', current_stock: 5, stock_basis: 'recipe', stock_limiting: limiting });
      await Promise.resolve();
      expect(screen.queryByTestId('cart-recipe-limit')).toBeNull();
    });

    it('shows no hint for a non-recipe item even at its stock limit', async () => {
      const { pinia } = renderPanel();
      const cart = usePosCartStore(pinia);
      cart.add({ variant_id: 2, sku: 'SKU2', name: 'Kopi', sell_price: '10000.00', current_stock: 1 });
      await Promise.resolve();
      expect(screen.queryByTestId('cart-recipe-limit')).toBeNull();
    });
  });
});

