import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import { listProducts, productSummary, getProduct } from '../../resources/js/api/products';
import { listOutlets } from '../../resources/js/api/outlets';
import { listCategories } from '../../resources/js/api/categories';
import { exportMasterData } from '../../resources/js/api/masterData';
import { ROW, COUNTS, SUMMARY, META, renderProducts, userEv } from './productsViewHarness';

// 070 — halaman Produk meminta daftar tanpa header outlet bilah atas (filter Outlet-nya sendiri).
const SKIP_HEADER = { skipOutletHeader: true };

vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(), productSummary: vi.fn(), getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(), deleteProduct: vi.fn(),
  addVariant: vi.fn(), updateVariant: vi.fn(), uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } }), createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }), createModifierGroup: vi.fn(), updateModifierGroup: vi.fn(), deleteModifierGroup: vi.fn(), setModifierGroupAttachments: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

// 043 (T030) — tampilan Tabel: kolom, urutan, pilihan baris + BulkBar, saklar tampilan yang diingat per pengguna.
describe('ProductsView — table (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    listProducts.mockResolvedValue({
      data: [
        ROW(1, { image_url: 'https://example.test/a.png' }),
        ROW(2, { name: 'Es Jeruk', category: { id: 4, name: 'Minuman' }, station: null, variant_count: 0, modifier_summary: [], status: 'discontinued', availability: [{ outlet_id: null, outlet_code: null, state: 'sold_out', reason: null }] }),
      ],
      meta: META(),
      counts: COUNTS,
    });
    productSummary.mockResolvedValue(SUMMARY);
    listOutlets.mockResolvedValue({ data: [{ id: 1, name: 'Outlet A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 3, name: 'Kopi', code: 'KP' }] });
  });

  it('renders the design columns: item, SKU, variants · modifiers, base price, availability, status', async () => {
    await renderProducts({ url: '/products?view=table' });
    await screen.findByText('Item 1');

    const headers = screen.getAllByRole('columnheader').map((h) => h.textContent.trim());
    expect(headers.slice(1, 7)).toEqual(['Item', 'SKU', 'Varian · modifier', 'Harga dasar', 'Ketersediaan', 'Status']);
    expect(screen.getAllByRole('checkbox').length).toBe(3); // kepala + dua baris

    const row = screen.getByText('Item 1').closest('tr');
    expect(within(row).getByText('Kopi · Bar')).toBeInTheDocument();
    expect(within(row).getByText('KOP-001')).toBeInTheDocument();
    expect(within(row).getByText('Panas / Es · Gula')).toBeInTheDocument();
    expect(within(row).getByText(/Rp\s?8\.000/)).toBeInTheDocument();
    expect(within(row).getByTestId('availability-chip')).toHaveTextContent('Ada');
    expect(within(row).getByText('Aktif')).toBeInTheDocument();
    expect(within(row).getByAltText('Item 1')).toHaveAttribute('src', 'https://example.test/a.png');
    expect(within(row).getByRole('link', { name: 'Item 1' })).toHaveAttribute('href', '/products/1');

    const second = screen.getByRole('link', { name: 'Es Jeruk' }).closest('tr');
    expect(within(second).getByText('Minuman')).toBeInTheDocument(); // tanpa station: hanya kategori
    expect(within(second).getByText('Tanpa varian')).toBeInTheDocument();
    expect(within(second).getByText('Discontinued')).toBeInTheDocument();
    expect(within(second).getByTestId('availability-chip')).toHaveTextContent('86');
  });

  it('sorts by item, SKU, price and status through the URL-backed sort param; other columns are not sortable', async () => {
    const user = await userEv();
    const { router } = await renderProducts({ url: '/products?view=table' });
    await screen.findByText('Item 1');

    const sortable = screen.getAllByRole('columnheader').filter((h) => h.hasAttribute('aria-sort') || h.className.includes('cursor-pointer')).map((h) => h.textContent.trim());
    expect(sortable).toEqual(['Item', 'SKU', 'Harga dasar', 'Status']);

    await user.click(screen.getByRole('columnheader', { name: /harga dasar/i }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ sort: 'price', page: 1 }), SKIP_HEADER));
    await user.click(screen.getByRole('columnheader', { name: /harga dasar/i }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ sort: '-price' }), SKIP_HEADER));
    await waitFor(() => expect(router.currentRoute.value.query.sort).toBe('-price'));
    expect(screen.getByRole('columnheader', { name: /harga dasar/i })).toHaveAttribute('aria-sort', 'descending');
  });

  it('selecting rows shows the bulk bar with only the export action; clearing hides it', async () => {
    const user = await userEv();
    await renderProducts({ url: '/products?view=table' });
    await screen.findByText('Item 1');
    expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();

    await user.click(screen.getByRole('checkbox', { name: /pilih item 1/i }));
    const bar = screen.getByRole('toolbar');
    expect(within(bar).getByText('1 dipilih')).toBeInTheDocument();
    expect(within(bar).getAllByRole('button').map((b) => b.textContent.trim())).toEqual(['Ekspor semua produk', 'Batalkan pilihan']);

    await user.click(within(bar).getByRole('button', { name: /ekspor semua produk/i }));
    expect(exportMasterData).toHaveBeenCalledWith('products');

    await user.click(within(bar).getByRole('button', { name: /batalkan pilihan/i }));
    expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
  });

  it('the header checkbox selects every row on the page', async () => {
    const user = await userEv();
    await renderProducts({ url: '/products?view=table' });
    await screen.findByText('Item 1');
    await user.click(screen.getByRole('checkbox', { name: /pilih semua di halaman ini/i }));
    expect(screen.getByText('2 dipilih')).toBeInTheDocument();
  });

  it('has no bulk bar in the grid view', async () => {
    await renderProducts({ url: '/products?view=grid' });
    await screen.findByText('Item 1');
    expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
  });

  it('switches Grid <-> Tabel keeping the filters, and remembers the choice per user', async () => {
    const user = await userEv();
    const { router, unmount } = await renderProducts({ url: '/products?q=kopi', userId: 7 });
    await screen.findByText('Item 1');
    expect(screen.getByTestId('item-grid')).toBeInTheDocument();

    await user.click(screen.getByRole('radio', { name: 'Tabel' }));
    expect(await screen.findByRole('table')).toBeInTheDocument();
    expect(screen.queryByTestId('item-grid')).not.toBeInTheDocument();
    expect(localStorage.getItem('boothpos:products-view:7')).toBe('table');
    await waitFor(() => expect(router.currentRoute.value.query).toMatchObject({ q: 'kopi', view: 'table' }));
    // pindah tampilan tidak memuat ulang daftar (data sama)
    expect(listProducts).toHaveBeenCalledTimes(1);
    unmount();

    // kunjungan berikutnya tanpa ?view= memakai pilihan tersimpan; pengguna lain tetap Grid
    await renderProducts({ userId: 7 });
    expect(await screen.findByRole('table')).toBeInTheDocument();
  });

  it('a different user keeps the Grid default, and an explicit ?view= in the address wins over the stored choice', async () => {
    localStorage.setItem('boothpos:products-view:7', 'table');
    await renderProducts({ userId: 8 });
    await screen.findByText('Item 1');
    expect(screen.getByTestId('item-grid')).toBeInTheDocument();
  });

  it('offers only Edit as a row action (the old instant Deactivate is gone: Discontinue lives on the item page)', async () => {
    await renderProducts({ url: '/products?view=table' });
    await screen.findByText('Item 1');

    expect(screen.getAllByRole('link', { name: /^ubah /i })).toHaveLength(2);
    expect(screen.queryByRole('button', { name: /nonaktifkan/i })).not.toBeInTheDocument();
  });

  // Edit membuka HALAMAN item (rute product-detail), bukan laci ubah di daftar.
  it('Edit on a row is a link to the item page and navigates there without opening the edit drawer', async () => {
    const user = await userEv();
    const { router } = await renderProducts({ url: '/products?view=table' });
    await screen.findByText('Item 1');

    const edit = screen.getByRole('link', { name: 'Ubah Es Jeruk' });
    expect(edit).toHaveAttribute('href', '/products/2');
    await user.click(edit);
    await waitFor(() => expect(router.currentRoute.value.name).toBe('product-detail'));
    expect(router.currentRoute.value.params.id).toBe('2');
    expect(getProduct).not.toHaveBeenCalled(); // openEdit dari laci akan memanggil GET /products/{id}
    expect(screen.queryByText('Simpan item')).not.toBeInTheDocument();
  });

  it('hides the row actions for a role without the products menu', async () => {
    await renderProducts({ menus: ['dashboard'], url: '/products?view=table' });
    await screen.findByText('Item 1');
    expect(screen.queryByRole('link', { name: /^ubah /i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /nonaktifkan/i })).not.toBeInTheDocument();
  });
});
