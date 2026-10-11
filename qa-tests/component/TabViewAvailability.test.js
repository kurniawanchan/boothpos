import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import TabView from '../../resources/js/views/TabView.vue';
import { getTab, addTabLine } from '../../resources/js/api/tabs';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useToastStore } from '../../resources/js/stores/toast';
import { listProducts } from '../../resources/js/api/products';
import { pollAvailability } from '../../resources/js/api/menu';

vi.mock('../../resources/js/api/tabs', () => ({
  getTab: vi.fn(), addTabLine: vi.fn(), updateTabLine: vi.fn(), removeTabLine: vi.fn(), payTab: vi.fn(), closeEmptyTab: vi.fn(),
  openTab: vi.fn(), listTabs: vi.fn(), sendTab: vi.fn(), moveTab: vi.fn(), mergeTab: vi.fn(), splitTab: vi.fn(), voidTab: vi.fn(),
}));
vi.mock('../../resources/js/api/floor', () => ({ getFloor: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn(), lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn().mockResolvedValue({ data: [{ id: 1, code: 'MK', name: 'Makanan' }] }) }));
vi.mock('../../resources/js/api/kitchen', () => ({ setTicketItemStatus: vi.fn(), getStationTickets: vi.fn(), getTicket: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }), uploadPaymentProof: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getReceipt: vi.fn().mockResolvedValue(null) }));
vi.mock('../../resources/js/api/menu', () => ({ pollAvailability: vi.fn() }));

const variant = (id) => ({ id, sku: `S${id}`, variant_name: 'Standard', sell_price: '25000.00', current_stock: 10, is_active: true, track_stock: true });
const TAB = {
  id: 7, order_number: 'TRX-1', status: 'open', service_type: 'dine_in', table: { id: 1, name: '7', area: '' }, tab_label: null,
  session_id: 1, subtotal: '0.00', discount_amount: '0.00', total_amount: '0.00', paid_amount: '0.00', change_amount: '0.00', balance: '0.00', lines: [], payments: [],
};

// 043 (T047) — layar tab: item 86 redup, berlabel, dan ketukannya ditolak dengan toast; item lain tetap bisa ditambahkan.
describe('TabView — menu availability (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getTab.mockResolvedValue(TAB);
    listProducts.mockResolvedValue({
      data: [
        { id: 1, name: 'Es Kopi', category_id: 1, artist_name: 'R', image_url: null, variants: [variant(5)] },
        { id: 2, name: 'Nasi Goreng', category_id: 1, artist_name: 'R', image_url: null, variants: [variant(6)] },
      ],
    });
    pollAvailability.mockResolvedValue({ etag: 'e1', menu_version: 1, sold_out: [{ product_id: 2, variant_ids: [6], reason_code: 'out_of_stock', expires_at: null }], discontinued_ids: [] });
    addTabLine.mockResolvedValue(TAB);
  });

  it('marks and refuses the 86 item, still adds the available one', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    useAuthStore().user = { id: 1, role: 'Kasir', name: 'Kasir', menu_keys: ['floor'] };
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/floor', name: 'floor', component: { template: '<div />' } }, { path: '/floor/tabs/:id', name: 'floor-tab', component: TabView }],
    });
    router.push('/floor/tabs/7');
    await router.isReady();
    render(TabView, { global: { plugins: [pinia, router] } });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();

    await screen.findByTestId('menu-1');
    await waitFor(() => expect(screen.getAllByTestId('card-86')).toHaveLength(1));
    expect(screen.getByTestId('menu-2')).toHaveAttribute('aria-disabled', 'true');

    await user.click(screen.getByTestId('menu-2'));
    expect(addTabLine).not.toHaveBeenCalled();
    expect(useToastStore().items.map((i) => i.message)).toContain('Item sedang habis (86).');

    await user.click(screen.getByTestId('menu-1'));
    expect(addTabLine).toHaveBeenCalledWith(7, { variant_id: 5, qty: 1 });
  });
});
