import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import MaterialsTab from '../../resources/js/views/reports/MaterialsTab.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { getMaterialPosition, createMaterialPurchaseDrafts, getMaterialUsage, getMaterialUsageByMenu } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/reports', () => ({ getMaterialPosition: vi.fn(), createMaterialPurchaseDrafts: vi.fn(), getMaterialUsage: vi.fn(), getMaterialUsageByMenu: vi.fn(), exportMaterialReport: vi.fn() }));
vi.mock('vue-router', () => ({ useRoute: () => ({ query: globalThis.__routeQuery ?? {} }), useRouter: () => ({ push: vi.fn() }) }));

const ROW = (over = {}) => ({
  material_id: 10, code: 'INDM-AB', name: 'Indomie Ayam Bawang', unit: 'pcs', kind: 'ingredient', outlet_id: 14, outlet_code: 'WDM',
  on_hand: '-1.000', daily_usage: '4.000', days_left: 0, status: 'negative', usage_untracked: false, unit_cost: '3000.00', stock_value: '-3000.00',
  at_risk_options: [{ option_id: 51, option: 'AYAM BAWANG', group: 'Rasa', portions: 0 }], at_risk_products: [{ product_id: 789, name: 'Indomie Kuah' }],
  suggestion: null, ...over,
});
const RES = (rows = [ROW(), ROW({ material_id: 9, code: 'INDM-ST', name: 'Indomie Soto', on_hand: '6.000', days_left: 1.5, status: 'low', stock_value: '18000.00', at_risk_options: [], at_risk_products: [] })]) => ({
  data: rows, summary: { negative: 1, out: 0, low: 1, ok: 0, unused: 0, at_risk_products: 1, stock_value: '15000.00', suggestion_cost: '0.00' },
  params: { window_days: 7, risk_days: 3, target_days: 7 }, computed_at: '2026-11-01T02:00:00+07:00',
});

function renderTab({ multi = true, selected = null, query = {}, menus = ['reports', 'purchase_orders'] } = {}) {
  globalThis.__routeQuery = query;
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 7, role: 'Owner', name: 'Owner', menu_keys: menus };
  Object.assign(useOutletStore(), { outlets: [{ id: 14, code: 'WDM', name: 'Warkop WJM Demo' }, { id: 4, code: 'WJ3', name: 'Warkop WJM 3' }], multi, selected });
  return render(MaterialsTab, { global: { plugins: [pinia] } });
}

// 088 US1 (T010) — tab Laporan → Bahan: ringkasan, tabel risiko, saringan, parameter, outlet navbar.
describe('MaterialsTab — position & risk', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    getMaterialPosition.mockResolvedValue(RES());
  });

  it('shows the summary and risky rows first with the threatened option and menu', async () => {
    renderTab();
    expect(await screen.findByText('Indomie Ayam Bawang')).toBeInTheDocument();
    expect(screen.getByTestId('mat-summary-negative')).toHaveTextContent('1');
    expect(screen.getByTestId('mat-summary-low')).toHaveTextContent('1');
    const rows = screen.getAllByTestId('mat-row');
    expect(within(rows[0]).getByText('Indomie Ayam Bawang')).toBeInTheDocument();
    expect(rows[0]).toHaveTextContent('AYAM BAWANG');
    expect(rows[0]).toHaveTextContent('Indomie Kuah');
    expect(within(rows[0]).getByTestId('mat-on-hand')).toHaveClass('text-danger-text');
    expect(rows[1]).toHaveTextContent('1,5');
  });

  it('sends the saved preferences and the status from the link', async () => {
    localStorage.setItem('materialReport.prefs.7', JSON.stringify({ window_days: 14, risk_days: 2, target_days: 10 }));
    renderTab({ query: { status: 'low' } });
    await waitFor(() => expect(getMaterialPosition).toHaveBeenCalledWith(expect.objectContaining({ window_days: 14, risk_days: 2, target_days: 10, status: ['low'] })));
  });

  it('clicking a summary card filters by that status, and changing a parameter reloads and is remembered', async () => {
    const user = userEvent.setup();
    renderTab();
    await screen.findByText('Indomie Ayam Bawang');

    await user.click(screen.getByTestId('mat-summary-negative'));
    await waitFor(() => expect(getMaterialPosition).toHaveBeenLastCalledWith(expect.objectContaining({ status: ['negative'] })));

    const risk = screen.getByTestId('mat-risk-days');
    await user.clear(risk);
    await user.type(risk, '5');
    await waitFor(() => expect(getMaterialPosition).toHaveBeenLastCalledWith(expect.objectContaining({ risk_days: 5 })));
    expect(JSON.parse(localStorage.getItem('materialReport.prefs.7')).risk_days).toBe(5);
  });

  it('reloads when the navbar outlet changes and hides the outlet column for one outlet', async () => {
    renderTab();
    await screen.findByText('Indomie Ayam Bawang');
    const calls = getMaterialPosition.mock.calls.length;
    useOutletStore().selected = 14;
    await waitFor(() => expect(getMaterialPosition.mock.calls.length).toBeGreaterThan(calls));
  });

  it('sends the page outlet filter as outlet_id[] and reloads when it changes', async () => {
    const user = userEvent.setup();
    renderTab({ selected: 14 });
    await waitFor(() => expect(getMaterialPosition).toHaveBeenLastCalledWith(expect.objectContaining({ outlet_id: [14] })));
    // Filter boleh dipindah ke outlet lain tanpa mengubah navbar (pola 071): pilih WJ3 lewat kontrol filter.
    await user.click(within(screen.getByTestId('mat-outlet-filter')).getByRole('button'));
    await user.click(await screen.findByText('Warkop WJM 3'));
    await waitFor(() => expect(getMaterialPosition).toHaveBeenLastCalledWith(expect.objectContaining({ outlet_id: expect.arrayContaining([4]) })));
  });

  it('sends no outlet_id when all outlets are shown, and hides the filter for a single-outlet install', async () => {
    renderTab({ selected: null });
    await waitFor(() => expect(getMaterialPosition).toHaveBeenCalled());
    expect(getMaterialPosition.mock.calls.at(-1)[0]).not.toHaveProperty('outlet_id');
    expect(screen.getByTestId('mat-outlet-filter')).toBeInTheDocument();
  });

  it('hides the outlet filter when there is only one outlet', async () => {
    renderTab({ multi: false });
    await screen.findByText('Indomie Ayam Bawang');
    expect(screen.queryByTestId('mat-outlet-filter')).not.toBeInTheDocument();
  });

  it('shows an error with retry instead of an empty table', async () => {
    getMaterialPosition.mockRejectedValueOnce(new Error('boom'));
    const user = userEvent.setup();
    renderTab();
    await user.click(await screen.findByRole('button', { name: /coba lagi/i }));
    await screen.findByText('Indomie Ayam Bawang');
  });
});

// 088 US2 (T019) — saran pembelian → draf PO per vendor (outlet tunggal dari navbar).
describe('MaterialsTab — purchase suggestions', () => {
  const SUG = { qty: '22.000', vendor_id: 3, vendor: 'Tukang Grosir Indomie', unit_price: '3000.00', cost: '66000.00' };
  const withSuggestions = () => RES([
    ROW({ suggestion: SUG }),
    ROW({ material_id: 11, name: 'Indomie Kari', suggestion: { qty: '5.000', vendor_id: null, vendor: null, unit_price: null, cost: null } }),
  ]);

  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    getMaterialPosition.mockResolvedValue(withSuggestions());
    createMaterialPurchaseDrafts.mockResolvedValue({ created: [{ purchase_order_id: 41, po_number: 'PO-1', vendor_id: 3, vendor: 'Tukang Grosir Indomie', line_count: 1, total: '66000.00' }], refused: [] });
  });

  it('shows the suggested quantity, vendor and cost, and flags a missing price', async () => {
    renderTab({ selected: 14 });
    const rows = await screen.findAllByTestId('mat-row');
    expect(within(rows[0]).getByTestId('mat-suggestion')).toHaveTextContent('22');
    expect(rows[0]).toHaveTextContent('Tukang Grosir Indomie');
    expect(within(rows[1]).getByTestId('mat-suggestion')).toHaveTextContent('Belum ada vendor');
  });

  it('creates drafts from the ticked rows with the (editable) quantity and shows the result', async () => {
    const user = userEvent.setup();
    renderTab({ selected: 14 });
    const rows = await screen.findAllByTestId('mat-row');
    await user.click(within(rows[0]).getByTestId('mat-pick'));
    const qty = within(rows[0]).getByTestId('mat-pick-qty');
    await user.clear(qty);
    await user.type(qty, '30');
    await user.click(screen.getByTestId('mat-create-drafts'));

    await waitFor(() => expect(createMaterialPurchaseDrafts).toHaveBeenCalledWith([{ material_id: 10, qty: 30 }], expect.any(String), 14));
    expect(await screen.findByTestId('mat-drafts-result')).toHaveTextContent('PO-1');
  });

  it('lists refused rows with their reason', async () => {
    createMaterialPurchaseDrafts.mockResolvedValueOnce({ created: [{ purchase_order_id: 41, po_number: 'PO-1', vendor_id: 3, vendor: 'X', line_count: 1, total: '1.00' }], refused: [{ material_id: 11, reason: 'no_vendor' }] });
    const user = userEvent.setup();
    renderTab({ selected: 14 });
    const rows = await screen.findAllByTestId('mat-row');
    await user.click(within(rows[0]).getByTestId('mat-pick'));
    await user.click(screen.getByTestId('mat-create-drafts'));
    expect(await screen.findByTestId('mat-drafts-result')).toHaveTextContent('Indomie Kari');
  });

  it('asks for one outlet instead of sending when all outlets are shown', async () => {
    const user = userEvent.setup();
    renderTab({ selected: null });
    const rows = await screen.findAllByTestId('mat-row');
    await user.click(within(rows[0]).getByTestId('mat-pick'));
    await user.click(screen.getByTestId('mat-create-drafts'));
    expect(await screen.findByText(/pilih satu outlet/i)).toBeInTheDocument();
    expect(createMaterialPurchaseDrafts).not.toHaveBeenCalled();
  });

  it('hides the draft button from users who cannot create purchase orders', async () => {
    renderTab({ selected: 14, menus: ['reports'] });
    await screen.findAllByTestId('mat-row');
    expect(screen.queryByTestId('mat-create-drafts')).toBeNull();
    expect(screen.queryByTestId('mat-pick')).toBeNull();
  });
});

// 088 US4 (T033) — sub-tab Pemakaian dan rincian menu per bahan.
describe('MaterialsTab — usage', () => {
  const USAGE = {
    data: [{ material_id: 9, code: 'INDM-ST', name: 'Indomie Soto', unit: 'pcs', outlet_id: 14, outlet_code: 'WDM', sold_net: '6.000', wasted: '1.000', adjusted_in: '4.000', adjusted_out: '1.000', purchased: '10.000', transferred_in: '0.000', transferred_out: '2.000', transfer_cancelled: '0.000', sold_net_value: '18000.00', wasted_value: '3000.00', adjusted_in_value: '12000.00', adjusted_out_value: '3000.00', purchased_value: '30000.00' }],
    totals: { sold_net_value: '18000.00', wasted_value: '3000.00', adjusted_in_value: '12000.00', adjusted_out_value: '3000.00', purchased_value: '30000.00' },
    trend: [{ date: '2026-10-30', sold_net_value: '15000.00', wasted_value: '0.00' }, { date: '2026-10-31', sold_net_value: '3000.00', wasted_value: '3000.00' }],
    top_used: [{ material_id: 9, name: 'Indomie Soto', value: '18000.00' }],
    top_wasted: [{ material_id: 9, name: 'Indomie Soto', value: '3000.00' }],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    getMaterialPosition.mockResolvedValue(RES());
    getMaterialUsage.mockResolvedValue(USAGE);
    getMaterialUsageByMenu.mockResolvedValue({ data: [{ product_id: 789, product: 'Indomie Kuah', options: ['SOTO'], qty: '6.000' }] });
  });

  it('shows period totals, the daily trend, top lists and per-material figures', async () => {
    const user = userEvent.setup();
    renderTab({ selected: 14 });
    await user.click(await screen.findByTestId('mat-subtab-usage'));

    expect(await screen.findByTestId('mat-usage-total-sold')).toHaveTextContent('18.000');
    expect(screen.getByTestId('mat-usage-total-wasted')).toHaveTextContent('3.000');
    expect(screen.getAllByTestId('mat-trend-day')).toHaveLength(2);
    expect(screen.getByTestId('mat-top-used')).toHaveTextContent('Indomie Soto');
    const row = screen.getByTestId('mat-usage-row');
    expect(row).toHaveTextContent('6');
    expect(row).toHaveTextContent('10');
  });

  it('sends the chosen period', async () => {
    const user = userEvent.setup();
    renderTab({ selected: 14 });
    await user.click(await screen.findByTestId('mat-subtab-usage'));
    await screen.findByTestId('mat-usage-total-sold');
    const from = screen.getByTestId('mat-usage-from');
    await user.clear(from);
    await user.type(from, '2026-10-01');
    await waitFor(() => expect(getMaterialUsage).toHaveBeenLastCalledWith(expect.objectContaining({ date_from: '2026-10-01' })));
  });

  it('opens the menus that used a material', async () => {
    const user = userEvent.setup();
    renderTab({ selected: 14 });
    await user.click(await screen.findByTestId('mat-subtab-usage'));
    await user.click(await screen.findByRole('button', { name: 'Indomie Soto' }));
    expect(await screen.findByText('Indomie Kuah')).toBeInTheDocument();
    expect(getMaterialUsageByMenu).toHaveBeenCalledWith(9, expect.objectContaining({ date_from: expect.any(String), date_to: expect.any(String) }));
  });
});

