import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

vi.mock('../../resources/js/api/auth', () => ({ login: vi.fn(), logout: vi.fn(), me: vi.fn() }));
vi.mock('../../resources/js/utils/tabSession', () => ({ adoptTokenFromOtherTabs: vi.fn(), broadcastLogout: vi.fn() }));

import * as authApi from '../../resources/js/api/auth';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useAbility } from '../../resources/js/composables/useAbility';

/** 057 — SPA: canDo membaca `permissions` dari server; payload lama tanpa `permissions` = akses penuh menu_keys. */
describe('auth store permissions (057)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('canDo reads the resolved permissions map', () => {
    const auth = useAuthStore();
    auth.user = { menu_keys: ['sales'], permissions: { sales: ['view', 'verify'] } };

    expect(auth.canDo('sales')).toBe(true);
    expect(auth.canDo('sales', 'verify')).toBe(true);
    expect(auth.canDo('sales', 'void')).toBe(false);
    expect(auth.canDo('pos', 'view')).toBe(false);
  });

  it('a legacy payload without permissions shows everything (cosmetic only — the server still enforces)', () => {
    const auth = useAuthStore();
    auth.user = { menu_keys: ['sales'] };

    expect(auth.canDo('sales', 'void')).toBe(true);
    expect(auth.canDo('pos', 'create')).toBe(true);
  });

  it('without any permission information nothing is hidden (cosmetic only)', () => {
    expect(useAuthStore().canDo('sales', 'view')).toBe(true);
  });

  it('refreshPermissions reloads /auth/me and the change is visible immediately', async () => {
    const auth = useAuthStore();
    auth.user = { menu_keys: ['sales'], permissions: { sales: ['view', 'void'] } };
    authApi.me.mockResolvedValue({ menu_keys: ['sales'], permissions: { sales: ['view'] } });

    await auth.refreshPermissions();

    expect(auth.canDo('sales', 'void')).toBe(false);
  });

  it('useAbility().can accepts "menu.action" and "menu" and is reactive', async () => {
    const auth = useAuthStore();
    auth.user = { menu_keys: ['sales'], permissions: { sales: ['view', 'void'] } };
    let ability;
    const Probe = defineComponent({
      setup() { ability = useAbility(); return () => h('div', ability.can('sales.void') ? 'yes' : 'no'); },
    });
    const w = mount(Probe);

    expect(w.text()).toBe('yes');
    expect(ability.can('sales')).toBe(true);
    expect(ability.can('sales.verify')).toBe(false);

    auth.user = { menu_keys: ['sales'], permissions: { sales: ['view'] } };
    await w.vm.$nextTick();
    expect(w.text()).toBe('no');
  });
});
