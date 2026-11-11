import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createI18n } from 'vue-i18n';
import { createRouter, createMemoryHistory } from 'vue-router';
import PreordersView from '../../resources/js/views/PreordersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useToastStore } from '../../resources/js/stores/toast';
import { listPreorders, getPreorderSummary, getPreorderNeighbors, bulkPreorderInvoices } from '../../resources/js/api/preorders';
import { listArtists } from '../../resources/js/api/artists';
import { listEvents } from '../../resources/js/api/events';
import { listCategories } from '../../resources/js/api/categories';
import { renderShippingSlipSheet, SlipTooTallError, PAGE_H } from '../../resources/js/utils/shippingSlipSheet';
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

// jsdom has no canvas: one fake canvas per rasterized PAGE, so call counts = pages.
const captured = [];
vi.mock('../../resources/js/utils/pdfCapture', () => ({
  captureElementCanvas: vi.fn(async (el) => {
    captured.push(el);
    return { width: 1588, height: 2246, toDataURL: () => 'data:image/png;base64,AAAA' };
  }),
  downloadElementAsPdf: vi.fn(),
  downloadElementAsPng: vi.fn(),
}));

const pdfCalls = { addPage: 0, addImage: 0, outputs: 0 };
vi.mock('jspdf', () => ({
  jsPDF: class {
    addPage() { pdfCalls.addPage += 1; }
    addImage() { pdfCalls.addImage += 1; }
    output() { pdfCalls.outputs += 1; return new Blob(['%PDF'], { type: 'application/pdf' }); }
  },
}));
const zipUsed = vi.fn();
vi.mock('jszip', () => ({ default: class { constructor() { zipUsed(); } } }));

const row = (n) => ({ id: n, preorder_number: `PO-${n}`, customer_name: `C${n}`, status: 'ordered', fulfillment: 'pickup', total_amount: '1.00', outstanding: '1.00', sellers: [] });
const invoice = (n, address = 'Jl. Uji') => ({
  preorder_number: `PO-${n}`,
  store_identity: { name: 'Test Store' },
  customer: { name: `Recipient ${n}`, phone: '0000', address },
});

async function renderView() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'preorders'] };
  listPreorders.mockResolvedValue({ data: [row(1), row(2), row(3)], meta: { current_page: 1, per_page: 3, total: 3, last_page: 1 } });
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/preorders', name: 'preorders', component: { template: '<div />' } }] });
  router.push('/preorders');
  await router.isReady();
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { id, en } });
  render(PreordersView, { global: { plugins: [pinia, i18n, router] } });
  return useToastStore();
}

describe('PreordersView — bulk shipping slips as one A4 sheet (067 US1)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    captured.length = 0;
    Object.assign(pdfCalls, { addPage: 0, addImage: 0, outputs: 0 });
    listArtists.mockResolvedValue({ data: [] });
    listEvents.mockResolvedValue({ data: [] });
    listCategories.mockResolvedValue({ data: [] }); // clearAllMocks drops the default from setup.js
    getPreorderSummary.mockResolvedValue({ transaction_count: 3 });
    getPreorderNeighbors.mockResolvedValue({ in_list: false, position: null, total: 0, previous: null, next: null });
    URL.createObjectURL = vi.fn(() => 'blob:x');
    URL.revokeObjectURL = vi.fn();
  });

  async function pickAllAndClick() {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const toast = await renderView();
    await screen.findByText('PO-1');
    await user.click(screen.getByTestId('select-page'));
    await user.click(await screen.findByTestId('bulk-btn-shipping-slips'));
    return toast;
  }

  it('downloads ONE surat-jalan.pdf (no ZIP) and shows the print-at-100% hint', async () => {
    bulkPreorderInvoices.mockResolvedValue({ data: [invoice(1), invoice(2), invoice(3)] });
    const clicked = [];
    const realCreate = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation((tag) => {
      const el = realCreate(tag);
      if (tag === 'a') el.click = () => clicked.push(el.download);
      return el;
    });

    const toast = await pickAllAndClick();

    await waitFor(() => expect(clicked).toEqual(['surat-jalan.pdf']));
    expect(bulkPreorderInvoices).toHaveBeenCalledWith([1, 2, 3], 'invoice');
    expect(zipUsed).not.toHaveBeenCalled();
    expect(pdfCalls.outputs).toBe(1);
    expect(captured).toHaveLength(1); // three slips fit one page -> one rasterization
    expect(toast.items.at(-1).message).toBe(en.preorders.shipping_sheet_hint);
    vi.restoreAllMocks();
  });

  it('shows the "too tall" message and downloads nothing when a slip cannot fit a page (FR-013)', async () => {
    bulkPreorderInvoices.mockResolvedValue({ data: [invoice(1), invoice(2), invoice(3)] });
    const desc = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetHeight');
    Object.defineProperty(HTMLElement.prototype, 'offsetHeight', { configurable: true, get: () => 5000 });
    try {
      const toast = await pickAllAndClick();
      await waitFor(() => expect(toast.items.at(-1)?.message).toBe(en.preorders.shipping_sheet_too_tall));
      expect(pdfCalls.outputs).toBe(0);
      expect(URL.createObjectURL).not.toHaveBeenCalled();
    } finally {
      Object.defineProperty(HTMLElement.prototype, 'offsetHeight', desc ?? { configurable: true, get: () => 0 });
    }
  });

  it('shows bulk_download_failed when fetching the slips fails and downloads nothing', async () => {
    bulkPreorderInvoices.mockRejectedValue(new Error(''));
    const toast = await pickAllAndClick();

    await waitFor(() => expect(toast.items.at(-1)?.message).toBe(en.preorders.bulk_download_failed));
    expect(pdfCalls.outputs).toBe(0);
  });
});

describe('renderShippingSlipSheet — pagination (067 US2)', () => {
  beforeEach(() => {
    captured.length = 0;
    Object.assign(pdfCalls, { addPage: 0, addImage: 0, outputs: 0 });
  });

  const invoices = (n) => Array.from({ length: n }, (_, i) => invoice(i + 1));
  const slipCount = () => captured.reduce((a, el) => a + el.querySelectorAll('[data-slip]').length, 0);

  it('40 slips flow onto the pages layoutSlipRows predicts and every slip is drawn once', async () => {
    // 200px rows -> 5 rows (1000 + 1 top border <= 1123) per page -> 15 slips/page -> 3 pages
    const blob = await renderShippingSlipSheet(invoices(40), { measure: (cells) => cells.map(() => 200) });

    expect(blob).toBeInstanceOf(Blob);
    expect(pdfCalls.addImage).toBe(3);
    expect(pdfCalls.addPage).toBe(2);
    expect(slipCount()).toBe(40);
  });

  it('one tall slip makes only its own row taller', async () => {
    const heights = Array.from({ length: 6 }, () => 100);
    heights[1] = 340;
    await renderShippingSlipSheet(invoices(6), { measure: () => heights });

    const rows = Array.from(captured[0].querySelectorAll('[data-row]'));
    expect(rows.map((r) => r.style.height)).toEqual(['341px', '100px']);
    expect(Array.from(rows[0].children).map((c) => c.style.height)).toEqual(['341px', '341px', '341px']);
  });

  it('200 slips complete, keep their order and release every page node', async () => {
    await renderShippingSlipSheet(invoices(200), { measure: (cells) => cells.map((_, i) => 90 + (i % 5) * 10) });

    const order = captured.flatMap((el) => Array.from(el.querySelectorAll('[data-slip]')).map((c) => Number(c.dataset.slip)));
    expect(order).toEqual(Array.from({ length: 200 }, (_, i) => i));
    expect(document.querySelectorAll('[data-sheet-page]')).toHaveLength(0);
    expect(pdfCalls.addPage).toBe(captured.length - 1);
  });

  it('a slip taller than a page throws SlipTooTallError and produces no PDF', async () => {
    await expect(renderShippingSlipSheet(invoices(3), { measure: () => [100, PAGE_H, 100] })).rejects.toBeInstanceOf(SlipTooTallError);
    expect(pdfCalls.outputs).toBe(0);
    expect(captured).toHaveLength(0);
  });
});
