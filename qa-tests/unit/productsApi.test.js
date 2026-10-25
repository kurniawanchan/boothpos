import { describe, it, expect, vi, beforeEach } from 'vitest';

// 043 (T019) — pembungkus API hanya mengirim: pastikan metode, alamat, dan badan sesuai kontrak
// (contracts/api-products.md, api-menu-release.md, api-recipes.md). Tanpa jaringan: klien axios di-mock.
const { client } = vi.hoisted(() => ({
  client: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));
vi.mock('../../resources/js/api/client', () => ({ default: client }));

import * as products from '../../resources/js/api/products';
import * as menu from '../../resources/js/api/menu';
import * as recipes from '../../resources/js/api/recipes';
import * as reasons from '../../resources/js/api/reasons';
import { listPermissionCatalog } from '../../resources/js/api/roles';

beforeEach(() => {
  vi.clearAllMocks();
  for (const m of ['get', 'post', 'put', 'delete']) client[m].mockResolvedValue({ data: { ok: m }, status: 200 });
});

describe('api/products (043)', () => {
  it('lists with the new query params and returns the response body', async () => {
    const params = { tab: 'retail', q: 'kopi', category_id: [1, 2], status: ['active'], quality: 'no_station', sort: '-price', page: 2, per_page: 15 };
    await expect(products.listProducts(params)).resolves.toEqual({ ok: 'get' });
    expect(client.get).toHaveBeenCalledWith('/products', { params });
  });

  it('reads the summary from GET /products/summary', async () => {
    await products.productSummary();
    expect(client.get).toHaveBeenCalledWith('/products/summary', { params: {} });
  });

  it('forwards filters and the skip-outlet-header option on list and summary (070)', async () => {
    const params = { outlet_id: [2, 3], category_id: [1], status: ['active'], q: 'mie' };
    await products.listProducts({ tab: 'menu', ...params }, { skipOutletHeader: true });
    expect(client.get).toHaveBeenCalledWith('/products', { params: { tab: 'menu', ...params }, skipOutletHeader: true });
    await products.productSummary(params, { skipOutletHeader: true });
    expect(client.get).toHaveBeenCalledWith('/products/summary', { params, skipOutletHeader: true });
  });

  it('marks and clears sold-out through POST/DELETE /products/{id}/sold-out', async () => {
    await products.soldOut(12, { reason_code_id: 3, note: 'habis' });
    expect(client.post).toHaveBeenCalledWith('/products/12/sold-out', { reason_code_id: 3, note: 'habis' });
    await products.undoSoldOut(12);
    expect(client.delete).toHaveBeenCalledWith('/products/12/sold-out');
    await products.undoSoldOut(12, [2, 3]);
    expect(client.delete).toHaveBeenCalledWith('/products/12/sold-out', { data: { outlet_ids: [2, 3] } });
  });

  it('discontinues with a reason and reactivates', async () => {
    await products.discontinueProduct(12, { reason_code_id: 4 });
    expect(client.post).toHaveBeenCalledWith('/products/12/discontinue', { reason_code_id: 4 });
    await products.reactivateProduct(12);
    expect(client.post).toHaveBeenCalledWith('/products/12/reactivate', {});
  });

  it('reads price history, and saves variants and modifier links as batches', async () => {
    await products.priceHistory(12, { page: 2 });
    expect(client.get).toHaveBeenCalledWith('/products/12/price-history', { params: { page: 2 } });
    await products.saveVariants(12, { variants: [{ name: 'Reguler', base_price: '8000' }] });
    expect(client.put).toHaveBeenCalledWith('/products/12/variants', { variants: [{ name: 'Reguler', base_price: '8000' }] });
    await products.saveModifierLinks(12, [{ group_id: 1, is_required: true, min_select: 1, max_select: 1, display_order: 0 }]);
    expect(client.put).toHaveBeenCalledWith('/products/12/modifier-links', { links: [{ group_id: 1, is_required: true, min_select: 1, max_select: 1, display_order: 0 }] });
  });

  it('keeps the pre-existing wrappers working', async () => {
    await products.getProduct(5);
    expect(client.get).toHaveBeenCalledWith('/products/5');
    await products.deleteProduct(5);
    expect(client.delete).toHaveBeenCalledWith('/products/5');
  });
});

describe('api/menu (043)', () => {
  it('previews and applies a bulk price change', async () => {
    const body = { category_ids: [1], type: 'percent', value: 5, rounding: '500', effective_from: '2026-10-20' };
    await menu.previewPriceChange(body);
    expect(client.post).toHaveBeenCalledWith('/price-changes/preview', body);
    await menu.applyPriceChange(body);
    expect(client.post).toHaveBeenCalledWith('/price-changes', body);
  });

  it('lists and cancels menu changes, and manages releases', async () => {
    await menu.listMenuChanges({ status: 'pending' });
    expect(client.get).toHaveBeenCalledWith('/menu/changes', { params: { status: 'pending' } });
    await menu.cancelMenuChange(9);
    expect(client.post).toHaveBeenCalledWith('/menu/changes/9/cancel');
    await menu.listMenuReleases();
    expect(client.get).toHaveBeenCalledWith('/menu/releases', { params: {} });
    await menu.getMenuRelease(4);
    expect(client.get).toHaveBeenCalledWith('/menu/releases/4');
    await menu.createMenuRelease({ publish_at: null, note: 'x' });
    expect(client.post).toHaveBeenCalledWith('/menu/releases', { publish_at: null, note: 'x' });
    await menu.cancelMenuRelease(4);
    expect(client.post).toHaveBeenCalledWith('/menu/releases/4/cancel');
  });

  it('lists, approves and rejects approvals', async () => {
    await menu.listApprovals({ kind: 'menu_price', status: 'pending' });
    expect(client.get).toHaveBeenCalledWith('/approvals', { params: { kind: 'menu_price', status: 'pending' } });
    await menu.approveApproval(7);
    expect(client.post).toHaveBeenCalledWith('/approvals/7/approve');
    await menu.rejectApproval(7, { reason_code_id: 2 });
    expect(client.post).toHaveBeenCalledWith('/approvals/7/reject', { reason_code_id: 2 });
  });

  it('polls availability with the etag and maps 304 to { notModified: true }', async () => {
    client.get.mockResolvedValueOnce({ status: 200, data: { etag: 'a1', sold_out: [] } });
    await expect(menu.pollAvailability('a0')).resolves.toEqual({ etag: 'a1', sold_out: [] });
    expect(client.get).toHaveBeenLastCalledWith('/menu/availability', expect.objectContaining({ params: { since: 'a0' } }));

    client.get.mockResolvedValueOnce({ status: 304, data: '' });
    await expect(menu.pollAvailability('a1')).resolves.toEqual({ notModified: true });

    // 304 harus diterima sebagai respons, bukan galat axios.
    const options = client.get.mock.lastCall[1];
    expect(options.validateStatus(304)).toBe(true);
    expect(options.validateStatus(500)).toBe(false);
  });

  it('sends no `since` on the first poll', async () => {
    client.get.mockResolvedValueOnce({ status: 200, data: { etag: 'x' } });
    await menu.pollAvailability();
    expect(client.get.mock.lastCall[1].params).toEqual({});
  });
});

describe('api/recipes (043)', () => {
  it('lists versions, reads one, creates the next version and recalculates cost', async () => {
    await recipes.listRecipes(12);
    expect(client.get).toHaveBeenCalledWith('/products/12/recipes');
    await recipes.getRecipe(3);
    expect(client.get).toHaveBeenCalledWith('/recipes/3');
    const body = { yield: 1, effective_from: '2026-10-20', variant_ids: [1], lines: [{ material_id: 2, quantity: 10, shrinkage_pct: 0 }] };
    await recipes.createRecipe(12, body);
    expect(client.post).toHaveBeenCalledWith('/products/12/recipes', body);
    await recipes.recalculateRecipeCost(12);
    expect(client.post).toHaveBeenCalledWith('/products/12/recipes/recalculate');
  });
});

describe('api/reasons and the permission catalog (043)', () => {
  it('lists reason codes by category with inactive ones on request', async () => {
    await reasons.listReasonCodes({ category: 'sold_out', include_inactive: 1 });
    expect(client.get).toHaveBeenCalledWith('/reason-codes', { params: { category: 'sold_out', include_inactive: 1 } });
  });

  it('creates and updates a reason code', async () => {
    const body = { category: 'sold_out', code: 'stok_habis', label_id: 'Stok habis', label_en: 'Out of stock', sort: 10 };
    await reasons.createReasonCode(body);
    expect(client.post).toHaveBeenCalledWith('/reason-codes', body);
    await reasons.updateReasonCode(5, { is_active: false });
    expect(client.put).toHaveBeenCalledWith('/reason-codes/5', { is_active: false });
  });

  it('reads the permission catalog', async () => {
    await listPermissionCatalog();
    expect(client.get).toHaveBeenCalledWith('/permission-catalog');
  });
});
