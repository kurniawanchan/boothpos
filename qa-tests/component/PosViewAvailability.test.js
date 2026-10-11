import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PosView from '../../resources/js/views/PosView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { usePosCartStore } from '../../resources/js/stores/posCart';
import { useToastStore } from '../../resources/js/stores/toast';
import { currentSession } from '../../resources/js/api/sessions';
import { listCategories } from '../../resources/js/api/categories';
import { listArtists } from '../../resources/js/api/artists';
import { listProducts, lookupVariants } from '../../resources/js/api/products';
import { pollAvailability } from '../../resources/js/api/menu';

vi.mock('../../resources/js/api/sessions', () => ({ currentSession: vi.fn(), openSession: vi.fn(), closeSession: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn(), lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ createOrder: vi.fn() }));
vi.mock('../../resources/js/api/menu', () => ({ pollAvailability: vi.fn() }));

const variant = (id, name) => ({ id, sku: `SKU-${id}`, variant_name: name, sell_price: '25000.00', current_stock: 5, is_active: true });
const PRODUCTS = [
  { id: 1, name: 'Kopi Susu', artist_name: 'Restoran', category_id: 1, image_url: null, variants: [variant(11, 'Standard')] },
  { id: 2, name: 'Es Jeruk', artist_name: 'Restoran', category_id: 1, image_url: null, variants: [variant(21, 'Standard')] },
];

// Item 2 sedang 86; item 3 didiskontinu (tidak ada di daftar browse, hanya relevan di pencarian lewat variant_id).
const AVAILABILITY = { etag: 'e1', menu_version: 1, sold_out: [{ product_id: 2, variant_ids: [21], reason_code: 'out_of_stock', expires_at: '2026-10-11T23:59:00+07:00' }], discontinued_ids: [] };

function renderPos() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Kasir', name: 'Kasir', menu_keys: ['dashboard', 'pos', 'session'] };
  return { ...render(PosView, { global: { plugins: [pinia], stubs: { RouterLink: { template: '<a><slot /></a>' } } } }), cart: usePosCartStore(), toast: useToastStore() };
}
async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

// 043 (T044) — layar POS membaca ketersediaan menu: kartu 86 redup, berlabel, dan tidak bisa ditambahkan.
describe('PosView — menu availability (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue({ id: 1 });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Minuman', code: 'MN' }] });
    listArtists.mockResolvedValue({ data: [] });
    listProducts.mockResolvedValue({ data: PRODUCTS });
    pollAvailability.mockResolvedValue(AVAILABILITY);
  });

  it('greys an 86 card with an "86" label and keeps available cards unmarked', async () => {
    renderPos();
    await screen.findByText('Kopi Susu');
    await waitFor(() => expect(screen.getAllByTestId('card-86')).toHaveLength(1));
    const card = screen.getByText('Es Jeruk').closest('button');
    expect(card).toHaveAttribute('aria-disabled', 'true');
    expect(card).toHaveClass('opacity-50');
    expect(card).toHaveTextContent('86');
    expect(screen.getByText('Kopi Susu').closest('button')).not.toHaveAttribute('aria-disabled');
  });

  it('refuses to add an 86 item (toast, empty cart) but still adds an available one', async () => {
    const user = await userEv();
    const { cart, toast } = renderPos();
    await screen.findByText('Kopi Susu');
    await waitFor(() => expect(screen.getAllByTestId('card-86')).toHaveLength(1));

    await user.click(screen.getByText('Es Jeruk'));
    expect(cart.items).toHaveLength(0);
    expect(toast.items.map((i) => i.message)).toContain('Item sedang habis (86).');

    await user.click(screen.getByText('Kopi Susu'));
    expect(cart.items.map((i) => i.variant_id)).toEqual([11]);
  });

  it('search results are checked by variant_id as well', async () => {
    lookupVariants.mockResolvedValue({
      data: [
        { variant_id: 21, sku: 'SKU-21', label: 'Es Jeruk — Standard', artist_name: 'Restoran', category_name: 'Minuman', sell_price: '25000.00', current_stock: 5, image_url: null },
        { variant_id: 11, sku: 'SKU-11', label: 'Kopi Susu — Standard', artist_name: 'Restoran', category_name: 'Minuman', sell_price: '25000.00', current_stock: 5, image_url: null },
      ],
    });
    const user = await userEv();
    const { cart } = renderPos();
    await screen.findByText('Kopi Susu');
    await waitFor(() => expect(pollAvailability).toHaveBeenCalled());
    await user.type(screen.getByLabelText(/cari nama produk/i), 'es');
    await screen.findByText('Es Jeruk — Standard');
    await waitFor(() => expect(screen.getAllByTestId('card-86')).toHaveLength(1));

    await user.click(screen.getByText('Es Jeruk — Standard'));
    expect(cart.items).toHaveLength(0);
    await user.click(screen.getByText('Kopi Susu — Standard'));
    expect(cart.items.map((i) => i.variant_id)).toEqual([11]);
  });

  it('a failing poll leaves every card addable (the server remains the guard)', async () => {
    pollAvailability.mockRejectedValue(new Error('offline'));
    const user = await userEv();
    const { cart } = renderPos();
    await screen.findByText('Es Jeruk');
    await waitFor(() => expect(pollAvailability).toHaveBeenCalled());
    expect(screen.queryByTestId('card-86')).not.toBeInTheDocument();
    await user.click(screen.getByText('Es Jeruk'));
    expect(cart.items.map((i) => i.variant_id)).toEqual([21]);
  });
});
