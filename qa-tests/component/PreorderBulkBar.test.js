import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createI18n } from 'vue-i18n';
import { createRouter, createMemoryHistory } from 'vue-router';
import PreordersView from '../../resources/js/views/PreordersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listPreorders, getPreorderSummary, getPreorderNeighbors, matchingPreorderIds } from '../../resources/js/api/preorders';
import { listArtists } from '../../resources/js/api/artists';
import { listEvents } from '../../resources/js/api/events';
import id from '../../resources/js/locales/id.json';
import en from '../../resources/js/locales/en.json';

vi.mock('../../resources/js/api/preorders', () => ({
  listPreorders: vi.fn(), getPreorder: vi.fn(), getPreorderNeighbors: vi.fn(), getPreorderSummary: vi.fn(),
  matchingPreorderIds: vi.fn(), previewBulk: vi.fn(), applyBulk: vi.fn(),
  createPreorder: vi.fn(), updatePreorder: vi.fn(), deletePreorder: vi.fn(), updatePreorderStatus: vi.fn(),
  updatePreorderDispatchStatus: vi.fn(), getPreorderInvoice: vi.fn(), exportPreorders: vi.fn(),
  downloadPreorderImportTemplate: vi.fn(), importPreorders: vi.fn(), resendPreorderNotification: vi.fn(),
  bulkPreorderInvoices: vi.fn(), bulkEmailPreorderInvoices: vi.fn(), duplicatePreorders: vi.fn(), splitPreorder: vi.fn(),
  deletePreorderPayment: vi.fn(), createPreorderPayment: vi.fn(),
}));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/shipments', () => ({ createShipment: vi.fn(), updateShipment: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/customers', () => ({ listCustomers: vi.fn(), createCustomer: vi.fn(), getCustomer: vi.fn() }));
vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }), uploadPaymentProof: vi.fn(), updatePaymentConfirmation: vi.fn(),
  getPaymentProofBlobUrl: vi.fn(), verifyPayment: vi.fn(),
}));

/** 065 US1 — kotak header, "pilih semua yang cocok", Clear, dan bilah aksi yang tersembunyi (bukan dinonaktifkan) per izin. */
const row = (n) => ({ id: n, preorder_number: `PO-${n}`, customer_name: `C${n}`, status: 'ordered', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] });
const ROWS = [row(1), row(2), row(3)];

async function renderView({ actions, total = 3 } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'preorders'] };
  if (actions) auth.user.permissions = { preorders: actions };
  listPreorders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 3, total, last_page: Math.ceil(total / 3) } });
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/preorders', name: 'preorders', component: { template: '<div />' } }] });
  router.push('/preorders');
  await router.isReady();
  const i18n = createI18n({ legacy: false, locale: 'id', messages: { id, en } });
  return render(PreordersView, { global: { plugins: [pinia, i18n, router] } });
}

describe('PreordersView — bulk selection and action bar (065 US1)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: [] });
    listEvents.mockResolvedValue({ data: [] });
    getPreorderSummary.mockResolvedValue({ transaction_count: 3 });
    getPreorderNeighbors.mockResolvedValue({ in_list: false, position: null, total: 0, previous: null, next: null });
  });

  it('shows no bulk bar with nothing selected; the header checkbox selects the whole page and shows the count', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderView();
    await screen.findByText('PO-1');
    expect(screen.queryByTestId('preorder-bulk-bar')).not.toBeInTheDocument();

    await user.click(screen.getByTestId('select-page'));

    expect(await screen.findByTestId('selected-count')).toHaveTextContent('3');
    ['status', 'invoice', 'shipping'].forEach((k) => expect(screen.getByTestId(`bulk-btn-${k}`)).toBeInTheDocument());
    ['payment', 'shipment'].forEach((k) => expect(screen.queryByTestId(`bulk-btn-${k}`)).not.toBeInTheDocument());
  });

  it('shows the partial state for some rows and Clear selection removes the bar', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderView();
    await screen.findByText('PO-1');

    await user.click(within(screen.getByText('PO-1').closest('tr')).getByRole('checkbox'));
    expect(screen.getByTestId('select-page').indeterminate).toBe(true);
    expect(screen.getByTestId('selected-count')).toHaveTextContent('1');

    await user.click(screen.getByTestId('clear-selection'));
    await waitFor(() => expect(screen.queryByTestId('preorder-bulk-bar')).not.toBeInTheDocument());
  });

  it('offers "select all N matching" across pages and selects the ids the server returns', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    matchingPreorderIds.mockResolvedValue({ ids: [1, 2, 3, 4, 5, 6, 7], total: 7, capped: false });
    await renderView({ total: 7 });
    await screen.findByText('PO-1');

    expect(screen.queryByTestId('select-all-matching')).not.toBeInTheDocument();
    await user.click(screen.getByTestId('select-page'));
    await user.click(await screen.findByTestId('select-all-matching'));

    await waitFor(() => expect(screen.getByTestId('selected-count')).toHaveTextContent('7'));
    expect(matchingPreorderIds).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('select-all-banner')).toHaveTextContent('7');
  });

  it('hides (never disables) every bulk action the role may not perform', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderView({ actions: ['status'] });
    await screen.findByText('PO-1');
    await user.click(screen.getByTestId('select-page'));

    expect(await screen.findByTestId('bulk-btn-status')).toBeInTheDocument();
    ['invoice', 'shipping'].forEach((k) => expect(screen.queryByTestId(`bulk-btn-${k}`)).not.toBeInTheDocument());
  });
});
