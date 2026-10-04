import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createI18n } from 'vue-i18n';
import { createRouter, createMemoryHistory } from 'vue-router';
import PreordersView from '../../resources/js/views/PreordersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listPreorders, getPreorder, getPreorderSummary, updatePreorder, deletePreorder, bulkEmailPreorderInvoices, bulkPreorderInvoices, updatePreorderDispatchStatus, getPreorderInvoice, duplicatePreorders, splitPreorder, deletePreorderPayment, createPreorderPayment } from '../../resources/js/api/preorders';
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
  duplicatePreorders: vi.fn(),
  splitPreorder: vi.fn(),
  deletePreorderPayment: vi.fn(),
  createPreorderPayment: vi.fn(),
}));
// 029-fix-bulk-invoice-logo — unduhan massal merender tiap invoice lewat helper bersama;
// helper dan pembuat zip/PDF diganti agar yang diuji hanya alurnya.
const bulk = vi.hoisted(() => ({ captures: [], zipFiles: [] }));
vi.mock('../../resources/js/utils/pdfCapture', () => ({
  downloadElementAsPdf: vi.fn(),
  captureElementCanvas: vi.fn(async (el) => {
    bulk.captures.push({ html: el.innerHTML, attached: document.body.contains(el), el });
    return { width: 96, height: 96, toDataURL: () => 'data:image/png;base64,AAAA' };
  }),
}));
vi.mock('jszip', () => ({
  default: class {
    file(name) { bulk.zipFiles.push(name); }
    async generateAsync() { return new Blob(['zip']); }
  },
}));
vi.mock('jspdf', () => ({
  jsPDF: class {
    addImage() {}
    output() { return new Blob(['pdf']); }
  },
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

async function renderPreorders(role = 'Owner') {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role, name: role, menu_keys: ['dashboard', 'preorders'] };
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

    // handed_over: Invoice, Invoice pembayaran, Duplikat, Detail (027: Duplikat
    // tersedia untuk SEMUA status) → masuk dropdown, tetapi tanpa Edit/Hapus.
    menu = await openMore(user, rowOf('PO-0022'));
    expect(within(menu).queryByRole('menuitem', { name: 'Hapus' })).not.toBeInTheDocument();
    expect(within(menu).queryByRole('menuitem', { name: 'Edit' })).not.toBeInTheDocument();
    expect(within(menu).getByRole('menuitem', { name: 'Duplikat' })).toBeInTheDocument();
    await user.keyboard('{Escape}');

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
    expect(within(menu).getAllByRole('menuitem')).toHaveLength(6); // Invoice, Invoice pembayaran, Edit, Hapus, Duplikat, Pisah
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
      customer_address: null,
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
      customer_address: 'Jl. Melati No. 9, Bandung',
      sellers: [],
    },
    {
      id: 22,
      preorder_number: 'PO-0022',
      customer_id: 9,
      customer_name: 'Sari',
      status: 'ordered',
      fulfillment: 'courier',
      total_amount: '80000.00',
      outstanding: '80000.00',
      shipping_cost: '0.00',
      customer_has_address: true,
      customer_address: 'Jl. Kenanga No. 5, Jakarta',
      sellers: [],
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: FLAGGED_ROWS, meta: { current_page: 1, per_page: 25, total: 3, last_page: 1 } });
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

  it('shows the customer address in the flag popup, or a dash when none is on file', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0020');

    await user.click(within(screen.getByText('PO-0020').closest('tr')).getByRole('button', { name: /Mail Order/i }));
    expect(await screen.findByTestId('flag-customer-address')).toHaveTextContent('—');
    await user.keyboard('{Escape}');

    await user.click(within(screen.getByText('PO-0022').closest('tr')).getByRole('button', { name: /Mail Order/i }));
    await waitFor(() => expect(screen.getByTestId('flag-customer-address')).toHaveTextContent('Jl. Kenanga No. 5, Jakarta'));
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


// 027-preorder-duplicate-split (US1) — Duplikat satu pre-order dari baris list
// maupun panel detail. Server selalu membalas 200 dengan laporan per-baris.
describe('PreordersView — duplicate one pre-order (027 US1)', () => {
  const DUP_ROWS = [
    { id: 30, preorder_number: 'PO-0030', customer_name: 'Rina', status: 'handed_over', fulfillment: 'pickup', total_amount: '1.00', outstanding: '0.00', sellers: [] },
  ];
  const COPY = {
    id: 99, preorder_number: 'PO-0099', status: 'ordered', dispatch_status: 'pending', fulfillment: 'pickup', outstanding: '0.00',
    items: [], payments: [], customer: { name: 'Rina' },
    source: { type: 'duplicate', preorder_id: 30, preorder_number: 'PO-0030' },
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: DUP_ROWS, meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    getPreorderSummary.mockResolvedValue({ transaction_count: 1 });
    getPreorder.mockResolvedValue(COPY);
  });

  it('offers Duplikat on a handed-over row, calls the API with that id, toasts the new number and opens the copy', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    duplicatePreorders.mockResolvedValue({ data: [{ source_id: 30, source_number: 'PO-0030', status: 'created', preorder: COPY }] });
    await renderPreorders();
    await screen.findByText('PO-0030');

    const row = screen.getByText('PO-0030').closest('tr');
    await user.click(within(row).getByRole('button', { name: /Lainnya/ }));
    await user.click(await screen.findByRole('menuitem', { name: 'Duplikat' }));

    await waitFor(() => expect(duplicatePreorders).toHaveBeenCalledWith([30]));
    const { useToastStore } = await import('../../resources/js/stores/toast');
    await waitFor(() => expect(useToastStore().items.some((i) => i.message === 'Berhasil diduplikasi menjadi PO-0099')).toBe(true));
    // salinan dibuka di panel detail dan list + ringkasan dimuat ulang
    await waitFor(() => expect(getPreorder).toHaveBeenCalledWith(99));
    await waitFor(() => expect(listPreorders.mock.calls.length).toBeGreaterThan(1));
    await waitFor(() => expect(getPreorderSummary.mock.calls.length).toBeGreaterThan(1));
  });

  it('shows the server error and opens nothing when the duplicate failed', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    duplicatePreorders.mockResolvedValue({ data: [{ source_id: 30, source_number: 'PO-0030', status: 'failed', error: 'Barang "Gantungan" sudah tidak bisa dijual' }] });
    await renderPreorders();
    await screen.findByText('PO-0030');

    const row = screen.getByText('PO-0030').closest('tr');
    await user.click(within(row).getByRole('button', { name: /Lainnya/ }));
    await user.click(await screen.findByRole('menuitem', { name: 'Duplikat' }));

    const { useToastStore } = await import('../../resources/js/stores/toast');
    await waitFor(() => expect(useToastStore().items.some((i) => i.message.includes('sudah tidak bisa dijual'))).toBe(true));
    expect(getPreorder).not.toHaveBeenCalled();
  });

  it('duplicates from the detail panel and shows the "Duplikat dari" link on a copy', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({ ...COPY, id: 30, preorder_number: 'PO-0030', source: null });
    duplicatePreorders.mockResolvedValue({ data: [{ source_id: 30, source_number: 'PO-0030', status: 'created', preorder: COPY }] });
    await renderPreorders();
    await screen.findByText('PO-0030');
    await user.click(screen.getByRole('button', { name: 'PO-0030' }));

    getPreorder.mockResolvedValue(COPY);
    await user.click(await screen.findByTestId('detail-duplicate'));

    await waitFor(() => expect(duplicatePreorders).toHaveBeenCalledWith([30]));
    const source = await screen.findByTestId('detail-source');
    expect(source).toHaveTextContent('Duplikat dari');
    expect(within(source).getByRole('button', { name: 'PO-0030' })).toBeInTheDocument();
  });

  it('shows a deleted source as plain text without a link', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({ ...COPY, source: { type: 'duplicate', preorder_id: null, preorder_number: 'PO-0001' } });
    await renderPreorders();
    await screen.findByText('PO-0030');
    await user.click(screen.getByRole('button', { name: 'PO-0030' }));

    const source = await screen.findByTestId('detail-source');
    expect(source).toHaveTextContent('PO-0001');
    expect(within(source).queryByRole('button')).not.toBeInTheDocument();
  });
});

// 027-preorder-duplicate-split (US2) — Duplikat terpilih dari bulk bar.
describe('PreordersView — duplicate selected (027 US2)', () => {
  const BULK_ROWS = [
    { id: 40, preorder_number: 'PO-0040', customer_name: 'A', status: 'ordered', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] },
    { id: 41, preorder_number: 'PO-0041', customer_name: 'B', status: 'cancelled', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] },
    { id: 42, preorder_number: 'PO-0042', customer_name: 'C', status: 'ordered', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: BULK_ROWS, meta: { current_page: 1, per_page: 25, total: 3, last_page: 1 } });
    getPreorderSummary.mockResolvedValue({ transaction_count: 3 });
    getPreorder.mockResolvedValue({ id: 50, preorder_number: 'PO-0050', status: 'ordered', dispatch_status: 'pending', fulfillment: 'pickup', outstanding: '0.00', items: [], payments: [], customer: { name: 'A' } });
  });

  it('has no duplicate-selected button until a row is ticked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0040');

    expect(screen.queryByTestId('bulk-duplicate')).not.toBeInTheDocument();
    await user.click(within(screen.getByText('PO-0040').closest('tr')).getByRole('checkbox'));
    expect(await screen.findByTestId('bulk-duplicate')).toBeInTheDocument();
  });

  it('sends only the ticked rows, opens the result modal with a mixed result and clears the selection', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    duplicatePreorders.mockResolvedValue({
      data: [
        { source_id: 40, source_number: 'PO-0040', status: 'created', preorder: { id: 50, preorder_number: 'PO-0050' } },
        { source_id: 42, source_number: 'PO-0042', status: 'failed', error: 'Barang tidak tersedia' },
      ],
    });
    await renderPreorders();
    await screen.findByText('PO-0040');

    await user.click(within(screen.getByText('PO-0040').closest('tr')).getByRole('checkbox'));
    await user.click(within(screen.getByText('PO-0042').closest('tr')).getByRole('checkbox'));
    await user.click(await screen.findByTestId('bulk-duplicate'));

    await waitFor(() => expect(duplicatePreorders).toHaveBeenCalledWith([40, 42]));
    const dialog = await screen.findByRole('dialog', { name: 'Hasil duplikat' });
    expect(within(dialog).getByRole('button', { name: 'PO-0050' })).toBeInTheDocument();
    expect(within(dialog).getByTestId('duplicate-failed')).toHaveTextContent('Barang tidak tersedia');
    // seleksi dibersihkan → bulk bar hilang; list + ringkasan dimuat ulang
    await waitFor(() => expect(screen.queryByTestId('bulk-duplicate')).not.toBeInTheDocument());
    await waitFor(() => expect(listPreorders.mock.calls.length).toBeGreaterThan(1));
    await waitFor(() => expect(getPreorderSummary.mock.calls.length).toBeGreaterThan(1));

    // membuka salinan dari dialog menutup dialog dan memuat detailnya
    await user.click(within(dialog).getByRole('button', { name: 'PO-0050' }));
    await waitFor(() => expect(getPreorder).toHaveBeenCalledWith(50));
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Hasil duplikat' })).not.toBeInTheDocument());
  });
});

// 027-preorder-duplicate-split (US3) — aksi Pisah di baris list & panel detail.
describe('PreordersView — split action (027 US3)', () => {
  const SPLIT_ROWS = [
    { id: 60, preorder_number: 'PO-0060', customer_name: 'A', status: 'ordered', fulfillment: 'pickup', total_amount: '1.00', paid_amount: '0.00', has_payments: false, outstanding: '1.00', sellers: [] },
    { id: 61, preorder_number: 'PO-0061', customer_name: 'B', status: 'dp_paid', fulfillment: 'pickup', total_amount: '1.00', paid_amount: '1.00', has_payments: true, outstanding: '0.00', sellers: [] },
    { id: 62, preorder_number: 'PO-0062', customer_name: 'C', status: 'cancelled', fulfillment: 'pickup', total_amount: '1.00', paid_amount: '0.00', has_payments: false, outstanding: '1.00', sellers: [] },
    { id: 63, preorder_number: 'PO-0063', customer_name: 'D', status: 'handed_over', fulfillment: 'pickup', total_amount: '1.00', paid_amount: '0.00', has_payments: false, outstanding: '0.00', sellers: [] },
  ];
  const FULL = {
    id: 60, preorder_number: 'PO-0060', status: 'ordered', dispatch_status: 'pending', fulfillment: 'pickup',
    subtotal: '150000.00', shipping_cost: '0.00', discount: '0.00', outstanding: '150000.00', payments: [], customer: { name: 'A' },
    items: [
      { id: 1, name_snapshot: 'Poster A', sku_snapshot: 'AAA00001', sell_price: '100000.00', qty: 1 },
      { id: 2, name_snapshot: 'Stiker B', sku_snapshot: 'BBB00001', sell_price: '25000.00', qty: 2 },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: SPLIT_ROWS, meta: { current_page: 1, per_page: 25, total: 4, last_page: 1 } });
    getPreorderSummary.mockResolvedValue({ transaction_count: 4 });
    getPreorder.mockResolvedValue(FULL);
  });

  async function openMore(user, number) {
    await user.click(within(screen.getByText(number).closest('tr')).getByRole('button', { name: /Lainnya/ }));
    return screen.findByRole('menu');
  }

  it('hides Pisah for closed statuses, disables it with an explanation when paid, enables it otherwise', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0060');

    let menu = await openMore(user, 'PO-0060');
    expect(within(menu).getByRole('menuitem', { name: 'Pisah' })).toBeEnabled();
    await user.keyboard('{Escape}');

    menu = await openMore(user, 'PO-0061');
    const paid = within(menu).getByRole('menuitem', { name: 'Pisah' });
    expect(paid).toBeDisabled();
    expect(paid).toHaveAttribute('title', expect.stringContaining('pembayaran'));
    await user.keyboard('{Escape}');

    menu = await openMore(user, 'PO-0062'); // cancelled
    expect(within(menu).queryByRole('menuitem', { name: 'Pisah' })).not.toBeInTheDocument();
    await user.keyboard('{Escape}');

    menu = await openMore(user, 'PO-0063'); // handed over
    expect(within(menu).queryByRole('menuitem', { name: 'Pisah' })).not.toBeInTheDocument();
  });

  it('loads the full pre-order, opens the split dialog, and reloads after a successful split', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    splitPreorder.mockResolvedValue({ original: { id: 60 }, created: [{ id: 70, preorder_number: 'PO-0070' }] });
    await renderPreorders();
    await screen.findByText('PO-0060');

    const menu = await openMore(user, 'PO-0060');
    await user.click(within(menu).getByRole('menuitem', { name: 'Pisah' }));

    const dialog = await screen.findByRole('dialog', { name: 'Pisahkan PO-0060' });
    expect(getPreorder).toHaveBeenCalledWith(60);
    await user.clear(within(dialog).getByLabelText('Unit Stiker B yang dipindah'));
    await user.type(within(dialog).getByLabelText('Unit Stiker B yang dipindah'), '1');
    await user.tab();
    await user.click(within(dialog).getByTestId('split-confirm'));

    await waitFor(() => expect(splitPreorder).toHaveBeenCalledWith(60, { mode: 'items', items: [{ item_id: 2, qty: 1 }] }));
    const { useToastStore } = await import('../../resources/js/stores/toast');
    await waitFor(() => expect(useToastStore().items.some((i) => i.message === 'Dipisah menjadi PO-0070')).toBe(true));
    await waitFor(() => expect(listPreorders.mock.calls.length).toBeGreaterThan(1));
    await waitFor(() => expect(getPreorderSummary.mock.calls.length).toBeGreaterThan(1));
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Pisahkan PO-0060' })).not.toBeInTheDocument());
  });

  it('offers Pisah in the detail panel, disabled once a payment exists, and lists the split children', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({ ...FULL, split_children: [{ id: 70, preorder_number: 'PO-0070' }] });
    await renderPreorders();
    await screen.findByText('PO-0060');
    await user.click(screen.getByRole('button', { name: 'PO-0060' }));

    expect(await screen.findByTestId('detail-split')).toBeEnabled();
    const children = await screen.findByTestId('detail-split-children');
    expect(children).toHaveTextContent('Dipisah menjadi');
    expect(within(children).getByRole('button', { name: 'PO-0070' })).toBeInTheDocument();
  });

  it('disables the detail Pisah button when the loaded pre-order already has payments', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({ ...FULL, status: 'dp_paid', payments: [{ id: 1, amount: '1000.00', purpose: 'down_payment', method: 'cash' }] });
    await renderPreorders();
    await screen.findByText('PO-0060');
    await user.click(screen.getByRole('button', { name: 'PO-0060' }));

    const button = await screen.findByTestId('detail-split');
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('title', expect.stringContaining('pembayaran'));
  });

  it('shows "Dipisah dari" on a pre-order that was created by a split', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({ ...FULL, source: { type: 'split', preorder_id: 59, preorder_number: 'PO-0059' } });
    await renderPreorders();
    await screen.findByText('PO-0060');
    await user.click(screen.getByRole('button', { name: 'PO-0060' }));

    expect(await screen.findByTestId('detail-source')).toHaveTextContent('Dipisah dari');
  });
});

// Hapus pembayaran dari panel detail — owner/admin saja, status dihitung ulang server.
describe('PreordersView — delete payment (detail panel)', () => {
  const ROW = { id: 80, preorder_number: 'PO-0080', customer_name: 'Dewi', status: 'dp_paid', fulfillment: 'pickup', total_amount: '200000.00', paid_amount: '50000.00', outstanding: '150000.00', sellers: [] };
  const DETAIL = {
    id: 80, preorder_number: 'PO-0080', status: 'dp_paid', dispatch_status: 'pending', fulfillment: 'pickup',
    total_amount: '200000.00', paid_amount: '50000.00', outstanding: '150000.00', items: [], customer: { name: 'Dewi' },
    payments: [{ id: 5, purpose: 'down_payment', amount: '50000.00', paid_at: '2026-10-02T01:00:00Z', proof_id: 9 }],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: [ROW], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    getPreorderSummary.mockResolvedValue({ transaction_count: 1 });
    getPreorder.mockResolvedValue(DETAIL);
  });

  async function openDetail(user, role) {
    await renderPreorders(role);
    await screen.findByText('PO-0080');
    await user.click(screen.getByRole('button', { name: 'PO-0080' }));
  }

  it('confirms, calls the API with both ids and shows the recalculated status', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deletePreorderPayment.mockResolvedValue({ ...DETAIL, status: 'ordered', paid_amount: '0.00', outstanding: '200000.00', payments: [] });
    await openDetail(user, 'Owner');

    await user.click(await screen.findByTestId('delete-payment'));
    const dialog = await screen.findByRole('dialog', { name: 'Hapus pembayaran' });
    expect(dialog).toHaveTextContent('50.000');
    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));

    await waitFor(() => expect(deletePreorderPayment).toHaveBeenCalledWith(80, 5));
    const { useToastStore } = await import('../../resources/js/stores/toast');
    await waitFor(() => expect(useToastStore().items.some((i) => i.message === 'Pembayaran dihapus. Status sekarang: Dipesan.')).toBe(true));
    await waitFor(() => expect(screen.queryByTestId('delete-payment')).not.toBeInTheDocument());
    await waitFor(() => expect(listPreorders.mock.calls.length).toBeGreaterThan(1));
  });

  it('does not offer deletion to a cashier', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await openDetail(user, 'Cashier');

    await waitFor(() => expect(getPreorder).toHaveBeenCalled());
    await screen.findAllByText(/Rp\s*50\.000/); // detail terbuka, riwayat pembayaran tampil
    expect(screen.queryByTestId('delete-payment')).not.toBeInTheDocument();
  });

  it('does not offer deletion once the pre-order is handed over', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({ ...DETAIL, status: 'handed_over' });
    await openDetail(user, 'Owner');

    await waitFor(() => expect(getPreorder).toHaveBeenCalled());
    await screen.findAllByText(/Rp\s*50\.000/);
    expect(screen.queryByTestId('delete-payment')).not.toBeInTheDocument();
  });
});

// Kartu "Pelanggan" + "Pengambilan / pengiriman" di panel detail.
describe('PreordersView — customer & fulfillment card in the detail panel', () => {
  const ROW = { id: 90, preorder_number: 'PO-0090', customer_name: 'Maya', status: 'ordered', fulfillment: 'pickup', total_amount: '55000.00', outstanding: '55000.00', sellers: [] };
  const BASE = {
    id: 90, preorder_number: 'PO-0090', status: 'ordered', dispatch_status: 'pending', fulfillment: 'pickup',
    pickup_day: '2026-11-01', expected_date: '2026-12-15', shipping_cost: '0.00', total_amount: '55000.00', outstanding: '55000.00',
    items: [], payments: [], notes: 'Ambil di booth hari kedua',
    customer: { id: 4, name: 'Maya Putri', phone: '0812000111', email: 'maya@example.test', social_handle: '@maya', address: 'Jl. Mawar No. 3, Bandung' },
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: [ROW], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    getPreorderSummary.mockResolvedValue({ transaction_count: 1 });
  });

  async function open(detail) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue(detail);
    await renderPreorders();
    await screen.findByText('PO-0090');
    await user.click(screen.getByRole('button', { name: 'PO-0090' }));
    return screen.findByTestId('detail-customer-fulfillment');
  }

  it('shows the customer contact details and the pickup fulfillment (day, expected date, notes)', async () => {
    await open(BASE);

    const customer = screen.getByTestId('detail-customer');
    expect(customer).toHaveTextContent('Maya Putri');
    expect(customer).toHaveTextContent('0812000111');
    expect(customer).toHaveTextContent('maya@example.test');
    expect(customer).toHaveTextContent('@maya');
    expect(customer).toHaveTextContent('Jl. Mawar No. 3, Bandung');

    const fulfillment = screen.getByTestId('detail-fulfillment');
    expect(fulfillment).toHaveTextContent('Ambil sendiri');
    expect(fulfillment).toHaveTextContent(/1 Nov 2026/);
    expect(fulfillment).toHaveTextContent(/15 Des 2026/);
    expect(fulfillment).toHaveTextContent('Ambil di booth hari kedua');
  });

  it('shows courier and shipping cost for a Mail Order, and dashes for missing customer fields', async () => {
    await open({
      ...BASE, fulfillment: 'courier', pickup_day: null, courier_name: 'J&T', shipping_cost: '19000.00',
      expected_date: null, notes: null,
      customer: { id: 4, name: 'Maya Putri', phone: null, email: null, social_handle: null, address: null },
    });

    const fulfillment = screen.getByTestId('detail-fulfillment');
    expect(fulfillment).toHaveTextContent('Mail Order');
    expect(fulfillment).toHaveTextContent('J&T');
    expect(fulfillment).toHaveTextContent('19.000');
    expect(fulfillment).not.toHaveTextContent('Ambil di booth');

    expect(screen.getByTestId('detail-customer').textContent.match(/—/g).length).toBe(4);
  });
});

// 028-partial-split-payment (US1) — ringkasan + Tambah pembayaran di panel detail.
describe('PreordersView — payment summary and Add Payment (028 US1)', () => {
  const ROW = { id: 95, preorder_number: 'PO-0095', customer_name: 'Lia', status: 'ordered', fulfillment: 'pickup', total_amount: '1000000.00', paid_amount: '0.00', outstanding: '1000000.00', sellers: [] };
  const UNPAID = {
    id: 95, preorder_number: 'PO-0095', status: 'ordered', dispatch_status: 'pending', fulfillment: 'pickup',
    total_amount: '1000000.00', paid_amount: '0.00', outstanding: '1000000.00', items: [], customer: { name: 'Lia' }, payments: [],
    payment_summary: { grand_total: '1000000.00', total_paid: '0.00', remaining: '1000000.00', status: 'unpaid', payment_count: 0 },
  };
  const PARTIAL = {
    ...UNPAID, status: 'dp_paid', paid_amount: '400000.00', outstanding: '600000.00',
    payments: [{ id: 1, method: 'cash', purpose: 'down_payment', amount: '400000.00', paid_at: '2026-10-04T05:00:00Z', status: 'paid', reference: null, recorded_by_name: 'Kasir' }],
    payment_summary: { grand_total: '1000000.00', total_paid: '400000.00', remaining: '600000.00', status: 'partially_paid', payment_count: 1 },
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: [ROW], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    getPreorderSummary.mockResolvedValue({ transaction_count: 1 });
    getPreorder.mockResolvedValue(UNPAID);
  });

  it('shows the payment summary with the unpaid status and the Add Payment button', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0095');
    await user.click(screen.getByRole('button', { name: 'PO-0095' }));

    expect(await screen.findByTestId('summary-status')).toHaveTextContent('Belum dibayar');
    expect(screen.getByTestId('summary-remaining')).toHaveTextContent('1.000.000');
    expect(screen.getByTestId('add-payment')).toBeInTheDocument();
  });

  it('saves a partial payment from the dialog with one click and shows the updated summary', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const { fireEvent, waitFor: wf } = await import('@testing-library/vue');
    const user = userEvent.setup();
    createPreorderPayment.mockResolvedValue(PARTIAL);
    await renderPreorders();
    await screen.findByText('PO-0095');
    await user.click(screen.getByRole('button', { name: 'PO-0095' }));
    await user.click(await screen.findByTestId('add-payment'));

    const amount = await screen.findByLabelText(/jumlah dibayar/i);
    await fireEvent.update(amount, '400000');
    await wf(() => expect(amount).toHaveValue(400000));
    await user.click(screen.getByRole('button', { name: /simpan pembayaran/i }));

    await wf(() => expect(createPreorderPayment).toHaveBeenCalledTimes(1));
    expect(createPreorderPayment).toHaveBeenCalledWith(95, expect.objectContaining({ method: 'cash', amount: '400000.00', purpose: 'down_payment' }));
    await wf(() => expect(screen.getByTestId('summary-status')).toHaveTextContent('Dibayar sebagian'));
    expect(screen.getByTestId('summary-remaining')).toHaveTextContent('600.000');
    await wf(() => expect(listPreorders.mock.calls.length).toBeGreaterThan(1));
    await wf(() => expect(getPreorderSummary.mock.calls.length).toBeGreaterThan(1));
  });

  it('hides Add Payment and shows the Fully Paid banner once nothing remains', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({
      ...PARTIAL, status: 'settled', paid_amount: '1000000.00', outstanding: '0.00',
      payment_summary: { grand_total: '1000000.00', total_paid: '1000000.00', remaining: '0.00', status: 'fully_paid', payment_count: 2 },
    });
    await renderPreorders();
    await screen.findByText('PO-0095');
    await user.click(screen.getByRole('button', { name: 'PO-0095' }));

    expect(await screen.findByTestId('summary-fully-paid')).toBeInTheDocument();
    expect(screen.queryByTestId('add-payment')).not.toBeInTheDocument();
  });

  it('does not offer Add Payment on a handed-over or cancelled pre-order even with a balance', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue({ ...PARTIAL, status: 'cancelled' });
    await renderPreorders();
    await screen.findByText('PO-0095');
    await user.click(screen.getByRole('button', { name: 'PO-0095' }));

    await screen.findByTestId('summary-status');
    expect(screen.queryByTestId('add-payment')).not.toBeInTheDocument();
  });

  it('opens the dialog for another payment with the amount defaulted to the NEW remaining balance', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorder.mockResolvedValue(PARTIAL);
    await renderPreorders();
    await screen.findByText('PO-0095');
    await user.click(screen.getByRole('button', { name: 'PO-0095' }));
    await user.click(await screen.findByTestId('add-payment'));

    const amount = await screen.findByLabelText(/jumlah dibayar/i);
    await waitFor(() => expect(amount).toHaveValue(600000));
    expect(screen.getByTestId('summary-status')).toHaveTextContent('Dibayar sebagian');
  });
});

/**
 * 029-fix-bulk-invoice-logo (US1) — unduhan massal merender SETIAP invoice terpisah
 * lewat captureElementCanvas, berurutan, dengan logo toko di header dan QR hanya di
 * bagian pembayaran, serta tidak menyisakan kontainer di halaman. Halaman sengaja
 * memuat <img> lain (avatar/thumbnail) — penyebab asli QR tampil di slot logo.
 */
describe('PreordersView — bulk invoice download renders each invoice with its own images (029)', () => {
  const LOGO = 'http://example.test/storage/store-logo/logo.png';
  const QR = 'http://example.test/storage/payment-channels/qris.jpg';
  const invoiceFor = (id, number) => ({
    id,
    preorder_number: number,
    status: 'ordered',
    document_type: 'invoice',
    fulfillment: 'pickup',
    items: [{ id: 1, name_snapshot: 'Poster — Std', qty: 2, sell_price: '500000.00', line_total: '1000000.00' }],
    total_amount: '1000000.00',
    paid_amount: '0.00',
    outstanding: '1000000.00',
    event_name: 'Comifuro 23',
    event_location: 'ICE BSD',
    customer: { name: `Pelanggan ${number}` },
    store_identity: { name: 'Sakana Fridge', address: 'Jl. Contoh No. 1', logo_url: LOGO },
    payment_channels: [
      { id: 1, type: 'qr_ewallet', provider: 'Shopee', qr_image_url: QR },
      { id: 2, type: 'bank_transfer', provider: 'BCA', account_number: '8010591199' },
    ],
  });

  beforeEach(() => {
    vi.clearAllMocks();
    bulk.captures.length = 0;
    bulk.zipFiles.length = 0;
    listArtists.mockResolvedValue({ data: ARTISTS });
    listEvents.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
    bulkPreorderInvoices.mockResolvedValue({ data: [invoiceFor(10, 'PO-0010'), invoiceFor(11, 'PO-0011')] });
    URL.createObjectURL = vi.fn(() => 'blob:zip');
    URL.revokeObjectURL = vi.fn();
  });

  it('captures one container per invoice, in order, with the logo before the QR, and cleans up', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0010');

    // <img> lain di halaman (pemicu bug asli: indeks bergeser)
    const decoy = document.createElement('img');
    decoy.setAttribute('src', 'http://example.test/avatar.png');
    document.body.prepend(decoy);

    for (const number of ['PO-0010', 'PO-0011']) {
      await user.click(within(screen.getByText(number).closest('tr')).getByRole('checkbox'));
    }
    await user.click(await screen.findByRole('button', { name: 'Unduh invoice' }));

    await waitFor(() => expect(bulk.captures).toHaveLength(2));
    expect(bulkPreorderInvoices).toHaveBeenCalledWith([10, 11], 'invoice');

    expect(bulk.captures.map((c) => c.attached)).toEqual([true, true]);
    bulk.captures.forEach((c, i) => {
      const srcs = [...c.html.matchAll(/<img[^>]*\ssrc="([^"]*)"/g)].map((m) => m[1]);
      expect(srcs).toEqual([LOGO, QR]);
      expect(c.html).toContain(i === 0 ? 'PO-0010' : 'PO-0011');
      // tata letak sama dengan modal invoice (bukan pembuat HTML lama): identitas toko, kartu pelanggan, kartu "Cara pembayaran"
      expect(c.html).toContain('Sakana Fridge');
      expect(c.html).toContain('Jl. Contoh No. 1');
      expect(c.html).toContain(`Pelanggan ${i === 0 ? 'PO-0010' : 'PO-0011'}`);
      expect(c.html).toContain('Cara pembayaran');
      expect(c.html).toContain('8010591199');
    });
    expect(bulk.captures[0].el).not.toBe(bulk.captures[1].el);

    await waitFor(() => expect(bulk.zipFiles).toEqual(['invoice-PO-0010.pdf', 'invoice-PO-0011.pdf']));
    bulk.captures.forEach((c) => expect(document.body.contains(c.el)).toBe(false));

    decoy.remove();
  });

  it('renders the payment invoice document (same component as its modal) for each selected pre-order', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    bulkPreorderInvoices.mockResolvedValue({
      data: [{ ...invoiceFor(10, 'PO-0010'), payments: [{ id: 7, method: 'cash', purpose: 'down_payment', amount: '500000.00', paid_at: '2026-09-01T10:00:00Z' }] }],
    });
    await renderPreorders();
    await screen.findByText('PO-0010');

    await user.click(within(screen.getByText('PO-0010').closest('tr')).getByRole('checkbox'));
    // pilih jenis dokumen: Payment invoice
    await user.click(screen.getByRole('combobox'));
    await user.click(await screen.findByRole('option', { name: 'Invoice pembayaran' }));
    await user.click(await screen.findByRole('button', { name: 'Unduh invoice' }));

    await waitFor(() => expect(bulk.captures).toHaveLength(1));
    expect(bulkPreorderInvoices).toHaveBeenCalledWith([10], 'payment_invoice');
    const [capture] = bulk.captures;
    const srcs = [...capture.html.matchAll(/<img[^>]*\ssrc="([^"]*)"/g)].map((m) => m[1]);
    expect(srcs).toEqual([LOGO, QR]);
    expect(capture.html).toContain('Sakana Fridge');
    expect(capture.html).toContain('Rp 500.000');
    await waitFor(() => expect(bulk.zipFiles).toEqual(['payment_invoice-PO-0010.pdf']));
  });
});
