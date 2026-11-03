import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PosView from '../../resources/js/views/PosView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { usePosCartStore } from '../../resources/js/stores/posCart';
import { currentSession } from '../../resources/js/api/sessions';
import { listCategories } from '../../resources/js/api/categories';
import { listArtists } from '../../resources/js/api/artists';
import { listProducts, lookupVariants } from '../../resources/js/api/products';

vi.mock('../../resources/js/api/sessions', () => ({ currentSession: vi.fn(), openSession: vi.fn(), closeSession: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn(), lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ createOrder: vi.fn() }));

const variant = (id, name, stock = 7) => ({ id, sku: `VLC-PI-V-${id}`, variant_name: name, sell_price: '15000.00', current_stock: stock, is_active: true, image_url: null });
const PRODUCTS = [
  { id: 1, name: 'CATS!!!', artist_name: 'vlaisca', category_id: 1, image_url: null, variants: [variant(10, 'Orange cat'), variant(11, 'Black cat')] },
  { id: 2, name: 'Solo', artist_name: 'vlaisca', category_id: 1, image_url: null, variants: [variant(20, 'Only')] },
];

function renderPos() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Kasir', name: 'Kasir', menu_keys: ['dashboard', 'pos', 'session'] };
  const utils = render(PosView, { global: { plugins: [pinia] } });
  return { cart: usePosCartStore(pinia), ...utils };
}

// 054 (US1) — pemilih varian tetap terbuka sampai kasir menekan Tutup.
describe('PosView — variant picker stays open (054)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null);
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Pins', code: 'PI' }] });
    listArtists.mockResolvedValue({ data: [] });
    listProducts.mockResolvedValue({ data: PRODUCTS });
    lookupVariants.mockResolvedValue({ data: [] });
  });

  it('adds several variants with the picker open, then Close keeps the cart', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { cart } = renderPos();

    await user.click(await screen.findByText('CATS!!!'));
    await user.click(await screen.findByText('Orange cat'));
    await user.click(screen.getByText('Black cat'));
    await user.click(screen.getByText('Black cat'));

    expect(cart.items.map((i) => [i.variant_id, i.qty])).toEqual([[10, 1], [11, 2]]);
    // masih terbuka: tombol Tutup di bawah ada dan jumlah di keranjang tampil
    expect(screen.getByTestId('picker-close')).toBeInTheDocument();
    expect(screen.getByText('2 di keranjang')).toBeInTheDocument();

    await user.click(screen.getByTestId('picker-close'));
    await waitFor(() => expect(screen.queryByTestId('picker-close')).not.toBeInTheDocument());
    expect(cart.items).toHaveLength(2);
  });

  it('still adds a single-variant product straight to the cart without a picker', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { cart } = renderPos();

    await user.click(await screen.findByText('Solo'));

    expect(cart.items).toHaveLength(1);
    expect(screen.queryByTestId('picker-close')).not.toBeInTheDocument();
  });
});
