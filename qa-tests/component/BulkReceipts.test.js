import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import ReceiptDocument from '../../resources/js/components/receipt/ReceiptDocument.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { bulkReceipts } from '../../resources/js/api/orders';
import { captureElementCanvas } from '../../resources/js/utils/pdfCapture';
import { saveBlob } from '../../resources/js/utils/saveBlob';

const zips = vi.hoisted(() => []);
vi.mock('jszip', () => ({
  default: class {
    constructor() { zips.push(this); this.files = {}; }
    file(name, blob) { this.files[name] = blob; }
    generateAsync() { return Promise.resolve(new Blob(['zip'])); }
  },
}));
vi.mock('jspdf', () => ({ jsPDF: class { addImage() {} output() { return new Blob(['pdf']); } } }));
vi.mock('../../resources/js/utils/pdfCapture', () => ({
  captureElementCanvas: vi.fn(), downloadElementAsPdf: vi.fn(), downloadElementAsPng: vi.fn(),
}));
vi.mock('../../resources/js/utils/saveBlob', () => ({ saveBlob: vi.fn() }));
vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), bulkReceipts: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => toast }));

const row = (id, over = {}) => ({
  key: `order:${id}`, id, order_number: `ORD-${String(id).padStart(3, '0')}`, status: 'completed', created_at: '2026-09-01T10:00:00Z',
  cashier_id: 1, cashier_name: 'Kasir A', session_id: 1, item_count: 1, unit_count: 1, total_amount: '10000.00', items_gross: '10000.00', items_net: '10000.00',
  artist_names: [], payment_methods: ['cash'], ...over,
});
const RECEIPT = (id, over = {}) => ({
  status: 'completed', store_name: 'Toko', order_number: `ORD-${String(id).padStart(3, '0')}`, event_name: 'E', cashier_name: 'K', created_at: '2026-09-01T10:00:00Z',
  items: [{ name: 'Stiker', qty: 1, price: '10000.00', line_total: '10000.00' }], subtotal: '10000.00', discount_amount: '0.00', total_amount: '10000.00',
  payment_summary: [{ method: 'cash', amount: '10000.00' }], change_amount: '0.00', ...over,
});

async function open(rows) {
  salesReport.mockResolvedValue({ event: null, totals: {}, sessions: [], transactions: rows });
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
  render(SalesView, { global: { plugins: [pinia, router] } });
  await screen.findAllByRole('button', { name: /^ORD-\d+$/ });
  return user;
}
const button = () => screen.getByTestId('bulk-receipts');

// 054 (US9) — unduh semua struk (zip PDF) dari daftar yang sedang difilter.
describe('SalesView — download all receipts (054 US9)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    zips.length = 0;
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    captureElementCanvas.mockResolvedValue({ width: 430, height: 600, toDataURL: () => 'data:image/png;base64,AA' });
    bulkReceipts.mockImplementation(async (ids) => ({ data: ids.map((id) => RECEIPT(id)), missing: [] }));
  });

  it('counts the filtered rows on the button', async () => {
    await open([row(1), row(2), row(3)]);
    expect(button()).toHaveTextContent('3');
    expect(button()).toBeEnabled();
  });

  it('is disabled when nothing matches the filters', async () => {
    const user = await open([row(1)]);
    await user.type(screen.getByLabelText('Cari transaksi'), 'zzzz-tidak-ada');

    await waitFor(() => expect(button()).toBeDisabled());
  });

  it('requests exactly the filtered rows from every page and zips one PDF per receipt', async () => {
    const user = await open([row(1), row(2), row(3)]);

    await user.click(button());

    await waitFor(() => expect(saveBlob).toHaveBeenCalledTimes(1));
    expect(bulkReceipts).toHaveBeenCalledWith(expect.arrayContaining([1, 2, 3]));
    expect(bulkReceipts.mock.calls[0][0]).toHaveLength(3);
    expect(Object.keys(zips[0].files).sort()).toEqual(['struk-ORD-001.pdf', 'struk-ORD-002.pdf', 'struk-ORD-003.pdf']);
    expect(saveBlob.mock.calls[0][1]).toMatch(/^struk-.*\.zip$/);
    expect(toast.success).toHaveBeenCalled();
  });

  it('follows the filters: only the matching sales are requested', async () => {
    const user = await open([row(1, { cashier_id: 1, cashier_name: 'Kasir A' }), row(2, { cashier_id: 2, cashier_name: 'Kasir B' })]);
    await user.type(screen.getByLabelText('Cari transaksi'), 'kasir b');
    await waitFor(() => expect(button()).toHaveTextContent('1'));

    await user.click(button());

    await waitFor(() => expect(bulkReceipts).toHaveBeenCalledWith([2]));
  });

  it('covers all pages, not just the visible one', async () => {
    const many = Array.from({ length: 60 }, (_, i) => row(i + 1));
    const user = await open(many);
    expect(button()).toHaveTextContent('60');

    await user.click(button());

    await waitFor(() => expect(bulkReceipts).toHaveBeenCalled());
    expect(bulkReceipts.mock.calls[0][0]).toHaveLength(60);
  });

  it('refuses above 200 receipts with a clear message and requests nothing', async () => {
    const user = await open(Array.from({ length: 201 }, (_, i) => row(i + 1)));

    await user.click(button());

    expect(bulkReceipts).not.toHaveBeenCalled();
    expect(toast.warning).toHaveBeenCalledWith(expect.stringMatching(/200/));
  });

  it('reports a receipt that failed instead of silently dropping it, and still zips the rest', async () => {
    captureElementCanvas.mockRejectedValueOnce(new Error('boom')).mockResolvedValue({ width: 430, height: 600, toDataURL: () => 'data:image/png;base64,AA' });
    const user = await open([row(1), row(2), row(3)]);

    await user.click(button());

    await waitFor(() => expect(saveBlob).toHaveBeenCalledTimes(1));
    expect(Object.keys(zips[0].files)).toHaveLength(2);
    expect(toast.error).toHaveBeenCalledWith(expect.stringMatching(/1/));
  });

  it('reports ids the server could not find', async () => {
    bulkReceipts.mockResolvedValue({ data: [RECEIPT(1)], missing: [2] });
    const user = await open([row(1), row(2)]);

    await user.click(button());

    await waitFor(() => expect(saveBlob).toHaveBeenCalled());
    expect(toast.error).toHaveBeenCalledWith(expect.stringMatching(/1/));
  });

  it('does not download anything and says so when the request itself fails', async () => {
    bulkReceipts.mockRejectedValue(new Error('jaringan putus'));
    const user = await open([row(1)]);

    await user.click(button());

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
    expect(saveBlob).not.toHaveBeenCalled();
  });
});

describe('ReceiptDocument (054 US9)', () => {
  it('renders the receipt in Indonesian, and marks a voided sale', () => {
    const { rerender } = render(ReceiptDocument, { props: { receipt: RECEIPT(1) } });
    expect(screen.getByText('Toko')).toBeInTheDocument();
    expect(screen.getByText('Kembalian')).toBeInTheDocument();
    expect(screen.queryByTestId('receipt-voided')).not.toBeInTheDocument();

    rerender({ receipt: RECEIPT(1, { status: 'voided' }) });
    return waitFor(() => expect(screen.getByTestId('receipt-voided')).toHaveTextContent(/dibatalkan/i));
  });
});
