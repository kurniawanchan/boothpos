import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const RESPONSE = { event: null, totals: {}, sessions: [], transactions: [] };

// 054 (US5) — "Semua event" harus bisa DIPILIH (dulu hanya teks placeholder yang tak bisa diklik).
describe('SalesView — All events (054 US5)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }, { id: 2, name: 'Event B', status: 'closed' }] });
    salesReport.mockResolvedValue(RESPONSE);
  });

  async function open() {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    render(SalesView, { global: { plugins: [pinia, router] } });
    await waitFor(() => expect(salesReport).toHaveBeenCalled());
    return user;
  }

  it('starts on the active event, then lets the user pick All events and reloads without an event filter', async () => {
    const user = await open();
    expect(salesReport.mock.calls.at(-1)[0]).toMatchObject({ event_id: 1 });

    await user.click(screen.getByText('Event A'));
    await user.click(await screen.findByRole('option', { name: 'Semua event' }));

    await waitFor(() => expect(salesReport.mock.calls.at(-1)[0].event_id).toBeUndefined());
  });

  it('can go back to a single event after choosing All events', async () => {
    const user = await open();
    await user.click(screen.getByText('Event A'));
    await user.click(await screen.findByRole('option', { name: 'Semua event' }));
    await waitFor(() => expect(salesReport.mock.calls.at(-1)[0].event_id).toBeUndefined());

    await user.click(screen.getAllByText('Semua event')[0]);
    await user.click(await screen.findByRole('option', { name: 'Event B' }));

    await waitFor(() => expect(salesReport.mock.calls.at(-1)[0]).toMatchObject({ event_id: 2 }));
  });
});
