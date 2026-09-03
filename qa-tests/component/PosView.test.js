import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PosView from '../../resources/js/views/PosView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { currentSession } from '../../resources/js/api/sessions';
import { listCategories } from '../../resources/js/api/categories';
import { listArtists } from '../../resources/js/api/artists';
import { listProducts } from '../../resources/js/api/products';

vi.mock('../../resources/js/api/sessions', () => ({ currentSession: vi.fn(), openSession: vi.fn(), closeSession: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn(), lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ createOrder: vi.fn() }));

const PRODUCTS = [
  {
    id: 1, name: 'Keychain A', artist_name: 'Artist A', category_id: 1, image_url: 'https://example.test/a.png',
    variants: [{ id: 11, sku: 'SKU-A-1', variant_name: 'Standard', sell_price: '25000.00', current_stock: 5, is_active: true }],
  },
  {
    id: 2, name: 'Keychain B', artist_name: 'Artist B', category_id: 1, image_url: null,
    variants: [{ id: 21, sku: 'SKU-B-1', variant_name: 'Standard', sell_price: '30000.00', current_stock: 5, is_active: true }],
  },
];

function renderPos() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Kasir', name: 'Kasir', menu_keys: ['dashboard', 'pos', 'session'] };
  return render(PosView, { global: { plugins: [pinia] } });
}

// 004-sidebar-menu-reorg US4/US5
describe('PosView — product images & clickable artist filter', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null);
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A' }, { id: 2, name: 'Artist B' }] });
    listProducts.mockResolvedValue({ data: PRODUCTS });
  });

  it('renders a product image for a card that has one and a placeholder for one that does not', async () => {
    renderPos();
    await screen.findByText('Keychain A');

    const img = screen.getByAltText('Keychain A');
    expect(img).toHaveAttribute('src', 'https://example.test/a.png');
    expect(screen.queryByAltText('Keychain B')).not.toBeInTheDocument();
  });

  it('shows an artist chip row and refetches with artist_id when one is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderPos();
    await screen.findByText('Keychain A');
    await screen.findByRole('button', { name: 'Artist A' });

    await user.click(screen.getByRole('button', { name: 'Artist A' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: 1 })));
  });

  it('returns to showing all artists when the "All" chip for that row is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderPos();
    await screen.findByText('Keychain A');
    await screen.findByRole('button', { name: 'Artist A' });

    await user.click(screen.getByRole('button', { name: 'Artist A' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: 1 })));

    const allButtons = screen.getAllByRole('button', { name: /^(all|semua)$/i });
    await user.click(allButtons[0]);
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.not.objectContaining({ artist_id: expect.anything() })));
  });
});
