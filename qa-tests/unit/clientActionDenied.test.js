import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

const toast = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn() }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => toast }));
vi.mock('../../resources/js/api/auth', () => ({ login: vi.fn(), logout: vi.fn(), me: vi.fn() }));
vi.mock('../../resources/js/utils/tabSession', () => ({ adoptTokenFromOtherTabs: vi.fn(), broadcastLogout: vi.fn() }));

import client from '../../resources/js/api/client';
import * as authApi from '../../resources/js/api/auth';
import { useAuthStore } from '../../resources/js/stores/auth';

/** 057 (FR-012) — 403 `action_not_allowed`: pesan terjemahan server ditampilkan dan izin layar disegarkan. */
describe('api client — action_not_allowed', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  async function deny(code) {
    client.defaults.adapter = async (config) => Promise.reject({
      config, response: { status: 403, data: { message: 'Peran Anda tidak diizinkan.', code }, headers: {}, config },
    });
    await client.post('/orders/1/void').catch(() => {});
  }

  it('toasts the server message and refreshes permissions once', async () => {
    const auth = useAuthStore();
    auth.user = { menu_keys: ['sales'], permissions: { sales: ['view', 'void'] } };
    authApi.me.mockResolvedValue({ menu_keys: ['sales'], permissions: { sales: ['view'] } });

    await deny('action_not_allowed');
    await vi.waitFor(() => expect(authApi.me).toHaveBeenCalledTimes(1));

    expect(toast.error).toHaveBeenCalledWith('Peran Anda tidak diizinkan.');
    await vi.waitFor(() => expect(auth.canDo('sales', 'void')).toBe(false));
  });

  it('other 403s do not refresh permissions', async () => {
    await deny('something_else');

    expect(authApi.me).not.toHaveBeenCalled();
  });
});
