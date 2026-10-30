import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PosView from '../../resources/js/views/PosView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { currentSession } from '../../resources/js/api/sessions';
import { listCategories } from '../../resources/js/api/categories';
import { listOutlets } from '../../resources/js/api/outlets';
import { listProducts, lookupVariants } from '../../resources/js/api/products';

vi.mock('../../resources/js/api/sessions', () => ({ currentSession: vi.fn(), openSession: vi.fn(), closeSession: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn(), lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ createOrder: vi.fn() }));

const PRODUCTS = [
  {
    id: 1, name: 'Keychain A', home_outlet_name: 'Outlet A', category_id: 1, image_url: 'https://example.test/a.png',
    variants: [{ id: 11, sku: 'SKU-A-1', variant_name: 'Standard', sell_price: '25000.00', current_stock: 5, is_active: true }],
  },
  {
    id: 2, name: 'Keychain B', home_outlet_name: 'Outlet B', category_id: 1, image_url: null,
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
describe('PosView — product images & clickable outlet filter', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null);
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    listOutlets.mockResolvedValue({ data: [{ id: 1, name: 'Outlet A' }, { id: 2, name: 'Outlet B' }] });
    listProducts.mockResolvedValue({ data: PRODUCTS });
  });

  it('045: browses the menu of the shift outlet (pos=1) so non-carried items are never offered', async () => {
    renderPos();
    await screen.findByText('Keychain A');
    expect(listProducts).toHaveBeenCalledWith(expect.objectContaining({ pos: 1, with_variants: 1 }));
  });

  it('renders a product image for a card that has one and a placeholder for one that does not', async () => {
    renderPos();
    await screen.findByText('Keychain A');

    const img = screen.getByAltText('Keychain A');
    expect(img).toHaveAttribute('src', 'https://example.test/a.png');
    expect(screen.queryByAltText('Keychain B')).not.toBeInTheDocument();
  });

  // 005-ux-enhancements-dashboard (US1) — chip rows replaced by a
  // searchable multi-select dropdown (BaseMultiSelect.vue); the outlet
  // dropdown is the first of the two on this screen.
  it('filters via the searchable outlet dropdown and refetches with home_outlet_id', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderPos();
    await screen.findByText('Keychain A');

    const outletTrigger = screen.getByText('Semua outlet');
    await user.click(outletTrigger);
    await user.click(await screen.findByRole('option', { name: 'Outlet A' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ home_outlet_id: [1] })));
  });

  it('returns to showing all outlets when "All" is re-selected in the dropdown', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderPos();
    await screen.findByText('Keychain A');

    const outletTrigger = screen.getByText('Semua outlet');
    await user.click(outletTrigger);
    await user.click(await screen.findByRole('option', { name: 'Outlet A' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ home_outlet_id: [1] })));

    // The panel stays open after a selection (multi-select) — pick "All"
    // directly to clear the selection back to unfiltered.
    await user.click(await screen.findByRole('option', { name: 'Semua outlet' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.not.objectContaining({ home_outlet_id: expect.anything() })));
  });
});

/**
 * 030-fix-pos-search-product-image — hasil pencarian POS (GET /variants/lookup) harus
 * menampilkan foto yang sama dengan grid browse. Dulu PosView menyalin hanya sebagian
 * field hit ke kartu dan `image_url` terbuang: kartu menampilkan ikon placeholder.
 */
describe('PosView — product photo on search results (030)', () => {
  const PHOTO = 'https://example.test/slippery.png';
  const hit = (overrides = {}) => ({
    variant_id: 31,
    sku: 'SPF-PI-MCY-014',
    label: 'MCYT — Slippery',
    home_outlet_name: 'Outlet A',
    category_name: 'Pin',
    sell_price: '15000.00',
    current_stock: 20,
    is_preorder: false,
    image_url: PHOTO,
    ...overrides,
  });

  async function searchFor(term) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderPos();
    await screen.findByText('Keychain A');
    await user.type(screen.getByLabelText('Cari nama produk atau SKU'), term);
    return user;
  }

  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null);
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    listOutlets.mockResolvedValue({ data: [{ id: 1, name: 'Outlet A' }] });
    listProducts.mockResolvedValue({ data: PRODUCTS });
  });

  it('shows the hit\'s photo on its result card instead of the placeholder icon', async () => {
    lookupVariants.mockResolvedValue({ data: [hit()] });
    await searchFor('slippery');

    const img = await screen.findByAltText('MCYT — Slippery');
    expect(img).toHaveAttribute('src', PHOTO);
  });

  it('shows the hit\'s category name on the card (same label as the browse cards), and none when the hit has no category', async () => {
    lookupVariants.mockResolvedValue({
      data: [hit(), hit({ variant_id: 32, sku: 'SPF-PI-MCY-015', label: 'MCYT — Slippery (40cm)', category_name: null })],
    });
    await searchFor('slippery');

    const withCategory = (await screen.findByText('MCYT — Slippery')).closest('button');
    expect(withCategory).toHaveTextContent('Pin');
    const without = screen.getByText('MCYT — Slippery (40cm)').closest('button');
    expect(without).not.toHaveTextContent('Pin');
  });

  it('shows the photo for a SKU-style search as well', async () => {
    lookupVariants.mockResolvedValue({ data: [hit()] });
    await searchFor('MCY-014');

    expect(await screen.findByAltText('MCYT — Slippery')).toHaveAttribute('src', PHOTO);
    expect(lookupVariants).toHaveBeenLastCalledWith('MCY-014', 40, { pos: true, withModifiers: true });
  });

  it('keeps the placeholder icon and no <img> for a hit that has no photo, and each card keeps its own photo', async () => {
    lookupVariants.mockResolvedValue({
      data: [
        hit(),
        hit({ variant_id: 32, sku: 'SPF-PI-MCY-015', label: 'MCYT — Slippery (40cm)', image_url: null, sell_price: '40000.00', current_stock: 9 }),
      ],
    });
    await searchFor('slippery');

    await screen.findByAltText('MCYT — Slippery');
    const noPhotoCard = screen.getByText('MCYT — Slippery (40cm)').closest('button');
    expect(noPhotoCard.querySelector('img')).toBeNull();
    expect(noPhotoCard.querySelector('i.ph-package')).not.toBeNull();
    // konten kartu lain tak berubah
    expect(noPhotoCard).toHaveTextContent('Rp 40.000');
    expect(noPhotoCard).toHaveTextContent('SPF-PI-MCY-015');
  });

  // US2 — foto yang sama di kartu hasil, di baris keranjang, dan di grid browse setelah pencarian dihapus.
  it('shows the same photo on the cart line after adding the item from a search result', async () => {
    lookupVariants.mockResolvedValue({ data: [hit()] });
    const user = await searchFor('slippery');

    await user.click((await screen.findByAltText('MCYT — Slippery')).closest('button'));

    // kartu hasil + baris keranjang: dua <img> dengan foto yang sama
    const cartPhotoButton = await screen.findByRole('button', { name: 'Perbesar gambar MCYT — Slippery' });
    expect(cartPhotoButton.querySelector('img')).toHaveAttribute('src', PHOTO);
  });

  it('returns to the browse grid with its own photos unchanged when the search is cleared', async () => {
    lookupVariants.mockResolvedValue({ data: [hit()] });
    const user = await searchFor('slippery');
    await screen.findByAltText('MCYT — Slippery');

    await user.clear(screen.getByLabelText('Cari nama produk atau SKU'));

    await waitFor(() => expect(screen.queryByAltText('MCYT — Slippery')).not.toBeInTheDocument());
    expect(screen.getByAltText('Keychain A')).toHaveAttribute('src', 'https://example.test/a.png');
  });

  it('keeps the photos on the result cards when the outlet filter is changed while results are shown', async () => {
    lookupVariants.mockResolvedValue({ data: [hit()] });
    const user = await searchFor('slippery');
    await screen.findByAltText('MCYT — Slippery');

    await user.click(screen.getByText('Semua outlet'));
    await user.click(await screen.findByRole('option', { name: 'Outlet A' }));

    expect(await screen.findByAltText('MCYT — Slippery')).toHaveAttribute('src', PHOTO);
  });

  // Dua pencarian bertumpuk: respons yang LEBIH LAMA tiba SETELAH yang lebih baru tidak boleh
  // menimpa hasil (dan foto-fotonya) milik pencarian terakhir.
  it('ignores a late response from an older search and keeps the final results with their photos', async () => {
    const deferred = () => {
      let resolve;
      const promise = new Promise((r) => { resolve = r; });
      return { promise, resolve };
    };
    const older = deferred();
    const newer = deferred();
    lookupVariants.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise);
    const user = await searchFor('slip');
    await waitFor(() => expect(lookupVariants).toHaveBeenCalledTimes(1));

    await user.type(screen.getByLabelText('Cari nama produk atau SKU'), 'pery');
    await waitFor(() => expect(lookupVariants).toHaveBeenCalledTimes(2));

    newer.resolve({ data: [hit({ label: 'MCYT — Slippery', image_url: PHOTO })] });
    expect(await screen.findByAltText('MCYT — Slippery')).toHaveAttribute('src', PHOTO);

    older.resolve({ data: [hit({ variant_id: 99, label: 'Produk Lama', image_url: 'https://example.test/lama.png' })] });
    await new Promise((r) => setTimeout(r, 50));

    expect(screen.queryByAltText('Produk Lama')).not.toBeInTheDocument();
    expect(screen.getByAltText('MCYT — Slippery')).toHaveAttribute('src', PHOTO);
  });
});

// 072-store-identity-branding (US4) — POS pas satu layar: pencarian/filter tetap di atas, hanya daftar produk menggulir,
// dan akar mengisi sisa tinggi area utama (bukan h-full, yang ditambah judul bawaan melampaui area utama).
describe('PosView — satu layar, daftar produk menggulir sendiri (072)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null);
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    listOutlets.mockResolvedValue({ data: [{ id: 1, name: 'Outlet A' }] });
    listProducts.mockResolvedValue({ data: PRODUCTS });
  });

  it('grid produk berada di wadah gulir sendiri dan pencarian berada di luarnya', async () => {
    renderPos();
    const scroller = await screen.findByTestId('pos-product-scroll');

    expect(scroller).toHaveClass('min-h-0', 'flex-1', 'overflow-auto');
    expect(scroller.contains(screen.getByLabelText(/cari nama produk/i))).toBe(false);
    await waitFor(() => expect(scroller.querySelector('button')).not.toBeNull());
  });

  it('kolom produk tidak menggulir sendiri dan akar mengisi sisa tinggi', async () => {
    const { container } = renderPos();
    const scroller = await screen.findByTestId('pos-product-scroll');
    const column = scroller.parentElement;

    expect(column).not.toHaveClass('overflow-auto');
    expect(column).toHaveClass('min-h-0');
    expect(container.firstElementChild).toHaveClass('flex', 'min-h-0', 'flex-1');
    expect(container.firstElementChild).not.toHaveClass('h-full');
  });
});
