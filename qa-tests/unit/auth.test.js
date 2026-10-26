import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('../../resources/js/api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
}));

vi.mock('../../resources/js/utils/tabSession', () => ({
  adoptTokenFromOtherTabs: vi.fn(),
  broadcastLogout: vi.fn(),
}));

import * as authApi from '../../resources/js/api/auth';
import * as tabSession from '../../resources/js/utils/tabSession';
import { useAuthStore } from '../../resources/js/stores/auth';

describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    sessionStorage.clear();
    vi.clearAllMocks();
    tabSession.adoptTokenFromOtherTabs.mockResolvedValue(null);
  });

  it('stores the token and user on successful login', async () => {
    authApi.login.mockResolvedValue({ token: 'abc123', user: { id: 1, username: 'kasir01', role: 'cashier' } });
    const auth = useAuthStore();
    await auth.login('kasir01', 'password123');
    expect(auth.isAuthenticated).toBe(true);
    expect(auth.role).toBe('cashier');
    expect(sessionStorage.getItem('boothpos_token')).toBe('abc123');
  });

  it('computes canAccessMenu from the resolved menu_keys', async () => {
    authApi.login.mockResolvedValue({
      token: 't',
      user: { id: 1, username: 'inventory', role: 'Inventory', menu_keys: ['dashboard', 'products', 'stock'] },
    });
    const auth = useAuthStore();
    await auth.login('inventory', 'x');
    expect(auth.canAccessMenu('reports')).toBe(false);
    expect(auth.canAccessMenu('products')).toBe(true);
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
  // 076-settlement-tab-session-branding (US1)
  it('restore() asks other tabs for a session when this tab has no token, then loads the user', async () => {
    tabSession.adoptTokenFromOtherTabs.mockImplementation(async () => {
      sessionStorage.setItem('boothpos_token', 'handed-over');
      return 'handed-over';
    });
    authApi.me.mockResolvedValue({ id: 1, username: 'owner', role: 'owner' });
    const auth = useAuthStore();
    await auth.restore();
    expect(tabSession.adoptTokenFromOtherTabs).toHaveBeenCalledTimes(1);
    expect(authApi.me).toHaveBeenCalledTimes(1);
    expect(auth.isAuthenticated).toBe(true);
  });

  it('restore() does not ask other tabs when it already has a token', async () => {
    sessionStorage.setItem('boothpos_token', 'mine');
    authApi.me.mockResolvedValue({ id: 1, username: 'owner', role: 'owner' });
    const auth = useAuthStore();
    await auth.restore();
    expect(tabSession.adoptTokenFromOtherTabs).not.toHaveBeenCalled();
  });

  it('a handed-over token that the server rejects (401) is cleared, leaving the tab signed out', async () => {
    tabSession.adoptTokenFromOtherTabs.mockImplementation(async () => {
      sessionStorage.setItem('boothpos_token', 'revoked');
      return 'revoked';
    });
    authApi.me.mockRejectedValue(new Error('401'));
    const auth = useAuthStore();
    await auth.restore();
    expect(auth.isAuthenticated).toBe(false);
    expect(sessionStorage.getItem('boothpos_token')).toBeNull();
  });

  it('concurrent restore() calls share one handoff', async () => {
    let resolveHandoff;
    tabSession.adoptTokenFromOtherTabs.mockReturnValue(new Promise((r) => (resolveHandoff = r)));
    const auth = useAuthStore();
    const a = auth.restore();
    const b = auth.restore();
    resolveHandoff(null);
    await Promise.all([a, b]);
    expect(tabSession.adoptTokenFromOtherTabs).toHaveBeenCalledTimes(1);
  });

  it('logout() broadcasts to the other tabs after clearing locally', async () => {
    authApi.login.mockResolvedValue({ token: 't', user: { id: 1, username: 'owner', role: 'owner' } });
    authApi.logout.mockResolvedValue({});
    const auth = useAuthStore();
    await auth.login('owner', 'x');
    await auth.logout();
    expect(tabSession.broadcastLogout).toHaveBeenCalledTimes(1);
    expect(sessionStorage.getItem('boothpos_token')).toBeNull();
  });

  it('endSessionFromAnotherTab() clears token and user without calling the server', async () => {
    authApi.login.mockResolvedValue({ token: 't', user: { id: 1, username: 'owner', role: 'owner' } });
    const auth = useAuthStore();
    await auth.login('owner', 'x');
    auth.endSessionFromAnotherTab();
    expect(auth.isAuthenticated).toBe(false);
    expect(sessionStorage.getItem('boothpos_token')).toBeNull();
    expect(authApi.logout).not.toHaveBeenCalled();
    expect(tabSession.broadcastLogout).not.toHaveBeenCalled();
  });
});
