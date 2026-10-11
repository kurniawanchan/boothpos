import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useAuthStore } from '../../resources/js/stores/auth';

// 043 — hasPermission membaca user.permissions; tidak ada daftar = false (kendali tersembunyi, bukan terbuka).
describe('useAuthStore.hasPermission', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('is true only for keys present in user.permissions', () => {
    const auth = useAuthStore();
    auth.user = { id: 1, permissions: ['menu.item.86', 'menu.price.bulk'] };
    expect(auth.hasPermission('menu.item.86')).toBe(true);
    expect(auth.hasPermission('menu.item.manage')).toBe(false);
  });

  it('is false when the payload carries no permissions or no user', () => {
    const auth = useAuthStore();
    expect(auth.hasPermission('menu.item.86')).toBe(false);
    auth.user = { id: 1 };
    expect(auth.hasPermission('menu.item.86')).toBe(false);
  });
});
