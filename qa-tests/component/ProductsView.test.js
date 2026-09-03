import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ProductsView from '../../resources/js/views/ProductsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listProducts } from '../../resources/js/api/products';
import { listArtists } from '../../resources/js/api/artists';
import { listCategories } from '../../resources/js/api/categories';

vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(),
  getProduct: vi.fn(),
  createProduct: vi.fn(),
  updateProduct: vi.fn(),
  deleteProduct: vi.fn(),
  addVariant: vi.fn(),
  updateVariant: vi.fn(),
  uploadProductImage: vi.fn(),
}));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

const PRODUCTS = [
  { id: 1, code_prefix: 'ARTKY001', name: 'Keychain A', artist_name: 'Artist A', category_name: 'Kategori A', is_preorder: false, is_active: true, image_url: 'https://example.test/a.png' },
  { id: 2, code_prefix: 'ARTKY002', name: 'Keychain B', artist_name: 'Artist A', category_name: 'Kategori A', is_preorder: false, is_active: true, image_url: null },
];

function renderProducts() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'products'] };
  return render(ProductsView, { global: { plugins: [pinia] } });
}

// 004-sidebar-menu-reorg US4/US5
describe('ProductsView — product images & clickable filters', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: PRODUCTS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
  });

  it('renders a thumbnail for a product with an image and a placeholder for one without', async () => {
    renderProducts();
    await screen.findByText('Keychain A');

    const img = screen.getByAltText('Keychain A');
    expect(img).toHaveAttribute('src', 'https://example.test/a.png');
    // Keychain B has no image_url — its row must not render an <img> for it.
    expect(screen.queryByAltText('Keychain B')).not.toBeInTheDocument();
  });

  it('filters by clicking an artist chip and returns to "all" via the All chip', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain A');
    await screen.findByRole('button', { name: 'Artist A' });

    await user.click(screen.getByRole('button', { name: 'Artist A' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: 1 })));

    await user.click(screen.getAllByText(/semua artist/i)[0]);
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.not.objectContaining({ artist_id: expect.anything() })));
  });

  it('filters by clicking a category chip, combinable with the artist filter', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain A');
    await screen.findByRole('button', { name: 'Artist A' });

    await user.click(screen.getByRole('button', { name: 'Artist A' }));
    await user.click(screen.getByRole('button', { name: 'Kategori A' }));

    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: 1, category_id: 1 })));
  });
});
