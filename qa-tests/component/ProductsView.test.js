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

// 004-sidebar-menu-reorg US4/US5, 005-ux-enhancements-dashboard US1
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

  it('filters via the searchable artist dropdown and returns to "all" by re-selecting it', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain A');

    await user.click(screen.getByText(/semua artist/i));
    await user.click(await screen.findByRole('option', { name: 'Artist A' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: [1] })));

    // The panel stays open after a selection (multi-select) — pick "All"
    // directly to clear the selection back to unfiltered.
    await user.click(await screen.findByRole('option', { name: /semua artist/i }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.not.objectContaining({ artist_id: expect.anything() })));
  });

  it('filters via the category dropdown, combinable with the artist filter', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain A');

    await user.click(screen.getByText(/semua artist/i));
    await user.click(await screen.findByRole('option', { name: 'Artist A' }));

    await user.click(screen.getByText(/semua kategori/i));
    await user.click(await screen.findByRole('option', { name: 'Kategori A' }));

    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: [1], category_id: [1] })));
  });
});
