import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createI18n } from 'vue-i18n';
import { createRouter, createMemoryHistory } from 'vue-router';
import PreordersView from '../../resources/js/views/PreordersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listPreorders, getPreorder, getPreorderSummary } from '../../resources/js/api/preorders';
import { listArtists } from '../../resources/js/api/artists';
import id from '../../resources/js/locales/id.json';
import en from '../../resources/js/locales/en.json';

/**
 * 013-preorder-list-filters-receipt (US1, T012) — seller filter on
 * PreordersView.vue (BaseSelect fed by listArtists) plus the new "Penjual"
 * column, which must render every seller on a row (not just the first) and
 * degrade gracefully (em-dash) when a row has no sellers at all.
 */

vi.mock('../../resources/js/api/preorders', () => ({
  listPreorders: vi.fn(),
  getPreorder: vi.fn(),
  createPreorder: vi.fn(),
  updatePreorderStatus: vi.fn(),
  exportPreorders: vi.fn(),
  downloadPreorderImportTemplate: vi.fn(),
  importPreorders: vi.fn(),
  resendPreorderNotification: vi.fn(),
  getPreorderSummary: vi.fn(),
}));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/shipments', () => ({ createShipment: vi.fn(), updateShipment: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ lookupVariants: vi.fn() }));

const ARTISTS = [
  { id: 1, name: 'Artist A' },
  { id: 2, name: 'Artist B' },
];

const ROWS = [
  {
    id: 10,
    preorder_number: 'PO-0010',
    customer_name: 'Siti Aminah',
    status: 'ordered',
    fulfillment: 'pickup',
    total_amount: '100000.00',
    outstanding: '100000.00',
    sellers: [{ id: 1, name: 'Artist A' }, { id: 2, name: 'Artist B' }],
  },
  {
    id: 11,
    preorder_number: 'PO-0011',
    customer_name: 'Budi Santoso',
    status: 'ordered',
    fulfillment: 'pickup',
    total_amount: '50000.00',
    outstanding: '50000.00',
    sellers: [],
  },
];

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/preorders', name: 'preorders', component: { template: '<div />' } }],
  });
}

async function renderPreorders() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'preorders'] };
  const i18n = createI18n({ legacy: false, locale: 'id', messages: { id, en } });
  const router = makeRouter();
  router.push('/preorders');
  await router.isReady();
  return render(PreordersView, { global: { plugins: [pinia, i18n, router] } });
}

describe('PreordersView — seller filter and column (013 US1)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listPreorders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
  });

  it('renders all seller names for a row with multiple sellers, joined by comma', async () => {
    await renderPreorders();
    expect(await screen.findByText('Artist A, Artist B')).toBeInTheDocument();
  });

  it('renders an em-dash placeholder for a row with no sellers, not a blank or "undefined" cell', async () => {
    await renderPreorders();
    await screen.findByText('PO-0011');
    const row = screen.getByText('PO-0011').closest('tr');
    expect(row).toHaveTextContent('—');
    expect(row).not.toHaveTextContent('undefined');
  });

  it('calls listPreorders with the selected seller id when a seller is chosen from the filter', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0010');

    await waitFor(() => expect(listArtists).toHaveBeenCalled());

    // The seller BaseSelect has no explicit label prop, so locate it by its
    // placeholder text "Semua penjual" instead.
    const trigger = screen.getByText('Semua penjual');
    await user.click(trigger);
    await user.click(await screen.findByRole('option', { name: 'Artist A' }));

    await waitFor(() =>
      expect(listPreorders).toHaveBeenCalledWith(expect.objectContaining({ artist_id: 1 })),
    );
  });

  it('opens the same detail view when clicking the preorder-number button as clicking "Detail" (013 US2, T020)', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({
      id: 10,
      preorder_number: 'PO-0010',
      status: 'ordered',
      fulfillment: 'pickup',
      items: [],
    });

    await renderPreorders();
    await screen.findByText('PO-0010');

    const numberButton = screen.getByRole('button', { name: 'PO-0010' });
    await user.click(numberButton);

    await waitFor(() => expect(getPreorder).toHaveBeenCalledWith(10));
    // Detail drawer title reflects the opened preorder's number, same
    // outcome as clicking the row's "Detail" action button would produce.
    await waitFor(() => expect(screen.getAllByText('PO-0010').length).toBeGreaterThan(1));
    expect(screen.getByText('Status pre-order')).toBeInTheDocument();
  });
});

/**
 * 013-preorder-list-filters-receipt (US5, T028) — summary panel rendering
 * and refetch-on-filter-change for getPreorderSummary().
 */
describe('PreordersView — summary panel (013 US5)', () => {
  const SUMMARY = {
    transaction_count: 5,
    by_status: [
      { status: 'ordered', count: 2, total_amount: '100000.00' },
      { status: 'dp_paid', count: 3, total_amount: '400000.00' },
    ],
    grand_total: '500000.00',
    total_outstanding: '200000.00',
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listPreorders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
    getPreorderSummary.mockResolvedValue(SUMMARY);
  });

  it('renders transaction count and formatted grand total/outstanding from getPreorderSummary', async () => {
    await renderPreorders();
    await screen.findByText('PO-0010');

    await waitFor(() => expect(getPreorderSummary).toHaveBeenCalled());
    expect(await screen.findByText('5')).toBeInTheDocument();
    expect(await screen.findByText('Rp 500.000')).toBeInTheDocument();
    expect(await screen.findByText('Rp 200.000')).toBeInTheDocument();
  });

  it('refetches getPreorderSummary with updated filter params when a filter changes', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0010');

    await waitFor(() => expect(getPreorderSummary).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(listArtists).toHaveBeenCalled());

    const trigger = screen.getByText('Semua penjual');
    await user.click(trigger);
    await user.click(await screen.findByRole('option', { name: 'Artist A' }));

    await waitFor(() =>
      expect(getPreorderSummary).toHaveBeenCalledWith(expect.objectContaining({ artist_id: 1 })),
    );
    expect(getPreorderSummary.mock.calls.length).toBeGreaterThan(1);
  });
});
