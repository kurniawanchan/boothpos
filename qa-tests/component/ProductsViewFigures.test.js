import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import { listProducts, productSummary } from '../../resources/js/api/products';
import { ROW, COUNTS, SUMMARY, META, renderProducts, userEv } from './productsViewHarness';

// 070 (US2) — angka kepala dihitung dari himpunan yang sama dengan daftar: dimuat ulang saat outlet/kategori/status/cari
// berubah, TIDAK saat quality/availability/urutan/halaman/tab berubah (D3, D8), dan respons usang tidak menimpa yang baru.
vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(), productSummary: vi.fn(), getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(),
  deleteProduct: vi.fn(), addVariant: vi.fn(), updateVariant: vi.fn(), uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn().mockResolvedValue({ data: [{ id: 3, name: 'Kopi' }] }) }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 15, total: 0, last_page: 1 } }), createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }), createModifierGroup: vi.fn(), updateModifierGroup: vi.fn(), deleteModifierGroup: vi.fn(), setModifierGroupAttachments: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

const OUTLETS = [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }];
const lastSummaryParams = () => productSummary.mock.calls.at(-1)[0];
const summaryWith = (count, total) => ({ ...SUMMARY, active: { count, total, draft: 0, discontinued: 0 } });

async function pickOutlet(user, name) {
  await user.click(within(screen.getByTestId('outlet-filter')).getByRole('button'));
  await user.click(await screen.findByRole('option', { name }));
}

describe('ProductsView figures follow the filters (070 US2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    productSummary.mockResolvedValue(SUMMARY);
    listProducts.mockResolvedValue({ data: [ROW(1)], meta: META(1), counts: COUNTS });
  });

  it('asks for the summary with the same filters as the list and skips the top-bar header', async () => {
    await renderProducts({ scope: { outlets: OUTLETS }, url: '/products?outlet=2&category=3&status=active&q=mie' });

    await waitFor(() => expect(productSummary).toHaveBeenCalled());
    expect(productSummary).toHaveBeenCalledWith({ outlet_id: [2], category_id: [3], status: ['active'], q: 'mie' }, { skipOutletHeader: true });
  });

  it('sends no filter keys when nothing is filtered', async () => {
    await renderProducts({ scope: { outlets: OUTLETS } });

    await waitFor(() => expect(productSummary).toHaveBeenCalled());
    expect(productSummary.mock.calls[0][0]).toEqual({});
  });

  it('reloads the figures when the outlet filter changes', async () => {
    const user = await userEv();
    await renderProducts({ scope: { outlets: OUTLETS } });
    await waitFor(() => expect(productSummary).toHaveBeenCalledTimes(1));
    productSummary.mockResolvedValue(summaryWith(5, 7));

    await pickOutlet(user, 'Warkop WJM 2');

    await waitFor(() => expect(lastSummaryParams()).toEqual({ outlet_id: [2] }));
    const active = await screen.findByTestId('figure-active');
    await waitFor(() => expect(within(active).getByText('5')).toBeInTheDocument());
    expect(active).toHaveTextContent('dari 7 item');
  });

  it('does not reload the figures for quality, availability, sort, page or tab changes', async () => {
    const user = await userEv();
    const { router } = await renderProducts({ scope: { outlets: OUTLETS } });
    await waitFor(() => expect(productSummary).toHaveBeenCalledTimes(1));

    await user.click(await screen.findByTestId('figure-sold_out'));
    await waitFor(() => expect(listProducts.mock.calls.at(-1)[0].availability).toBe('sold_out'));
    await router.replace({ query: { ...router.currentRoute.value.query, sort: 'price', page: '1', tab: 'retail' } });
    await waitFor(() => expect(listProducts.mock.calls.at(-1)[0].tab).toBe('retail'));

    expect(productSummary).toHaveBeenCalledTimes(1);
  });

  it('keeps the old numbers visible but marked busy while the new ones load, and ignores a stale answer', async () => {
    const user = await userEv();
    await renderProducts({ scope: { outlets: OUTLETS } });
    await waitFor(() => expect(productSummary).toHaveBeenCalledTimes(1));
    let resolveSlow;
    productSummary.mockImplementationOnce(() => new Promise((res) => { resolveSlow = res; }));
    productSummary.mockResolvedValueOnce(summaryWith(2, 3));

    await pickOutlet(user, 'Warkop WJM 1'); // permintaan lambat (usang)
    await waitFor(() => expect(screen.getByTestId('product-figures')).toHaveAttribute('aria-busy', 'true'));
    // Panel pilih-banyak tetap terbuka setelah memilih: opsi kedua langsung diklik (menambah WJM 2).
    await user.click(await screen.findByRole('option', { name: 'Warkop WJM 2' })); // permintaan baru selesai lebih dulu
    const active = await screen.findByTestId('figure-active');
    await waitFor(() => expect(within(active).getByText('2')).toBeInTheDocument());

    resolveSlow(summaryWith(99, 99));
    await new Promise((r) => setTimeout(r, 20));

    expect(screen.getByTestId('figure-active')).not.toHaveTextContent('99');
    expect(screen.getByTestId('product-figures')).toHaveAttribute('aria-busy', 'false');
  });

  it('shows the error state with retry when the filtered summary fails', async () => {
    const user = await userEv();
    await renderProducts({ scope: { outlets: OUTLETS } });
    await waitFor(() => expect(productSummary).toHaveBeenCalledTimes(1));
    productSummary.mockRejectedValueOnce(new Error('boom'));

    await pickOutlet(user, 'Warkop WJM 2');

    expect(await screen.findByRole('button', { name: /Coba lagi/ })).toBeInTheDocument();
  });
});
