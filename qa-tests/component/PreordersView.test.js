import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createI18n } from 'vue-i18n';
import { createRouter, createMemoryHistory } from 'vue-router';
import PreordersView from '../../resources/js/views/PreordersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listPreorders, getPreorder, getPreorderSummary, updatePreorder, deletePreorder, bulkEmailPreorderInvoices, updatePreorderDispatchStatus, getPreorderInvoice } from '../../resources/js/api/preorders';
import { listArtists } from '../../resources/js/api/artists';
import { listCustomers, getCustomer } from '../../resources/js/api/customers';
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
  updatePreorderDispatchStatus: vi.fn(),
  getPreorderInvoice: vi.fn(),
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
vi.mock('../../resources/js/api/customers', () => ({ listCustomers: vi.fn(), createCustomer: vi.fn(), getCustomer: vi.fn() }));
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

    // 024-invoice-layout-shipping-slip follow-up — this filter is now a
    // BaseMultiSelect (combinable, multi-value, see PreordersView.vue's
    // applyPreorderFilters()), so a single pick sends a one-element array,
    // not a bare scalar.
    await waitFor(() =>
      expect(listPreorders).toHaveBeenCalledWith(expect.objectContaining({ artist_id: [1] })),
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
      expect(getPreorderSummary).toHaveBeenCalledWith(expect.objectContaining({ artist_id: [1] })),
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
 * Delete shown for "ordered" AND "cancelled" (024-invoice-layout-
 * shipping-slip lanjutan — a cancelled preorder can now be deleted too,
 * same payment-existence guard still enforced server-side).
 */
describe('PreordersView — edit/delete row actions (022 US1)', () => {
  const GATED_ROWS = [
    { id: 20, preorder_number: 'PO-0020', customer_name: 'A', status: 'ordered', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] },
    { id: 21, preorder_number: 'PO-0021', customer_name: 'B', status: 'dp_paid', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] },
    { id: 22, preorder_number: 'PO-0022', customer_name: 'C', status: 'handed_over', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] },
    { id: 23, preorder_number: 'PO-0023', customer_name: 'D', status: 'cancelled', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: GATED_ROWS, meta: { current_page: 1, per_page: 25, total: 4, last_page: 1 } });
  });

  // Aksi baris yang lebih dari 3 masuk dropdown "Lainnya" (di-Teleport ke body).
  async function openMore(user, rowEl) {
    await user.click(within(rowEl).getByRole('button', { name: /Lainnya/ }));
    return screen.findByRole('menu');
  }

  it('shows Delete for "ordered" and "cancelled" rows, and Edit for every row except handed_over/cancelled', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0020');

    const rowOf = (n) => screen.getByText(n).closest('tr');

    let menu = await openMore(user, rowOf('PO-0020')); // ordered
    expect(within(menu).getByRole('menuitem', { name: 'Hapus' })).toBeInTheDocument();
    expect(within(menu).getByRole('menuitem', { name: 'Edit' })).toBeInTheDocument();
    await user.keyboard('{Escape}');

    menu = await openMore(user, rowOf('PO-0021')); // dp_paid
    expect(within(menu).queryByRole('menuitem', { name: 'Hapus' })).not.toBeInTheDocument();
    expect(within(menu).getByRole('menuitem', { name: 'Edit' })).toBeInTheDocument();
    await user.keyboard('{Escape}');

    // handed_over: hanya 3 aksi (Invoice, Invoice pembayaran, Detail) → tampil
    // inline, tanpa dropdown, tanpa Edit/Hapus sama sekali.
    const handedOverRow = rowOf('PO-0022');
    expect(within(handedOverRow).queryByRole('button', { name: /Lainnya/ })).not.toBeInTheDocument();
    expect(handedOverRow).not.toHaveTextContent('Hapus');
    expect(handedOverRow).not.toHaveTextContent('Edit');

    menu = await openMore(user, rowOf('PO-0023')); // cancelled
    expect(within(menu).getByRole('menuitem', { name: 'Hapus' })).toBeInTheDocument();
    expect(within(menu).queryByRole('menuitem', { name: 'Edit' })).not.toBeInTheDocument();
  });

  it('keeps Detail visible in the row and only moves the other actions into the menu', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0020');

    const row = screen.getByText('PO-0020').closest('tr');
    expect(within(row).getByRole('button', { name: 'Detail' })).toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
    const menu = await openMore(user, row);
    expect(within(menu).queryByRole('menuitem', { name: 'Detail' })).not.toBeInTheDocument();
    expect(within(menu).getAllByRole('menuitem')).toHaveLength(4); // Invoice, Invoice pembayaran, Edit, Hapus
  });

  it('opens a delete confirmation and calls deletePreorder on confirm', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deletePreorder.mockResolvedValue();
    await renderPreorders();
    await screen.findByText('PO-0020');

    const orderedRow = screen.getByText('PO-0020').closest('tr');
    await user.click(within(orderedRow).getByRole('button', { name: /Lainnya/ }));
    await user.click(await screen.findByRole('menuitem', { name: 'Hapus' }));

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
    await user.click(within(orderedRow).getByRole('button', { name: /Lainnya/ }));
    await user.click(await screen.findByRole('menuitem', { name: 'Edit' }));

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

// Requested follow-up: flag a Mail Order pre-order in the list that's
// missing shipping cost or a customer address, and let clicking the
// customer's name open their full contact info.
describe('PreordersView — missing-shipping-info flag and customer info (post-024 follow-up)', () => {
  const FLAGGED_ROWS = [
    {
      id: 20,
      preorder_number: 'PO-0020',
      customer_id: 7,
      customer_name: 'Wati',
      status: 'ordered',
      fulfillment: 'courier',
      total_amount: '100000.00',
      outstanding: '100000.00',
      shipping_cost: '0.00',
      customer_has_address: false,
      notes: 'Alamat: Jl. Mawar No. 3 (dititip via WA)',
      sellers: [],
    },
    {
      id: 21,
      preorder_number: 'PO-0021',
      customer_id: 8,
      customer_name: 'Joko',
      status: 'ordered',
      fulfillment: 'courier',
      total_amount: '150000.00',
      outstanding: '150000.00',
      shipping_cost: '15000.00',
      customer_has_address: true,
      sellers: [],
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: FLAGGED_ROWS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
  });

  it('flags a Mail Order row missing shipping cost/address, but not one with both filled in', async () => {
    await renderPreorders();
    await screen.findByText('PO-0020');

    const flaggedRow = screen.getByText('PO-0020').closest('tr');
    expect(flaggedRow).toHaveClass('bg-warn-bg');
    expect(flaggedRow.querySelector('.ph-flag')).toBeInTheDocument();

    const okRow = screen.getByText('PO-0021').closest('tr');
    expect(okRow).not.toHaveClass('bg-warn-bg');
    expect(okRow.querySelector('.ph-flag')).not.toBeInTheDocument();
  });

  it('shows shipping cost and notes when the flag is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0020');

    const flaggedRow = screen.getByText('PO-0020').closest('tr');
    await user.click(within(flaggedRow).getByRole('button', { name: /Mail Order/i }));

    await waitFor(() => expect(screen.getByText('Alamat: Jl. Mawar No. 3 (dititip via WA)')).toBeInTheDocument());
    // Rp 0 appears for the flagged row's shipping cost inside the popup.
    expect(screen.getAllByText('Rp 0').length).toBeGreaterThan(0);
  });

  it('opens the customer info modal with full contact details when the customer name is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getCustomer.mockResolvedValue({
      id: 7, name: 'Wati', phone: '0812', email: 'wati@example.test', social_handle: '@wati', address: null, notes: null,
    });

    await renderPreorders();
    await screen.findByText('PO-0020');

    await user.click(screen.getByRole('button', { name: 'Wati' }));

    await waitFor(() => expect(getCustomer).toHaveBeenCalledWith(7));
    await waitFor(() => expect(screen.getByText('wati@example.test')).toBeInTheDocument());
  });
});

/**
 * Penanda manual invoice-terkirim / pengiriman-berjalan + dropdown "Cetak"
 * di panel detail, serta filter dispatch_status di list.
 */
describe('PreordersView — dispatch status & print menu in detail', () => {
  const DETAIL = {
    id: 10,
    preorder_number: 'PO-0010',
    status: 'ordered',
    dispatch_status: 'pending',
    fulfillment: 'pickup',
    items: [],
    payments: [],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
    getPreorder.mockResolvedValue(DETAIL);
  });

  async function openDetail(user) {
    await renderPreorders();
    await screen.findByText('PO-0010');
    await user.click(screen.getByRole('button', { name: 'PO-0010' }));
    await screen.findByText('Progres invoice & pengiriman');
  }

  it('marks the invoice as sent from the detail panel and refreshes the list', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updatePreorderDispatchStatus.mockResolvedValue({ ...DETAIL, dispatch_status: 'invoice_sent' });
    await openDetail(user);
    listPreorders.mockClear();

    const sent = screen.getByRole('button', { name: 'Invoice terkirim', pressed: false });
    await user.click(sent);

    await waitFor(() => expect(updatePreorderDispatchStatus).toHaveBeenCalledWith(10, 'invoice_sent'));
    await waitFor(() => expect(listPreorders).toHaveBeenCalled());
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Invoice terkirim', pressed: true })).toBeInTheDocument(),
    );
  });

  it('does not call the API when the already-selected option is clicked again', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await openDetail(user);

    await user.click(screen.getByRole('button', { name: 'Belum dikirim', pressed: true }));

    expect(updatePreorderDispatchStatus).not.toHaveBeenCalled();
  });

  it('disables the manual status buttons for a cancelled preorder', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({ ...DETAIL, fulfillment: 'courier', status: 'cancelled' });
    await openDetail(user);

    expect(screen.getByRole('button', { name: 'Pengiriman berjalan' })).toBeDisabled();
  });

  it('offers both documents in the Print dropdown and disables the payment invoice without payments', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await openDetail(user);

    await user.click(screen.getByRole('button', { name: /Cetak/ }));

    expect(await screen.findByRole('menuitem', { name: 'Invoice pre-order' })).toBeEnabled();
    expect(screen.getByRole('menuitem', { name: 'Invoice pembayaran' })).toBeDisabled();
  });

  it('enables the payment invoice once a payment exists', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({
      ...DETAIL,
      payments: [{ id: 5, method: 'cash', purpose: 'down_payment', amount: '50000.00', paid_at: '2026-09-27T10:00:00Z' }],
    });
    await openDetail(user);

    await user.click(screen.getByRole('button', { name: /Cetak/ }));

    expect(await screen.findByRole('menuitem', { name: 'Invoice pembayaran' })).toBeEnabled();
  });

  it('offers "Pengiriman berjalan" only for Mail Order preorders', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();

    await openDetail(user); // fulfillment: pickup
    expect(screen.getByRole('button', { name: 'Invoice terkirim' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Pengiriman berjalan' })).not.toBeInTheDocument();
  });

  it('shows "Pengiriman berjalan" for a Mail Order preorder', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({ ...DETAIL, fulfillment: 'courier' });

    await openDetail(user);

    expect(screen.getByRole('button', { name: 'Pengiriman berjalan' })).toBeEnabled();
  });

  it('opens the payment invoice from a list row using that row\'s preorder id', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listPreorders.mockResolvedValue({
      data: [{ ...ROWS[0], paid_amount: '50000.00' }, { ...ROWS[1], paid_amount: '0.00' }],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
    getPreorderInvoice.mockResolvedValue({
      id: 10, preorder_number: 'PO-0010', status: 'dp_paid', document_type: 'invoice',
      items: [], payment_channels: [],
      payments: [{ id: 5, method: 'cash', purpose: 'down_payment', amount: '50000.00', paid_at: '2026-09-27T10:00:00Z' }],
    });
    await renderPreorders();
    await screen.findByText('PO-0010');

    const rows = screen.getAllByRole('row');
    const paidRow = screen.getByText('PO-0010').closest('tr');
    const unpaidRow = screen.getByText('PO-0011').closest('tr');
    expect(rows.length).toBeGreaterThan(2);

    // Baris tanpa pembayaran: itemnya dinonaktifkan.
    await user.click(within(unpaidRow).getByRole('button', { name: /Lainnya/ }));
    expect(await screen.findByRole('menuitem', { name: 'Invoice pembayaran' })).toBeDisabled();
    await user.keyboard('{Escape}');

    // Baris dengan pembayaran: aktif, dan membuka invoice untuk id BARIS itu.
    await user.click(within(paidRow).getByRole('button', { name: /Lainnya/ }));
    const item = await screen.findByRole('menuitem', { name: 'Invoice pembayaran' });
    expect(item).toBeEnabled();
    await user.click(item);

    await waitFor(() => expect(getPreorderInvoice).toHaveBeenCalledWith(10));
  });

  it('left-aligns the customer name and shows the Actions / Created / Updated headers', async () => {
    await renderPreorders();
    await screen.findByText('PO-0010');

    expect(screen.getByRole('button', { name: 'Siti Aminah' })).toHaveClass('text-left');
    for (const name of ['Aksi', 'Dibuat', 'Diperbarui']) {
      expect(screen.getByRole('columnheader', { name: new RegExp(name) })).toBeInTheDocument();
    }
  });

  it('renders created and updated timestamps in the list row', async () => {
    listPreorders.mockResolvedValue({
      data: [{ ...ROWS[0], created_at: '2026-09-27T03:00:00Z', updated_at: '2026-09-28T04:00:00Z' }],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
    await renderPreorders();
    const row = (await screen.findByText('PO-0010')).closest('tr');

    expect(row).toHaveTextContent('27 Sep 2026');
    expect(row).toHaveTextContent('28 Sep 2026');
  });

  it('shows the invoice-sent and shipping dates inside the existing status cell (no extra column)', async () => {
    listPreorders.mockResolvedValue({
      data: [{
        ...ROWS[0], fulfillment: 'courier', dispatch_status: 'shipping',
        invoice_sent_at: '2026-09-27T03:00:00Z', shipping_at: '2026-09-28T04:00:00Z',
      }],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
    await renderPreorders();
    const row = (await screen.findByText('PO-0010')).closest('tr');

    expect(row).toHaveTextContent('Invoice terkirim: 27 Sep 2026');
    expect(row).toHaveTextContent('Pengiriman mulai: 28 Sep 2026');
  });

  it('shows created, updated and dispatch dates in the detail drawer', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({
      ...DETAIL, fulfillment: 'courier', dispatch_status: 'shipping',
      created_at: '2026-09-27T03:00:00Z', updated_at: '2026-09-29T04:00:00Z',
      invoice_sent_at: '2026-09-28T03:00:00Z', shipping_at: '2026-09-29T03:00:00Z',
    });
    await openDetail(user);

    expect(screen.getByText(/Dibuat:/)).toHaveTextContent('27 Sep 2026');
    expect(screen.getByText(/Terakhir diperbarui:/)).toHaveTextContent('29 Sep 2026');
    expect(screen.getByText(/Invoice terkirim: 28 Sep 2026/)).toBeInTheDocument();
    expect(screen.getByText(/Pengiriman mulai: 29 Sep 2026/)).toBeInTheDocument();
  });

  it('filters the list by dispatch status', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0010');

    await user.click(screen.getByText('Semua invoice/pengiriman'));
    await user.click(await screen.findByRole('option', { name: 'Pengiriman berjalan' }));

    await waitFor(() =>
      expect(listPreorders).toHaveBeenCalledWith(expect.objectContaining({ dispatch_status: ['shipping'] })),
    );
  });
});
