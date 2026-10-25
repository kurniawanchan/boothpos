import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/vue';
import { listProducts, productSummary } from '../../resources/js/api/products';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { ROW, COUNTS, SUMMARY, META, renderProducts, userEv } from './productsViewHarness';

// 070 (US1) — filter Outlet di baris filter halaman Produk: pilihan banyak, tersimpan di URL, terisi dari bilah atas,
// dan permintaan daftar TIDAK membawa header outlet bilah atas (filter halaman yang menentukan).
vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(), productSummary: vi.fn(), getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(),
  deleteProduct: vi.fn(), addVariant: vi.fn(), updateVariant: vi.fn(), uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 15, total: 0, last_page: 1 } }), createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }), createModifierGroup: vi.fn(), updateModifierGroup: vi.fn(), deleteModifierGroup: vi.fn(), setModifierGroupAttachments: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

const OUTLETS = [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }];
const lastListParams = () => listProducts.mock.calls.at(-1)[0];
const lastListOptions = () => listProducts.mock.calls.at(-1)[1];

async function pickOutlet(user, name) {
  await user.click(screen.getByRole('button', { name: /Semua outlet/ }));
  await user.click(await screen.findByRole('option', { name }));
}

describe('ProductsView outlet filter (070 US1)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    productSummary.mockResolvedValue(SUMMARY);
    listProducts.mockResolvedValue({ data: [ROW(1)], meta: META(1), counts: COUNTS });
  });

  it('shows the Outlet filter only when several outlets are visible', async () => {
    const { unmount } = await renderProducts({ scope: { outlets: OUTLETS } });
    await waitFor(() => expect(listProducts).toHaveBeenCalled());
    expect(screen.getByRole('button', { name: /Semua outlet/ })).toBeInTheDocument();
    unmount();

    await renderProducts({ scope: { outlets: [OUTLETS[0]], multi: false } });
    await waitFor(() => expect(listProducts).toHaveBeenCalled());
    expect(screen.queryByRole('button', { name: /Semua outlet/ })).not.toBeInTheDocument();
  });

  it('requests the chosen outlets and skips the top-bar header', async () => {
    const user = await userEv();
    const { router } = await renderProducts({ scope: { outlets: OUTLETS } });
    await waitFor(() => expect(listProducts).toHaveBeenCalled());
    expect(lastListParams().outlet_id).toBeUndefined();

    await pickOutlet(user, 'Warkop WJM 2');

    await waitFor(() => expect(lastListParams().outlet_id).toEqual([2]));
    expect(lastListOptions()).toEqual({ skipOutletHeader: true });
    expect(router.currentRoute.value.query.outlet).toBe('2');
  });

  it('starts from the outlet chosen in the top bar and follows it when it changes', async () => {
    await renderProducts({ scope: { outlets: OUTLETS, selected: 2 } });
    await waitFor(() => expect(listProducts).toHaveBeenCalled());
    expect(listProducts.mock.calls[0][0].outlet_id).toEqual([2]);

    useOutletStore().select(1);

    await waitFor(() => expect(lastListParams().outlet_id).toEqual([1]));
  });

  it('keeps an explicit "all outlets" choice even while the top bar has an outlet', async () => {
    const user = await userEv();
    const { router } = await renderProducts({ scope: { outlets: OUTLETS, selected: 2 } });
    await waitFor(() => expect(listProducts).toHaveBeenCalled());

    await user.click(screen.getByRole('button', { name: /Warkop WJM 2/ }));
    await user.click(await screen.findByRole('option', { name: 'Semua outlet' }));

    await waitFor(() => expect(lastListParams().outlet_id).toBeUndefined());
    expect(router.currentRoute.value.query.outlet).toBe('all');
  });

  it('restores the filter from the URL', async () => {
    await renderProducts({ scope: { outlets: OUTLETS }, url: '/products?outlet=1,2' });

    await waitFor(() => expect(listProducts).toHaveBeenCalled());
    expect(listProducts.mock.calls[0][0].outlet_id).toEqual([1, 2]);
  });

  it('ignores unknown outlet ids from the URL', async () => {
    await renderProducts({ scope: { outlets: OUTLETS }, url: '/products?outlet=99,abc' });

    await waitFor(() => expect(listProducts).toHaveBeenCalled());
    expect(listProducts.mock.calls[0][0].outlet_id).toBeUndefined();
  });

  it('resets the outlet filter to its starting point together with the other filters', async () => {
    const user = await userEv();
    const { router } = await renderProducts({ scope: { outlets: OUTLETS }, url: '/products?outlet=2&q=mie' });
    await waitFor(() => expect(listProducts).toHaveBeenCalled());

    await user.click(await screen.findByRole('button', { name: 'Atur ulang filter' }));

    await waitFor(() => expect(lastListParams().outlet_id).toBeUndefined());
    expect(router.currentRoute.value.query.outlet).toBeUndefined();
    expect(lastListParams().q).toBeUndefined();
  });

  it('offers a reset when the outlet filter alone leaves nothing', async () => {
    listProducts.mockResolvedValue({ data: [], meta: META(0), counts: { ...COUNTS, menu: 0 } });
    await renderProducts({ scope: { outlets: OUTLETS }, url: '/products?outlet=2' });

    expect(await screen.findByText('Atur ulang filter', { selector: 'button.underline, button' })).toBeInTheDocument();
  });
});
