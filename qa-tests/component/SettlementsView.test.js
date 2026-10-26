import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';

vi.mock('../../resources/js/api/settlements', () => ({
  listSettlements: vi.fn(),
  previewSettlement: vi.fn(),
  createSettlement: vi.fn(),
}));
vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));

import * as api from '../../resources/js/api/settlements';
import { listEvents } from '../../resources/js/api/events';
import { listArtists } from '../../resources/js/api/artists';
import SettlementsView from '../../resources/js/views/SettlementsView.vue';

const ROW = {
  id: 12, number: 'STL-20261026-0001', status: 'finalized', display_status: 'partially_paid',
  event: { id: 3, name: 'Event Uji' }, seller: { id: 7, name: 'Seller Uji', code: 'ABC' },
  revenue_total: '45000.00', net_amount: '40000.00', paid_amount: '10000.00', balance: '30000.00',
};

async function renderList(rows = [ROW]) {
  api.listSettlements.mockResolvedValue({
    data: rows,
    meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 },
    totals: { revenue_total: '45000.00', net_amount: '40000.00', paid_amount: '10000.00', balance: '30000.00' },
  });
  listEvents.mockResolvedValue({ data: [{ id: 3, name: 'Event Uji' }] });
  listArtists.mockResolvedValue({ data: [{ id: 7, name: 'Seller Uji' }] });
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/settlements', name: 'settlements', component: SettlementsView },
      { path: '/settlements/:id', name: 'settlement-detail', component: { template: '<div>detail</div>' } },
    ],
  });
  router.push('/settlements');
  await router.isReady();
  return { router, ...render(SettlementsView, { global: { plugins: [pinia, router] } }) };
}

describe('SettlementsView', () => {
  beforeEach(() => vi.clearAllMocks());

  it('lists settlements with status, money and the server-computed totals', async () => {
    await renderList();

    expect(await screen.findByText('STL-20261026-0001')).toBeInTheDocument();
    expect(screen.getByText('Seller Uji')).toBeInTheDocument();
    expect(screen.getByText(/dibayar sebagian/i)).toBeInTheDocument();
    const totals = screen.getByTestId('settlement-totals');
    expect(totals).toHaveTextContent('Rp 45.000');
    expect(totals).toHaveTextContent('Rp 30.000');
  });

  it('shows the empty state when there are no settlements', async () => {
    await renderList([]);

    expect(await screen.findByText(/belum ada settlement/i)).toBeInTheDocument();
  });

  it('opens the detail page when the number is clicked', async () => {
    const { router } = await renderList();

    await fireEvent.click(await screen.findByRole('button', { name: 'STL-20261026-0001' }));

    await waitFor(() => expect(router.currentRoute.value.name).toBe('settlement-detail'));
    expect(router.currentRoute.value.params.id).toBe('12');
  });

  it('a failed list load is an error with Retry, not an empty list', async () => {
    api.listSettlements.mockRejectedValueOnce(new Error('boom'));
    listEvents.mockResolvedValue({ data: [] });
    listArtists.mockResolvedValue({ data: [] });
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/settlements', name: 'settlements', component: SettlementsView }, { path: '/settlements/:id', name: 'settlement-detail', component: { template: '<div />' } }],
    });
    router.push('/settlements');
    await router.isReady();
    render(SettlementsView, { global: { plugins: [pinia, router] } });

    expect(await screen.findByRole('alert')).toHaveTextContent(/gagal memuat/i);
  });
});
