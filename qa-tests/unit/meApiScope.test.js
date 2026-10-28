import { describe, it, expect, vi } from 'vitest';

// GET /me/scope harus bebas dari header X-Outlet-Id: dengan pilihan tersimpan yang sudah dicabut, header itu membuat
// server menjawab 403 sehingga outlet store tidak pernah sempat membuang pilihan basi (dashboard penuh galat 403).
vi.mock('../../resources/js/api/client', () => ({ default: { get: vi.fn().mockResolvedValue({ data: { outlets: [] } }) } }));

import client from '../../resources/js/api/client';
import { getScope } from '../../resources/js/api/me';

describe('getScope', () => {
  it('skips the outlet header so a stale stored selection cannot block it', async () => {
    await getScope();
    expect(client.get).toHaveBeenCalledWith('/me/scope', { skipOutletHeader: true });
  });
});
