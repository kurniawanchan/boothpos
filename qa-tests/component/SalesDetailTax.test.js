import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import OpenOrderDetail from '../../resources/js/components/sales/OpenOrderDetail.vue';
import TransactionItemsModal from '../../resources/js/components/sales/TransactionItemsModal.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import * as tabsApi from '../../resources/js/api/tabs';
import { getOrder } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/tabs', () => ({
  getTab: vi.fn(), openTab: vi.fn(), closeEmptyTab: vi.fn(), addTabLine: vi.fn(), updateTabLine: vi.fn(), removeTabLine: vi.fn(),
  voidTab: vi.fn(), payTab: vi.fn(), sendTab: vi.fn(), moveTab: vi.fn(), mergeTab: vi.fn(), splitTab: vi.fn(),
}));
vi.mock('../../resources/js/api/orders', () => ({
  getOrder: vi.fn(), getReceipt: vi.fn(), voidOrder: vi.fn(), addOrderPayment: vi.fn(), deleteOrderPayment: vi.fn(),
}));
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }), uploadPaymentProof: vi.fn(),
  updatePaymentConfirmation: vi.fn(), getPaymentProofBlobUrl: vi.fn(), verifyPayment: vi.fn(),
}));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => ({ success: vi.fn(), error: vi.fn() }) }));

const TAB = {
  id: 9, order_number: 'WJM-0009', status: 'open', service_type: 'dine_in',
  table: { id: 4, name: 'T4', area: 'Indoor' }, tab_label: null, opened_by: 'Rina', session_id: 1, customer_id: null,
  subtotal: '7000.00', discount_amount: '0.00', tax_amount: '700.00', tax_rate: '10.00',
  total_amount: '7700.00', paid_amount: '0.00', change_amount: '0.00', balance: '7700.00',
  created_at: new Date().toISOString(),
  lines: [{ id: 1, variant_id: 5, name: 'Kopi', qty: 1, unit_price: '7000.00', line_total: '7000.00', modifiers: [], note: null, sent: false, prep_status: null, ticket_item_id: null, station: null }],
  payments: [],
};

const ORDER = {
  id: 101, order_number: 'TRX-0001', status: 'completed', created_at: '2026-09-27T11:23:00Z', notes: null, channel: 'offline',
  subtotal: '7000.00', discount_amount: '0.00', tax_amount: '700.00', tax_rate: '10.00', total_amount: '7700.00',
  paid_amount: '7700.00', change_amount: '0.00', void_reason: null, customer: null, cashier: { id: 1, name: 'Owner' },
  items: [{ id: 1, product_id: 10, sku_snapshot: 'SKU1', name_snapshot: 'Kopi', variant_name: 'Hot', home_outlet_name: 'Outlet A', category_name: 'Coffee', qty: 1, sell_price: '7000.00', discount_amount: '0.00', line_total: '7000.00', image_url: null }],
  payment_summary: { grand_total: '7700.00', total_paid: '7700.00', remaining: '0.00', status: 'fully_paid', payment_count: 1 },
  payments: [{ id: 1, method: 'cash', amount: '7700.00', verification: 'verified', status: 'paid', paid_at: '2026-09-27T11:24:00Z', provider: null }],
};

function pinia({ show = true, label = 'PPN' } = {}) {
  const p = createPinia();
  setActivePinia(p);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['sales', 'floor'] };
  Object.assign(useSettingsStore(), { taxRate: 10, taxLabel: label, taxShow: show });

  return p;
}

async function mountOpen(opts, tab = TAB) {
  tabsApi.getTab.mockResolvedValue(tab);
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/floor/tabs/:id', name: 'floor-tab', component: { template: '<div />' } }],
  });
  render(OpenOrderDetail, { props: { orderId: 9 }, global: { plugins: [pinia(opts), router] } });
  await flushPromises();
}

async function mountItems(opts, order = ORDER) {
  getOrder.mockResolvedValue(order);
  render(TransactionItemsModal, { props: { open: true, orderId: order.id }, global: { plugins: [pinia(opts)] } });
  await screen.findByText(order.order_number);
}

// 083 / US3 — detail Penjualan: pajak dari jumlah tersimpan order, mengikuti toggle, total tidak berubah.
describe('OpenOrderDetail — pajak', () => {
  beforeEach(() => vi.clearAllMocks());
  afterEach(() => vi.restoreAllMocks());

  it('menampilkan baris pajak tersimpan di antara diskon dan total', async () => {
    await mountOpen({});

    expect(screen.getByText('PPN (10%)')).toBeInTheDocument();
    expect(screen.getAllByText('Rp 700').length).toBeGreaterThan(0);
  });

  it('menyembunyikan baris pajak saat toggle mati; total tetap 7.700', async () => {
    await mountOpen({ show: false });

    expect(screen.queryByText(/PPN/)).not.toBeInTheDocument();
    expect(screen.getAllByText('Rp 7.700').length).toBeGreaterThan(0);
  });

  it('order tanpa pajak (tarif lama 0) tidak punya baris pajak', async () => {
    await mountOpen({}, { ...TAB, tax_amount: '0.00', tax_rate: '0.00', total_amount: '7000.00', balance: '7000.00' });

    expect(screen.queryByText(/PPN/)).not.toBeInTheDocument();
  });

  it('memakai tarif snapshot order, bukan tarif saat ini', async () => {
    await mountOpen({}, { ...TAB, tax_rate: '11.00', tax_amount: '770.00', total_amount: '7770.00' });

    expect(screen.getByText('PPN (11%)')).toBeInTheDocument();
  });
});

describe('TransactionItemsModal — pajak', () => {
  beforeEach(() => vi.clearAllMocks());

  it('menampilkan baris pajak sehingga rincian menjumlah ke total tersimpan', async () => {
    await mountItems({});

    expect(screen.getByTestId('order-tax')).toHaveTextContent('PPN (10%)');
    expect(screen.getByTestId('order-tax')).toHaveTextContent('Rp 700');
    expect(screen.getByTestId('order-totals')).toHaveTextContent('Rp 7.700');
  });

  it('menyembunyikan baris pajak saat toggle mati; total tetap 7.700', async () => {
    await mountItems({ show: false });

    expect(screen.queryByTestId('order-tax')).not.toBeInTheDocument();
    expect(screen.getByTestId('order-totals')).toHaveTextContent('Rp 7.700');
  });

  it('order lama tanpa pajak tidak punya baris pajak', async () => {
    await mountItems({}, { ...ORDER, tax_amount: '0.00', tax_rate: '0.00', total_amount: '7000.00' });

    expect(screen.queryByTestId('order-tax')).not.toBeInTheDocument();
  });
});
