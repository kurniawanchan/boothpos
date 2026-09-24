import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createI18n } from 'vue-i18n';
import { createRouter, createMemoryHistory } from 'vue-router';
import PreordersView from '../../resources/js/views/PreordersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listPreorders, getPreorder, getPreorderSummary, updatePreorder, deletePreorder, bulkEmailPreorderInvoices } from '../../resources/js/api/preorders';
import { listArtists } from '../../resources/js/api/artists';
import { listCustomers } from '../../resources/js/api/customers';
import { lookupVariants } from '../../resources/js/api/products';
import { listEvents } from '../../resources/js/api/events';
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
  updatePreorder: vi.fn(),
  deletePreorder: vi.fn(),
  updatePreorderStatus: vi.fn(),
  exportPreorders: vi.fn(),
  downloadPreorderImportTemplate: vi.fn(),
  importPreorders: vi.fn(),
  resendPreorderNotification: vi.fn(),
  getPreorderSummary: vi.fn(),
  bulkPreorderInvoices: vi.fn(),
  bulkEmailPreorderInvoices: vi.fn(),
}));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/shipments', () => ({ createShipment: vi.fn(), updateShipment: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/customers', () => ({ listCustomers: vi.fn(), createCustomer: vi.fn() }));
vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));

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
    listEvents.mockResolvedValue({ data: [] });
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
    listEvents.mockResolvedValue({ data: [] });
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

/**
 * 021-preorder-form-updates (US1) — customer field collapses from a
 * button-opens-a-second-modal flow into one inline searchable dropdown,
 * plus quantity direct-entry alongside the existing +/- stepper.
 */
describe('PreordersView — inline customer dropdown & quantity direct-entry (021 US1)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } });
    listCustomers.mockResolvedValue({ data: [{ id: 5, name: 'Siti Aminah', phone: '0812' }] });
    lookupVariants.mockResolvedValue({ data: [{ variant_id: 1, sku: 'ABC123', label: 'Keychain — Standard', sell_price: '15000.00' }] });
  });

  it('shows matching customers inline with no separate modal, and selecting one sets the customer', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();

    await user.click(screen.getByRole('button', { name: 'Pre-order baru' }));
    await user.click(screen.getByText('Pilih pelanggan…'));
    await user.type(screen.getByPlaceholderText('Ketik untuk mencari…'), 'Siti');

    const match = await screen.findByText('Siti Aminah');
    // No BaseModal dialog element should exist for a second "Pick a
    // customer" pop-up — the dropdown is a Teleported panel, not a modal.
    expect(screen.queryByRole('dialog', { name: /pilih pelanggan/i })).not.toBeInTheDocument();

    await user.click(match);
    expect(await screen.findByText('Siti Aminah')).toBeInTheDocument();
  });

  it('allows typing a quantity directly for an added item, in addition to the +/- stepper', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();

    await user.click(screen.getByRole('button', { name: 'Pre-order baru' }));
    await user.type(screen.getByLabelText('Tambah item'), 'Keychain');
    await user.click(await screen.findByText('Keychain — Standard'));

    const qtyInput = screen.getByLabelText('Jumlah Keychain — Standard');
    expect(qtyInput).toHaveValue(1);

    await user.clear(qtyInput);
    await user.type(qtyInput, '5');
    await user.tab(); // triggers @change

    expect(qtyInput).toHaveValue(5);
  });
});

/**
 * 022-preorder-invoice-crud-overhaul (US1) — Edit/Delete row actions are
 * status-gated per FR-001a/FR-003: Edit hidden for handed_over/cancelled,
 * Delete shown only for "ordered".
 */
describe('PreordersView — edit/delete row actions (022 US1)', () => {
  const GATED_ROWS = [
    { id: 20, preorder_number: 'PO-0020', customer_name: 'A', status: 'ordered', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] },
    { id: 21, preorder_number: 'PO-0021', customer_name: 'B', status: 'dp_paid', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] },
    { id: 22, preorder_number: 'PO-0022', customer_name: 'C', status: 'handed_over', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: GATED_ROWS, meta: { current_page: 1, per_page: 25, total: 3, last_page: 1 } });
  });

  it('shows Delete only for the "ordered" row, and Edit for every row except handed_over/cancelled', async () => {
    await renderPreorders();
    await screen.findByText('PO-0020');

    const orderedRow = screen.getByText('PO-0020').closest('tr');
    const dpPaidRow = screen.getByText('PO-0021').closest('tr');
    const handedOverRow = screen.getByText('PO-0022').closest('tr');

    expect(orderedRow).toHaveTextContent('Hapus');
    expect(orderedRow).toHaveTextContent('Edit');
    expect(dpPaidRow).not.toHaveTextContent('Hapus');
    expect(dpPaidRow).toHaveTextContent('Edit');
    expect(handedOverRow).not.toHaveTextContent('Hapus');
    expect(handedOverRow).not.toHaveTextContent('Edit');
  });

  it('opens a delete confirmation and calls deletePreorder on confirm', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deletePreorder.mockResolvedValue();
    await renderPreorders();
    await screen.findByText('PO-0020');

    const orderedRow = screen.getByText('PO-0020').closest('tr');
    await user.click(within(orderedRow).getByText('Hapus'));

    const dialog = await screen.findByRole('dialog', { name: 'Hapus pre-order' });
    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));

    await waitFor(() => expect(deletePreorder).toHaveBeenCalledWith(20));
  });

  it('loads the full preorder and opens the edit form pre-filled', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({
      id: 20, preorder_number: 'PO-0020', status: 'ordered', fulfillment: 'pickup',
      customer_id: 5, customer: { id: 5, name: 'A' }, discount: '0.00', shipping_cost: '0.00',
      items: [{ variant_id: 1, sku_snapshot: 'ABC123', name_snapshot: 'Keychain', sell_price: '15000.00', qty: 2 }],
    });
    await renderPreorders();
    await screen.findByText('PO-0020');

    const orderedRow = screen.getByText('PO-0020').closest('tr');
    await user.click(within(orderedRow).getByText('Edit'));

    await waitFor(() => expect(getPreorder).toHaveBeenCalledWith(20));
    expect(await screen.findByText('Ubah pre-order')).toBeInTheDocument();
    expect(screen.getByDisplayValue(2)).toBeInTheDocument();
  });
});

/**
 * 022-preorder-invoice-crud-overhaul (US6, FR-016) — customer picker shows
 * a scrollable default list on open, before any search text is typed.
 */
describe('PreordersView — customer picker default list (022 US6)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } });
    listCustomers.mockResolvedValue({ data: [{ id: 5, name: 'Siti Aminah', phone: '0812' }] });
  });

  it('fetches and shows customers as soon as the dropdown opens, with no search text typed', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();

    await user.click(screen.getByRole('button', { name: 'Pre-order baru' }));
    await user.click(screen.getByText('Pilih pelanggan…'));

    await waitFor(() => expect(listCustomers).toHaveBeenCalledWith(expect.objectContaining({ search: '' })));
    expect(await screen.findByText('Siti Aminah')).toBeInTheDocument();
  });
});

/**
 * 022-preorder-invoice-crud-overhaul (US5, FR-013/FR-014/FR-015) — bulk
 * toolbar only appears once a row is selected, and bulk email reports a
 * sent/skipped summary.
 */
describe('PreordersView — bulk select and email (022 US5)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
  });

  it('shows the bulk-actions toolbar only after selecting a row, and reports the send/skip summary', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    bulkEmailPreorderInvoices.mockResolvedValue({ data: [{ preorder_id: 10, status: 'sent' }] });
    await renderPreorders();
    await screen.findByText('PO-0010');

    expect(screen.queryByRole('button', { name: /Email invoices|Kirim email/ })).not.toBeInTheDocument();

    const row = screen.getByText('PO-0010').closest('tr');
    await user.click(within(row).getByRole('checkbox'));

    const emailButton = await screen.findByRole('button', { name: 'Kirim email' });
    await user.click(emailButton);

    await waitFor(() => expect(bulkEmailPreorderInvoices).toHaveBeenCalledWith([10], 'invoice'));
    const { useToastStore } = await import('../../resources/js/stores/toast');
    await waitFor(() => expect(useToastStore().items.some((i) => i.message === '1 email terkirim, 0 dilewati.')).toBe(true));
  });
});
