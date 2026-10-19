import { describe, it, expect, vi, beforeEach } from 'vitest';

// 054 / US3 — helper API alamat server dan pemetaan 422 per kolom yang dipakai ServerAddressSection.vue.
vi.mock('../../resources/js/api/client', () => ({
  default: { get: vi.fn(), put: vi.fn() },
}));

import client from '../../resources/js/api/client';
import { getServerAddress, saveServerAddress } from '../../resources/js/api/settings';
import { normalizeAxiosError } from '../../resources/js/utils/errors';

describe('server address API helpers', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('reads the admin endpoint', async () => {
    client.get.mockResolvedValue({ data: { local: null, online: null, selected: 'local' } });
    const data = await getServerAddress();
    expect(client.get).toHaveBeenCalledWith('/settings/server-address');
    expect(data.selected).toBe('local');
  });

  it('saves the three values in one PUT to the admin endpoint', async () => {
    client.put.mockResolvedValue({ data: { local: null, online: 'https://pos.example.com', selected: 'online' } });
    const payload = { local: null, online: 'https://pos.example.com', selected: 'online' };
    await saveServerAddress(payload);
    expect(client.put).toHaveBeenCalledWith('/settings/server-address', payload);
  });
});

describe('server address validation errors', () => {
  it('a 422 keeps the per-field messages the section maps onto its inputs', () => {
    const err = normalizeAxiosError({
      response: {
        status: 422,
        data: {
          message: 'Validasi gagal',
          errors: {
            online: ['Alamat server online harus berupa URL http atau https yang lengkap.'],
            selected: ['Pilih online hanya jika alamat online sudah diisi.'],
          },
        },
      },
    });
    expect(err.isValidation).toBe(true);
    expect(err.errors.online[0]).toContain('http atau https');
    expect(err.errors.selected[0]).toContain('alamat online');
  });

  it('a 403 is a role denial, not a field error', () => {
    const err = normalizeAxiosError({ response: { status: 403, data: { message: 'Tidak berhak.' } } });
    expect(err.isForbidden).toBe(true);
    expect(err.isValidation).toBe(false);
  });
});
