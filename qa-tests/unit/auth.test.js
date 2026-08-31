import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('../../resources/js/api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
}));

import * as authApi from '../../resources/js/api/auth';
import { useAuthStore } from '../../resources/js/stores/auth';

describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    sessionStorage.clear();
    vi.clearAllMocks();
  });

  it('stores the token and user on successful login', async () => {
    authApi.login.mockResolvedValue({ token: 'abc123', user: { id: 1, username: 'kasir01', role: 'cashier' } });
    const auth = useAuthStore();
    await auth.login('kasir01', 'password123');
    expect(auth.isAuthenticated).toBe(true);
    expect(auth.role).toBe('cashier');
    expect(sessionStorage.getItem('boothpos_token')).toBe('abc123');
  });

  it('computes isOwnerOrAdmin and canManageMasterData from the role', async () => {
    authApi.login.mockResolvedValue({ token: 't', user: { id: 1, username: 'inventory', role: 'inventory' } });
    const auth = useAuthStore();
    await auth.login('inventory', 'x');
    expect(auth.isOwnerOrAdmin).toBe(false);
    expect(auth.canManageMasterData).toBe(true);
  });

  it('clears state on logout even if the server call fails', async () => {
    authApi.login.mockResolvedValue({ token: 't', user: { id: 1, username: 'owner', role: 'owner' } });
    authApi.logout.mockRejectedValue(new Error('token already invalid'));
    const auth = useAuthStore();
    await auth.login('owner', 'x');
    await auth.logout();
    expect(auth.isAuthenticated).toBe(false);
    expect(sessionStorage.getItem('boothpos_token')).toBeNull();
  });

  it('restore() is a no-op when there is no stored token', async () => {
    const auth = useAuthStore();
    await auth.restore();
    expect(auth.ready).toBe(true);
    expect(auth.isAuthenticated).toBe(false);
    expect(authApi.me).not.toHaveBeenCalled();
  });

  it('restore() clears an invalid stored token', async () => {
    sessionStorage.setItem('boothpos_token', 'stale-token');
    authApi.me.mockRejectedValue(new Error('401'));
    const auth = useAuthStore();
    await auth.restore();
    expect(auth.isAuthenticated).toBe(false);
    expect(sessionStorage.getItem('boothpos_token')).toBeNull();
  });
});
