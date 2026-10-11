import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import { listProducts, productSummary, getProduct } from '../../resources/js/api/products';
import { listArtists } from '../../resources/js/api/artists';
import { listCategories } from '../../resources/js/api/categories';
import { ROW, COUNTS, SUMMARY, META, renderProducts, userEv } from './productsViewHarness';

vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(), productSummary: vi.fn(), getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(), deleteProduct: vi.fn(),
  addVariant: vi.fn(), updateVariant: vi.fn(), uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/materials', () => ({
  listMaterials: vi.fn().mockResolvedValue({ data: [{ id: 5, code: 'BJ1', name: 'Biji kopi', unit: 'kg', kind: 'ingredient', is_active: true, vendor_price_count: 1 }], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } }),
  createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn(),
}));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }), createModifierGroup: vi.fn(), updateModifierGroup: vi.fn(), deleteModifierGroup: vi.fn(), setModifierGroupAttachments: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

// 043 (T030) — tampilan Grid: isi kartu, tab, filter, alamat URL, pager, keadaan kosong.
describe('ProductsView — grid (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    listProducts.mockResolvedValue({
      data: [
        ROW(1, { image_url: 'https://example.test/a.png' }),
        ROW(2, { name: 'Es Jeruk', variant_count: 0, variants_summary: 'Tanpa varian', modifier_summary: [], status: 'draft', availability: [{ outlet_id: null, outlet_code: null, state: 'sold_out', reason: 'Stok habis' }] }),
        ROW(3, { name: 'Teh', variant_count: 1, variants_summary: 'Reguler', modifier_summary: ['Gula', 'Ekstra shot'], base_price: null }),
      ],
      meta: META(),
      counts: COUNTS,
    });
    productSummary.mockResolvedValue(SUMMARY);
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 3, name: 'Kopi', code: 'KP' }, { id: 4, name: 'Teh', code: 'TH' }] });
  });

  it('is the default view and renders one card per item with photo or placeholder', async () => {
    await renderProducts();
    await screen.findByText('Item 1');

    expect(screen.getByTestId('item-grid')).toBeInTheDocument();
    expect(screen.getAllByTestId('item-card')).toHaveLength(3);
    expect(screen.getByAltText('Item 1')).toHaveAttribute('src', 'https://example.test/a.png');
    expect(screen.getAllByTestId('item-card-placeholder')).toHaveLength(2);
    expect(screen.getAllByText('foto item')).toHaveLength(2);
  });

  it('shows status badge, name, base price, the muted variants/modifiers line and availability on a card', async () => {
    await renderProducts();
    await screen.findByText('Item 1');
    const [first, second, third] = screen.getAllByTestId('item-card');

    expect(within(first).getByText('Aktif')).toBeInTheDocument();
    expect(within(first).getByText('Item 1')).toBeInTheDocument();
    expect(within(first).getByText(/Rp\s?8\.000/)).toBeInTheDocument();
    expect(within(first).getByText('Varian Panas / Es')).toBeInTheDocument();
    expect(within(first).getByTestId('availability-chip')).toHaveTextContent('Ada');

    expect(within(second).getByText('Draft')).toBeInTheDocument();
    expect(within(second).getByText('Tanpa varian')).toBeInTheDocument();
    expect(within(second).getByTestId('availability-chip')).toHaveTextContent('86');

    // satu varian -> baris menampilkan nama modifier; harga tak ada -> penanda, bukan 0
    expect(within(third).getByText('Gula, Ekstra shot')).toBeInTheDocument();
    expect(within(third).getByText('—')).toBeInTheDocument();
  });

  it('makes the whole card one link to the item page', async () => {
    await renderProducts();
    await screen.findByText('Item 1');
    const link = screen.getByRole('link', { name: 'Es Jeruk' });
    expect(link).toHaveAttribute('href', '/products/2');
    expect(within(link).getByText('Es Jeruk')).toBeInTheDocument();
  });

  it('offers Edit outside the card link, only to roles with the products menu', async () => {
    await renderProducts();
    await screen.findByText('Item 1');
    expect(screen.getAllByRole('link', { name: /^ubah /i })).toHaveLength(3);
    expect(within(screen.getByRole('link', { name: 'Item 1' })).queryByRole('link')).not.toBeInTheDocument();
  });

  // Edit membuka HALAMAN item (bukan laci ubah): tautan menuju rute product-detail, sama dengan klik kartu.
  it('Edit on a card is a link to the item page, and clicking it navigates there without opening the edit drawer', async () => {
    const user = await userEv();
    const { router } = await renderProducts();
    await screen.findByText('Item 1');
    const edit = screen.getByRole('link', { name: 'Ubah Es Jeruk' });
    expect(edit).toHaveAttribute('href', '/products/2');
    await user.click(edit);
    await waitFor(() => expect(router.currentRoute.value.name).toBe('product-detail'));
    expect(router.currentRoute.value.params.id).toBe('2');
    expect(getProduct).not.toHaveBeenCalled(); // laci ubah (openEdit) memanggil GET /products/{id}
    expect(screen.queryByText('Simpan item')).not.toBeInTheDocument();
  });

  it('hides Edit for a role without the products menu', async () => {
    await renderProducts({ menus: ['dashboard'] });
    await screen.findByText('Item 1');
    expect(screen.queryByRole('link', { name: /^ubah /i })).not.toBeInTheDocument();
  });

  it('filters by category and status through the dropdowns, into the query and the URL, and survives a reload', async () => {
    const user = await userEv();
    const { router } = await renderProducts();
    await screen.findByText('Item 1');

    await user.click(screen.getByText(/semua kategori/i));
    await user.click(await screen.findByRole('option', { name: 'Kopi' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ category_id: [3], page: 1 })));

    await user.click(screen.getByText(/semua status/i));
    await user.click(await screen.findByRole('option', { name: 'Draft' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ category_id: [3], status: ['draft'] })));
    await waitFor(() => expect(router.currentRoute.value.query).toMatchObject({ category: '3', status: 'draft' }));
  });

  it('restores tab, filters, sort and page from the address, ignoring unknown values', async () => {
    await renderProducts({ url: '/products?tab=retail&q=es&category=3&status=active,bogus&quality=no_station&sort=-price&page=2&view=table' });
    await waitFor(() => expect(listProducts).toHaveBeenCalled());
    expect(listProducts).toHaveBeenCalledWith({
      tab: 'retail', page: 2, per_page: 15, q: 'es', category_id: [3], status: ['active'], quality: 'no_station', availability: undefined, sort: '-price',
    });
    expect(await screen.findByRole('tab', { name: /^Item ritel/, selected: true })).toBeInTheDocument();

    // nilai tak dikenal jatuh ke bawaan, bukan galat
    listProducts.mockClear();
    const second = await renderProducts({ url: '/products?tab=zzz&sort=hack&page=-4&quality=nope&view=cube' });
    await waitFor(() => expect(listProducts).toHaveBeenCalled());
    expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ tab: 'menu', page: 1, sort: undefined, quality: undefined }));
    expect(second.container).toBeTruthy();
  });

  it('pages with "Menampilkan 1–15 dari 24" and asks for the next page', async () => {
    const user = await userEv();
    await renderProducts();
    await screen.findByText('Item 1');
    expect(screen.getByText('Menampilkan 1–15 dari 24')).toBeInTheDocument();

    listProducts.mockResolvedValueOnce({ data: [ROW(16)], meta: META(24, 2), counts: COUNTS });
    await user.click(screen.getByRole('button', { name: /halaman berikutnya/i }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 })));
    expect(await screen.findByText('Menampilkan 16–24 dari 24')).toBeInTheDocument();
  });

  it('shows an empty state with a way to clear filters when nothing matches', async () => {
    const user = await userEv();
    listProducts.mockResolvedValue({ data: [], meta: META(0), counts: COUNTS });
    await renderProducts({ url: '/products?q=zzz' });

    expect(await screen.findByText('Tidak ada item yang cocok dengan filter ini.')).toBeInTheDocument();
    listProducts.mockResolvedValue({ data: [ROW(1)], meta: META(1), counts: COUNTS });
    await user.click(screen.getAllByRole('button', { name: /atur ulang filter/i })[0]);
    expect(await screen.findByText('Item 1')).toBeInTheDocument();
  });

  it('shows the plain "no items" message (and no reset link) for an empty install', async () => {
    listProducts.mockResolvedValue({ data: [], meta: META(0), counts: { ...COUNTS, menu: 0 } });
    await renderProducts();
    expect(await screen.findByText('Belum ada item.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();
  });

  it('turns the "86 hari ini" figure into a visible, removable filter', async () => {
    const user = await userEv();
    await renderProducts();
    await screen.findByText('Item 1');

    await user.click(await screen.findByTestId('figure-sold_out'));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ availability: 'sold_out', page: 1 })));
    const chip = await screen.findByRole('button', { name: /lepas filter 86 hari ini/i });

    await user.click(chip);
    await waitFor(() => expect(listProducts.mock.lastCall[0].availability).toBeUndefined());
  });

  it('turns the "Kualitas data" figure into a filter using the kind the server named', async () => {
    const user = await userEv();
    await renderProducts();
    await screen.findByText('Item 1');
    await user.click(await screen.findByTestId('figure-quality'));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ quality: 'no_station' })));
    expect(await screen.findByRole('button', { name: /lepas filter kualitas data: tanpa station dapur/i })).toBeInTheDocument();
  });

  it('switching to "Item ritel" requests the retail tab and clears the previous rows first', async () => {
    const user = await userEv();
    await renderProducts();
    await screen.findByText('Item 1');

    listProducts.mockResolvedValueOnce({ data: [ROW(9, { name: 'Kopi botol', is_retail: true })], meta: META(1), counts: COUNTS });
    await user.click(screen.getByRole('tab', { name: /^Item ritel/ }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ tab: 'retail' })));
    expect(await screen.findByText('Kopi botol')).toBeInTheDocument();
    expect(screen.queryByText('Item 1')).not.toBeInTheDocument();
  });

  it('embeds the materials list (filtered by kind) on the raw-material tabs and refreshes counts only once', async () => {
    const user = await userEv();
    const { listMaterials } = await import('../../resources/js/api/materials');
    await renderProducts();
    await screen.findByText('Item 1');

    await user.click(screen.getByRole('tab', { name: /^Bahan baku/ }));
    expect(await screen.findByText('Biji kopi')).toBeInTheDocument();
    expect(listMaterials).toHaveBeenLastCalledWith(expect.objectContaining({ kind: 'ingredient' }));
    // tidak ada kolom "Jenis" di tab yang sudah menyaring jenis
    expect(screen.queryByRole('columnheader', { name: 'Jenis' })).not.toBeInTheDocument();

    await user.click(screen.getByRole('tab', { name: /^Kemasan/ }));
    await waitFor(() => expect(listMaterials).toHaveBeenLastCalledWith(expect.objectContaining({ kind: 'packaging' })));
    // hitungan tab sudah diketahui -> berpindah antar tab bahan tidak memanggil GET /products lagi
    expect(listProducts).toHaveBeenCalledTimes(1);
  });

  it('embeds the modifier groups list on the "Modifier & combo" tab', async () => {
    const user = await userEv();
    const { listModifierGroups } = await import('../../resources/js/api/modifierGroups');
    await renderProducts();
    await screen.findByText('Item 1');
    await user.click(screen.getByRole('tab', { name: /^Modifier/ }));
    await waitFor(() => expect(listModifierGroups).toHaveBeenCalled());
    // tombol tambah kepala halaman disembunyikan di tab ini (daftar grup punya tombol barunya sendiri)
    expect(within(screen.getByRole('heading', { level: 1 }).closest('header')).queryByRole('button', { name: /tambah item/i })).not.toBeInTheDocument();
  });
});
