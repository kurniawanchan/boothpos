import { describe, it, expect, vi, beforeEach } from 'vitest';

// Spec 041 (FR-013/FR-016): ekspor produk harus membawa filter layar ke API.
// Klien HTTP di-stub; tidak butuh backend.
const get = vi.fn();
vi.mock('../../resources/js/api/client', () => ({ default: { get: (...a) => get(...a) } }));

import { exportMasterData } from '../../resources/js/api/masterData';

describe('exportMasterData (spec 041)', () => {
  beforeEach(() => {
    get.mockReset();
    get.mockResolvedValue({ data: new Blob(['x']) });
    // downloadBlob membuat tautan unduhan; cukup di-stub di lingkungan uji.
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:test');
    globalThis.URL.revokeObjectURL = vi.fn();
    // Klik tautan unduhan memicu navigasi jsdom yang tidak diimplementasikan; hentikan.
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  });

  it('sends the screen filters as query params for the products sheet', async () => {
    await exportMasterData('products', {
      search: 'Sakura',
      artist_id: [3, 5],
      category_id: [2],
    });

    expect(get).toHaveBeenCalledTimes(1);
    const [url, config] = get.mock.calls[0];
    expect(url).toBe('/exports/products');
    expect(config.params).toEqual({ search: 'Sakura', artist_id: [3, 5], category_id: [2] });
    expect(config.responseType).toBe('blob');
  });

  it('keeps the original call shape when no filters are given', async () => {
    await exportMasterData('stock');

    const [url, config] = get.mock.calls[0];
    expect(url).toBe('/exports/stock');
    expect(config.params).toEqual({});
    expect(config.responseType).toBe('blob');
  });
});
