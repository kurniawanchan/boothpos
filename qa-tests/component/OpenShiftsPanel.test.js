import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createRouter, createMemoryHistory } from 'vue-router';
import OpenShiftsPanel from '../../resources/js/components/dashboard/OpenShiftsPanel.vue';
import { shifts } from '../../resources/js/api/dashboard';

vi.mock('../../resources/js/api/dashboard', () => ({ shifts: vi.fn() }));

const ROW = (over) => ({ id: 1, source: 'app', terminal_name: 'Kasir Depan', cashier: { id: 1, name: 'Dewi' }, outlet: { id: 1, name: 'Warkop WJM 3' }, opened_at: '2026-10-22T06:39:00+07:00', sales_total: '37400.00', order_count: 3, ...over });

async function renderPanel() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/session', name: 'session', component: { template: '<div />' } }] });
  router.push('/session');
  await router.isReady();
  return render(OpenShiftsPanel, { global: { plugins: [router] } });
}

describe('OpenShiftsPanel (065)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows one row per open shift (different users) with source label and sales, linking to the shift', async () => {
    shifts.mockResolvedValue({ total_open: 2, data: [ROW({ id: 11 }), ROW({ id: 12, source: 'web', terminal_name: null, cashier: { id: 2, name: 'Budi' }, sales_total: '0.00', order_count: 0 })] });
    await renderPanel();

    const rows = await screen.findAllByTestId('open-shift-row');
    expect(rows).toHaveLength(2);
    expect(within(rows[0]).getByText('Dewi').closest('a').getAttribute('href')).toContain('/session?id=11');
    expect(within(rows[0]).getByText('Kasir Depan')).toBeInTheDocument();
    expect(within(rows[1]).getByText('Budi')).toBeInTheDocument();
    expect(within(rows[1]).getByText('Web')).toBeInTheDocument();
    expect(screen.getByTestId('open-shifts-count').textContent).toMatch(/2/);
  });

  it('shows an empty message when nothing is open', async () => {
    shifts.mockResolvedValue({ total_open: 0, data: [] });
    await renderPanel();

    expect(await screen.findByTestId('open-shifts-empty')).toBeInTheDocument();
  });

  it('shows an error state with retry, and recovers', async () => {
    shifts.mockRejectedValueOnce(new Error('boom')).mockResolvedValue({ total_open: 1, data: [ROW()] });
    await renderPanel();

    const retry = await screen.findByRole('button', { name: /coba lagi|retry/i });
    retry.click();
    await waitFor(() => expect(screen.getAllByTestId('open-shift-row')).toHaveLength(1));
  });
});
