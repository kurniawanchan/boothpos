import { describe, it, expect, vi, beforeEach } from 'vitest';

// 071 — daftar Meja/Stasiun halaman Restaurant Setup meminta tanpa header outlet bilah atas dan mengirim `outlet_id[]`
// sendiri; pemanggil lain (dapur, formulir produk) tetap memakai bentuk lama.
const { client } = vi.hoisted(() => ({ client: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('../../resources/js/api/client', () => ({ default: client }));

import { listDiningTables } from '../../resources/js/api/diningTables';
import { listStations } from '../../resources/js/api/stations';

beforeEach(() => {
  vi.clearAllMocks();
  client.get.mockResolvedValue({ data: { data: [] } });
});

describe('api/diningTables and api/stations outlet options (071)', () => {
  it('keeps the old call shape without options', async () => {
    await listDiningTables({ active: true });
    expect(client.get).toHaveBeenCalledWith('/dining-tables', { params: { active: true } });
    await listStations();
    expect(client.get).toHaveBeenCalledWith('/stations', { params: {} });
  });

  it('forwards outlet_id[] and the skip-outlet-header option', async () => {
    await listDiningTables({ outlet_id: [1, 2] }, { skipOutletHeader: true });
    expect(client.get).toHaveBeenCalledWith('/dining-tables', { params: { outlet_id: [1, 2] }, skipOutletHeader: true });
    await listStations({ outlet_id: [2] }, { skipOutletHeader: true });
    expect(client.get).toHaveBeenCalledWith('/stations', { params: { outlet_id: [2] }, skipOutletHeader: true });
  });
});
