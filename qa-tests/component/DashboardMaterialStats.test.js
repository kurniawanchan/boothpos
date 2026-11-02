import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import DashboardMaterialStats from '../../resources/js/components/dashboard/DashboardMaterialStats.vue';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { getDashboardMaterials } from '../../resources/js/api/dashboard';

const push = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }));
vi.mock('../../resources/js/api/dashboard', () => ({ getDashboardMaterials: vi.fn() }));

const STATS = { counts: { negative: 2, out: 1, low: 3, at_risk_products: 4 }, low_stock_products: 5, stock_value: '250000.00', wasted_value: '3000.00', params: { window_days: 7, risk_days: 3 }, computed_at: '2026-11-01T02:00:00+07:00' };

function renderCard(props = { period: 'today' }) {
  const pinia = createPinia();
  setActivePinia(pinia);
  Object.assign(useOutletStore(), { multi: true, selected: 14 });
  return render(DashboardMaterialStats, { props, global: { plugins: [pinia] } });
}

// 088 US3 (T026) — kartu statistik bahan & stok di Dashboard.
describe('DashboardMaterialStats', () => {
  beforeEach(() => { vi.clearAllMocks(); getDashboardMaterials.mockResolvedValue(STATS); });

  it('shows the counts and money when present', async () => {
    renderCard();
    expect(await screen.findByTestId('dms-negative')).toHaveTextContent('2');
    expect(screen.getByTestId('dms-low')).toHaveTextContent('3');
    expect(screen.getByTestId('dms-products')).toHaveTextContent('4');
    expect(screen.getByTestId('dms-low-stock-products')).toHaveTextContent('5');
    expect(screen.getByTestId('dms-stock-value')).toHaveTextContent('250.000');
    expect(screen.getByTestId('dms-wasted-value')).toHaveTextContent('3.000');
  });

  it('hides money blocks when the server leaves them out', async () => {
    const { stock_value, wasted_value, ...noMoney } = STATS;
    getDashboardMaterials.mockResolvedValue(noMoney);
    renderCard();
    await screen.findByTestId('dms-negative');
    expect(screen.queryByTestId('dms-stock-value')).toBeNull();
    expect(screen.queryByTestId('dms-wasted-value')).toBeNull();
  });

  it('opens the Materials report filtered by the clicked status', async () => {
    const user = userEvent.setup();
    renderCard();
    await user.click(await screen.findByTestId('dms-low'));
    expect(push).toHaveBeenCalledWith({ name: 'reports', query: { tab: 'materials', status: 'low' } });
  });

  it('reloads on period and navbar outlet change', async () => {
    const { rerender } = renderCard();
    await screen.findByTestId('dms-negative');
    await rerender({ period: '7d' });
    await waitFor(() => expect(getDashboardMaterials).toHaveBeenLastCalledWith({ period: '7d' }));
    const n = getDashboardMaterials.mock.calls.length;
    useOutletStore().selected = 4;
    await waitFor(() => expect(getDashboardMaterials.mock.calls.length).toBeGreaterThan(n));
  });

  it('a failure shows a retry and never breaks the dashboard', async () => {
    getDashboardMaterials.mockRejectedValueOnce(new Error('x'));
    const user = userEvent.setup();
    renderCard();
    await user.click(await screen.findByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByTestId('dms-negative')).toHaveTextContent('2');
  });
});
