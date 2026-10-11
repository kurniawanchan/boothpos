import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ProductsView from '../../resources/js/views/ProductsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listProducts } from '../../resources/js/api/products';
import { listArtists } from '../../resources/js/api/artists';
import { listCategories } from '../../resources/js/api/categories';

vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(), getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(), deleteProduct: vi.fn(),
  addVariant: vi.fn(), updateVariant: vi.fn(), uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

const ROWS = [
  { id: 1, code_prefix: 'ARTKY001', name: 'Keychain A', artist_name: 'Artist A', category_name: 'Kategori A', is_preorder: false, is_active: true, image_url: null, variants: [] },
];

function renderProducts(menus = ['dashboard', 'products']) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menus };
  return render(ProductsView, { global: { plugins: [pinia] } });
}

// 041 US2 — halaman Produk memakai kit halaman (PageHeader + FilterBar), perilaku tetap.
describe('ProductsView — page kit structure (041)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 137, last_page: 6 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
  });

  it('shows the page title and subtitle from PageHeader, with the primary action inside the header', async () => {
    renderProducts();
    await screen.findByText('Keychain A');

    const heading = screen.getByRole('heading', { level: 1 });
    expect(heading).toHaveTextContent('Produk');
    expect(heading).toHaveAttribute('id', 'page-heading');
    expect(screen.getByText('Produk dan varian yang dijual di kasir')).toBeInTheDocument();
    const header = heading.closest('header');
    expect(within(header).getByRole('button', { name: /produk baru/i })).toBeInTheDocument();
    expect(within(header).getByRole('button', { name: /ekspor/i })).toBeInTheDocument();
  });

  it('hides every management action (not disabled) for a role without the products menu', async () => {
    renderProducts(['dashboard']);
    await screen.findByText('Keychain A');

    expect(screen.queryByRole('button', { name: /produk baru/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ekspor/i })).not.toBeInTheDocument();
    expect(screen.queryByText('Edit')).not.toBeInTheDocument();
    expect(screen.getByText('Detail')).toBeInTheDocument();
  });

  it('shows the server total (not the page size) as "N data" in the filter bar', async () => {
    renderProducts();
    await screen.findByText('Keychain A');
    expect(screen.getByTestId('data-count')).toHaveTextContent('137 data');
  });

  it('shows no KPI strip: the paginated list has no honest four-number summary', async () => {
    renderProducts();
    await screen.findByText('Keychain A');
    expect(document.querySelector('section.grid')).toBeNull();
  });

  it('search is debounced into the list query, and "reset filters" clears search and both dropdowns', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain A');
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();

    await user.type(screen.getByRole('searchbox'), 'key');
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'key', page: 1 })));

    await user.click(screen.getByText(/semua penjual/i));
    await user.click(await screen.findByRole('option', { name: 'Artist A' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'key', artist_id: [1] })));

    await user.click(screen.getByRole('button', { name: /atur ulang filter/i }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.not.objectContaining({ search: expect.anything() })));
    expect(listProducts).toHaveBeenLastCalledWith(expect.not.objectContaining({ artist_id: expect.anything() }));
    expect(screen.getByRole('searchbox')).toHaveValue('');
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();
  });

  it('shows an error state with a retry button when loading fails, and retry reloads the list', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listProducts.mockRejectedValueOnce(new Error('network'));
    renderProducts();

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Gagal memuat data');

    await user.click(within(alert).getByRole('button'));
    expect(await screen.findByText('Keychain A')).toBeInTheDocument();
    expect(listProducts).toHaveBeenCalledTimes(2);
  });

  it('still asks for variants and opens the create drawer from the header action', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain A');
    expect(listProducts).toHaveBeenCalledWith(expect.objectContaining({ with_variants: 1 }));

    await user.click(screen.getByRole('button', { name: /produk baru/i }));
    expect(await screen.findByDisplayValue('Standard')).toBeInTheDocument();
  });
});
