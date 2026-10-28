import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import StockView from '../../resources/js/views/StockView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useToastStore } from '../../resources/js/stores/toast';
import { listMovements } from '../../resources/js/api/stock';
import { getProduct } from '../../resources/js/api/products';
import { listArtists } from '../../resources/js/api/artists';
import { listCategories } from '../../resources/js/api/categories';

vi.mock('../../resources/js/api/stock', () => ({ listMovements: vi.fn(), createAdjustment: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ lookupVariants: vi.fn(), getProduct: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({ listBomLines: vi.fn() }));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn() }));

const MOVEMENT = (id, extra = {}) => ({
  id, variant_id: 11, sku: 'SPF-KC-MCY-008', type: 'sale', qty_change: -1, stock_before: 9, stock_after: 8, reason: null,
  created_at: '2026-10-04T06:29:00+00:00', user_name: 'Chan', variant_name: 'Slippery 5cm', product_id: 2, product_name: 'MCYT', reference: null, ...extra,
});
const PRODUCT = {
  id: 2, name: 'MCYT', code_prefix: 'SPF-KC-MCY', artist_name: 'sapphirefiless', category_name: 'KEYCHAIN', is_active: true, is_preorder: false, description: null,
  variants: [
    { id: 10, sku: 'SPF-KC-MCY-001', variant_name: 'Red', sell_price: '20000.00', current_stock: 5, is_active: true, image_url: null },
    { id: 11, sku: 'SPF-KC-MCY-008', variant_name: 'Slippery 5cm', sell_price: '20000.00', current_stock: 8, is_active: true, image_url: null },
  ],
};

function renderStock(menuKeys = ['dashboard', 'stock', 'products']) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menuKeys };
  return { pinia, ...render(StockView, { global: { plugins: [pinia], stubs: { 'router-link': true } } }) };
}

// 036-bom-variant-stock-ux (US5) — SKU membuka produk; header Type diterjemahkan; kolom Oleh terisi.
describe('StockView (036)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.HTMLElement.prototype.scrollIntoView = vi.fn();
    getProduct.mockResolvedValue(PRODUCT);
    listArtists.mockResolvedValue({ data: [{ id: 5, name: 'Penjual Satu' }, { id: 6, name: 'Penjual Dua' }] });
    listCategories.mockResolvedValue({ data: [{ id: 8, name: 'Keychain' }] });
    listMovements.mockResolvedValue({
      data: [
        MOVEMENT(2, { reference: { type: 'order', id: 41, number: 'TRX-20261004-0007' } }),
        MOVEMENT(1, { type: 'adjustment', qty_change: 9, stock_before: 0, stock_after: 9, reason: 'add slippery 5cm', user_name: 'Rina' }),
      ],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
  });

  it('shows a translated Type header, the user in the By column and the resolved reference', async () => {
    renderStock();
    await screen.findByText('TRX-20261004-0007');

    expect(screen.getByRole('columnheader', { name: 'Tipe' })).toBeInTheDocument();
    expect(screen.getByText('Chan')).toBeInTheDocument();
    expect(screen.getByText('Rina')).toBeInTheDocument();
    expect(screen.getByText('add slippery 5cm')).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/master_data\./i);
  });

  it('opens the product detail with that variant highlighted when an SKU is clicked, leaving the list untouched', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderStock();
    await screen.findByText('TRX-20261004-0007');
    const callsBefore = listMovements.mock.calls.length;

    await user.click(screen.getAllByRole('button', { name: 'SPF-KC-MCY-008' })[0]);

    await waitFor(() => expect(getProduct).toHaveBeenCalledWith(2));
    const row = (await screen.findAllByText('SPF-KC-MCY-008')).map((el) => el.closest('tr')).find((tr) => tr?.getAttribute('aria-current') === 'true');
    expect(row).toBeTruthy();

    await user.click(screen.getByRole('button', { name: /tutup dialog/i }));
    expect(listMovements.mock.calls.length).toBe(callsBefore); // daftar & filter tidak dimuat ulang
    expect(screen.getByText('TRX-20261004-0007')).toBeInTheDocument();
  });

  it('shows a clear message instead of a broken dialog when the product no longer exists', async () => {
    listMovements.mockResolvedValue({ data: [MOVEMENT(3, { product_id: null })], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { pinia } = renderStock();
    await screen.findByRole('button', { name: 'SPF-KC-MCY-008' });

    await user.click(screen.getByRole('button', { name: 'SPF-KC-MCY-008' }));

    expect(getProduct).not.toHaveBeenCalled();
    expect(useToastStore(pinia).items.some((i) => /sudah dihapus|tidak ditemukan/i.test(i.message))).toBe(true);
  });

  it('shows the chosen type in the filter box and can go back to all types', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderStock();
    await screen.findByText('TRX-20261004-0007');

    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: 'Penjualan' }));
    expect(screen.getByRole('combobox')).toHaveTextContent('Penjualan');
    await waitFor(() => expect(listMovements).toHaveBeenLastCalledWith(expect.objectContaining({ type: 'sale', page: 1 })));

    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: 'Semua tipe' }));
    expect(screen.getByRole('combobox')).toHaveTextContent('Semua tipe');
    await waitFor(() => expect(listMovements.mock.lastCall[0].type).toBeUndefined());
  });

  it('renders the SKU as plain text (no button) for a user without products access', async () => {
    renderStock(['dashboard', 'stock']);
    await screen.findByText('TRX-20261004-0007');

    expect(screen.queryByRole('button', { name: 'SPF-KC-MCY-008' })).not.toBeInTheDocument();
    expect(screen.getAllByText('SPF-KC-MCY-008').length).toBeGreaterThan(0);
  });
});

// 048-stock-movements-search-variant-columns (US1) — pencarian, filter penjual/kategori, kolom baru di baris pergerakan.
describe('StockView — search, filters and variant columns (048)', () => {
  const ROW = (id, extra = {}) => MOVEMENT(id, { image_url: 'https://example.test/v.png', artist_id: 5, artist_name: 'Penjual Satu', category_id: 8, category_name: 'Keychain', current_stock: 8, ...extra });

  beforeEach(() => {
    vi.clearAllMocks();
    window.HTMLElement.prototype.scrollIntoView = vi.fn();
    listArtists.mockResolvedValue({ data: [{ id: 5, name: 'Penjual Satu' }, { id: 6, name: 'Penjual Dua' }] });
    listCategories.mockResolvedValue({ data: [{ id: 8, name: 'Keychain' }] });
    listMovements.mockResolvedValue({ data: [ROW(1), ROW(2, { variant_id: 12, sku: 'SPF-KC-MCY-009', variant_name: 'Merah', image_url: null, artist_name: null, category_name: null, current_stock: 0 })], meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
  });

  it('keeps the ledger and shows picture/placeholder, variant name, seller, category and live available stock per row', async () => {
    renderStock();
    await screen.findByText('Slippery 5cm');

    for (const name of ['Varian', 'Penjual', 'Kategori', 'Stok tersedia', 'Tipe']) {
      expect(screen.getByRole('columnheader', { name })).toBeInTheDocument();
    }
    const first = screen.getByText('Slippery 5cm').closest('tr');
    expect(within(first).getByAltText('Slippery 5cm')).toHaveAttribute('src', 'https://example.test/v.png');
    expect(within(first).getByText('Penjual Satu')).toBeInTheDocument();
    expect(within(first).getByText('Keychain')).toBeInTheDocument();
    expect(within(first).getByText('8')).toBeInTheDocument();

    const second = screen.getByText('Merah').closest('tr');
    expect(within(second).getByTestId('variant-placeholder')).toBeInTheDocument();
    expect(within(second).queryByRole('img')).not.toBeInTheDocument();
    expect(within(second).getByText('0')).toBeInTheDocument(); // nol tetap angka, bukan kosong
    expect(screen.getAllByRole('row').length).toBe(3); // header + 2 pergerakan (tetap buku besar)
    expect(document.body.textContent).not.toMatch(/master_data\./i);
  });

  it('sends the typed search to the server after the debounce and resets to page 1', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderStock();
    await screen.findByText('Slippery 5cm');

    await user.type(screen.getByRole('searchbox', { name: 'Cari pergerakan stok' }), 'slip');

    await waitFor(() => expect(listMovements).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'slip', page: 1 })));
  });

  it('sends seller and category selections as arrays and drops them when cleared back to "all"', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderStock();
    await screen.findByText('Slippery 5cm');

    await user.click(screen.getByRole('button', { name: /Semua penjual/ }));
    await user.click(await screen.findByRole('option', { name: /Penjual Dua/ }));
    await waitFor(() => expect(listMovements).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: [6], page: 1 })));

    await user.click(screen.getByRole('button', { name: /Semua kategori/ }));
    await user.click(await screen.findByRole('option', { name: /Keychain/ }));
    await waitFor(() => expect(listMovements).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: [6], category_id: [8] })));
  });

  it('shows a "no match" message under active filters but the plain empty message otherwise', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listMovements.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } });
    renderStock();
    // DataTable menampilkan pesan kosong sekilas sebelum muat dimulai; tunggu muat pertama selesai dulu.
    await waitFor(() => expect(listMovements).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.getByText('Belum ada pergerakan stok.')).toBeInTheDocument());

    await user.type(screen.getByRole('searchbox', { name: 'Cari pergerakan stok' }), 'zzz');

    await waitFor(() => expect(screen.getByText('Tidak ada pergerakan stok yang cocok dengan pencarian atau filter.')).toBeInTheDocument());
    expect(screen.queryByText('Belum ada pergerakan stok.')).not.toBeInTheDocument();
  });

  it('still loads the list when the seller/category options fail to load', async () => {
    listArtists.mockRejectedValue(new Error('boom'));
    listCategories.mockRejectedValue(new Error('boom'));
    renderStock();

    expect(await screen.findByText('Slippery 5cm')).toBeInTheDocument();
  });
});

// 048 (US2) — tombol Riwayat per baris membuka dialog riwayat varian itu, berjudul "<nama varian> · <SKU>".
describe('StockView — History action (048)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.HTMLElement.prototype.scrollIntoView = vi.fn();
    listArtists.mockResolvedValue({ data: [] });
    listCategories.mockResolvedValue({ data: [] });
    listMovements.mockResolvedValue({
      data: [MOVEMENT(7, { variant_id: 11, variant_name: 'Slippery 5cm', reference: { type: 'order', id: 41, number: 'TRX-20261004-0007' } }), MOVEMENT(8, { variant_id: 12, sku: 'SPF-KC-MCY-009', variant_name: 'Merah' })],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
  });

  it('opens the dialog for THAT row\'s variant, titled with its name and SKU, and leaves the list as it was', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderStock();
    await screen.findByText('TRX-20261004-0007');

    await user.click(screen.getByRole('button', { name: 'Riwayat: Merah' }));

    await waitFor(() => expect(listMovements).toHaveBeenCalledWith(expect.objectContaining({ variant_id: 12, page: 1 })));
    expect(await screen.findByRole('dialog', { name: 'Riwayat transaksi — Merah · SPF-KC-MCY-009' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /tutup dialog/i }));
    expect(screen.getByText('TRX-20261004-0007')).toBeInTheDocument(); // daftar tetap, tidak kembali kosong/berpindah
    expect(screen.getAllByRole('button', { name: /^Riwayat: / })).toHaveLength(2);
  });
});
