import { describe, it, expect, beforeEach } from 'vitest';
import { ref } from 'vue';
import { createPinia, setActivePinia } from 'pinia';
import { render, screen } from '@testing-library/vue';
import { useAuthStore, SELLER_SCOPED_HIDDEN_MENUS } from '../../resources/js/stores/auth';
import { useSalesFilters } from '../../resources/js/composables/useSalesFilters';
import SellerInactiveBanner from '../../resources/js/components/layout/SellerInactiveBanner.vue';

const MENUS = ['dashboard', 'pos', 'products', 'users', 'roles', 'settings', 'events', 'reports', 'sales'];

describe('058 seller scope (SPA, cosmetic mirror of the server)', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('hides administrative menus from a seller-scoped user and keeps the rest', () => {
    const auth = useAuthStore();
    auth.user = { menu_keys: MENUS, seller_scoped: true, artist: { id: 1, name: 'Seller A' }, seller_active: true };

    for (const key of SELLER_SCOPED_HIDDEN_MENUS) expect(auth.canAccessMenu(key)).toBe(false);
    for (const key of ['dashboard', 'pos', 'products', 'reports', 'sales']) expect(auth.canAccessMenu(key)).toBe(true);
    expect(auth.isSellerScoped).toBe(true);
    expect(auth.sellerInactive).toBe(false);
  });

  it('changes nothing for a user without a seller (and for the Owner, which the server reports as not scoped)', () => {
    const auth = useAuthStore();
    auth.user = { menu_keys: MENUS, seller_scoped: false, artist: null };
    expect(MENUS.every((k) => auth.canAccessMenu(k))).toBe(true);

    auth.user = { menu_keys: MENUS }; // payload lama tanpa field baru
    expect(MENUS.every((k) => auth.canAccessMenu(k))).toBe(true);
    expect(auth.isSellerScoped).toBe(false);
  });

  it('flags an inactive seller (empty view with a notice, never a full view)', () => {
    const auth = useAuthStore();
    auth.user = { menu_keys: MENUS, seller_scoped: true, seller_active: false };
    expect(auth.sellerInactive).toBe(true);
  });

  it('renders the inactive-seller notice as an alert', () => {
    render(SellerInactiveBanner);
    expect(screen.getByTestId('seller-inactive-banner')).toHaveAttribute('role', 'alert');
  });

  it('sales cards count only the locked seller share of a mixed order', () => {
    const tx = (id, items) => ({
      id, status: 'completed', created_at: '2026-11-05T10:00:00Z', total_amount: '20000.00', unit_count: 2,
      items_gross: '10000.00', items_net: '10000.00', artist_names: ['Seller A', 'Seller B'], seller_items: items,
    });
    const transactions = ref([tx(1, { 'Seller A': { units: 1, gross: '10000.00', net: '10000.00' }, 'Seller B': { units: 1, gross: '10000.00', net: '10000.00' } })]);

    const open = useSalesFilters({ transactions, sessions: ref([]) });
    expect(open.summary.value.units).toBe(2); // tanpa kunci: order utuh

    const locked = useSalesFilters({ transactions, sessions: ref([]), lockedSeller: ref('Seller A') });
    expect(locked.summary.value.units).toBe(1);
    expect(locked.summary.value.amount).toBe(10000);
  });
});
