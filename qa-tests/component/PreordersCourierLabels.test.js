import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createI18n } from 'vue-i18n';
import { createRouter, createMemoryHistory } from 'vue-router';
import PreordersView from '../../resources/js/views/PreordersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useToastStore } from '../../resources/js/stores/toast';
import {
  listPreorders, getPreorder, getPreorderNeighbors, getPreorderSummary, preorderShippingLabels, bulkPreorderInvoices,
} from '../../resources/js/api/preorders';
import { listArtists } from '../../resources/js/api/artists';
import { listEvents } from '../../resources/js/api/events';
import { listCategories } from '../../resources/js/api/categories';
import { renderCourierLabels, LabelTooTallError } from '../../resources/js/utils/courierLabelSheet';
import { renderShippingSlipSheet } from '../../resources/js/utils/shippingSlipSheet';
import id from '../../resources/js/locales/id.json';
import en from '../../resources/js/locales/en.json';

/**
 * 069-courier-shipping-label-a4 — tombol label kurir di drawer detail (US1) dan
 * di bilah aksi massal (US2). Perender dan API diganti tiruan: yang diuji di sini
 * alurnya (data TERSIMPAN dari server, ringkasan yang dilewati, hak `export`).
 */
vi.mock('../../resources/js/api/preorders', () => ({
  listPreorders: vi.fn(), getPreorder: vi.fn(), getPreorderNeighbors: vi.fn(), getPreorderSummary: vi.fn(),
  matchingPreorderIds: vi.fn(), previewBulk: vi.fn(), applyBulk: vi.fn(),
  createPreorder: vi.fn(), updatePreorder: vi.fn(), deletePreorder: vi.fn(), updatePreorderStatus: vi.fn(),
  updatePreorderDispatchStatus: vi.fn(), getPreorderInvoice: vi.fn(), exportPreorders: vi.fn(),
  downloadPreorderImportTemplate: vi.fn(), importPreorders: vi.fn(), resendPreorderNotification: vi.fn(),
  bulkPreorderInvoices: vi.fn(), bulkEmailPreorderInvoices: vi.fn(), duplicatePreorders: vi.fn(), splitPreorder: vi.fn(),
  deletePreorderPayment: vi.fn(), createPreorderPayment: vi.fn(), preorderShippingLabels: vi.fn(),
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
vi.mock('../../resources/js/utils/courierLabelSheet', async (importOriginal) => {
  const actual = await importOriginal();
  return { ...actual, renderCourierLabels: vi.fn() };
});
vi.mock('../../resources/js/utils/shippingSlipSheet', async (importOriginal) => {
  const actual = await importOriginal();
  return { ...actual, renderShippingSlipSheet: vi.fn() };
});

const STORE = { name: 'Toko Uji', address: 'Jl. Toko', contact_phone: '0811', contact_person: 'Budi' };
const SHIPMENT = {
  id: 7, courier_name: 'JNE', tracking_number: 'TRK1', recipient_name: 'Avery', recipient_phone: '0800', address_line: 'Jl. A', status: 'pending',
};
const DETAIL = {
  id: 1, preorder_number: 'PO-1', status: 'dp_paid', dispatch_status: 'pending', fulfillment: 'courier',
  items: [], payments: [], customer: { name: 'Avery' }, shipment: SHIPMENT,
};
const LABEL = {
  preorder_id: 1, preorder_number: 'PO-1', courier_name: 'JNE', tracking_number: 'TRK1',
  recipient_name: 'Avery', recipient_phone: '0800', address_line: 'Jl. A',
};
const row = (n, fulfillment = 'courier') => ({
  id: n, preorder_number: `PO-${n}`, customer_name: `C${n}`, status: 'dp_paid', fulfillment,
  total_amount: '1.00', outstanding: '0.00', sellers: [],
});

let downloads = [];

async function renderView({ permissions } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'preorders'], ...(permissions ? { permissions } : {}) };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/preorders', name: 'preorders', component: { template: '<div />' } }] });
  router.push('/preorders');
  await router.isReady();
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { id, en } });
  render(PreordersView, { global: { plugins: [pinia, i18n, router] } });
  return useToastStore();
}

const messages = (toast) => toast.items.map((i) => i.message);

beforeEach(() => {
  vi.clearAllMocks();
  downloads = [];
  listArtists.mockResolvedValue({ data: [] });
  listEvents.mockResolvedValue({ data: [] });
  listCategories.mockResolvedValue({ data: [] });
  getPreorderSummary.mockResolvedValue({ transaction_count: 3 });
  getPreorderNeighbors.mockResolvedValue({ in_list: false, position: null, total: 0, previous: null, next: null });
  listPreorders.mockResolvedValue({ data: [row(1), row(2), row(3, 'pickup')], meta: { current_page: 1, per_page: 25, total: 3, last_page: 1 } });
  getPreorder.mockResolvedValue(structuredClone(DETAIL));
  renderCourierLabels.mockResolvedValue(new Blob(['%PDF']));
  renderShippingSlipSheet.mockResolvedValue(new Blob(['%PDF']));
  URL.createObjectURL = vi.fn(() => 'blob:x');
  URL.revokeObjectURL = vi.fn();
  const realCreate = document.createElement.bind(document);
  vi.spyOn(document, 'createElement').mockImplementation((tag, opts) => {
    const el = realCreate(tag, opts);
    if (tag === 'a') el.click = () => downloads.push(el.download);
    return el;
  });
});

afterEach(() => {
  vi.restoreAllMocks();
});

async function setupUser() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

describe('Courier label from the detail drawer (069 US1)', () => {
  async function openDetail(user, opts) {
    const toast = await renderView(opts);
    await screen.findByText('PO-1');
    await user.click(screen.getByRole('button', { name: 'PO-1' }));
    await screen.findByText(en.preorders.courier_shipping);
    return toast;
  }

  it('offers the courier label next to the existing shipping slip when a shipment exists', async () => {
    const user = await setupUser();
    await openDetail(user);

    expect(await screen.findByTestId('btn-courier-label')).toHaveTextContent(en.preorders.download_courier_label);
    expect(screen.getByRole('button', { name: en.preorders.download_shipping_slip })).toBeInTheDocument();
  });

  it('is absent without a shipment', async () => {
    const user = await setupUser();
    getPreorder.mockResolvedValue({ ...structuredClone(DETAIL), shipment: null });
    await openDetail(user);

    expect(screen.queryByTestId('btn-courier-label')).not.toBeInTheDocument();
  });

  it('is absent without the preorders.export action', async () => {
    const user = await setupUser();
    await openDetail(user, { permissions: { dashboard: ['view'], preorders: ['view', 'edit'] } });

    expect(screen.queryByTestId('btn-courier-label')).not.toBeInTheDocument();
  });

  it('prints the SAVED shipment from the server and downloads label-kurir-<number>.pdf', async () => {
    const user = await setupUser();
    preorderShippingLabels.mockResolvedValue({ data: [LABEL], skipped: [], store_identity: STORE });
    const toast = await openDetail(user);

    await user.click(await screen.findByTestId('btn-courier-label'));

    await waitFor(() => expect(downloads).toEqual(['label-kurir-PO-1.pdf']));
    expect(preorderShippingLabels).toHaveBeenCalledWith([1]);
    expect(renderCourierLabels).toHaveBeenCalledWith([LABEL], STORE);
    expect(messages(toast)).toContain(en.preorders.courier_label_downloaded);
    expect(messages(toast)).not.toContain(en.preorders.courier_label_unsaved_ignored);
  });

  it('warns that unsaved edits were not included but still prints the saved values', async () => {
    const user = await setupUser();
    preorderShippingLabels.mockResolvedValue({ data: [LABEL], skipped: [], store_identity: STORE });
    const toast = await openDetail(user);

    const tracking = screen.getByLabelText(en.preorders.tracking_number);
    await user.clear(tracking);
    await user.type(tracking, 'BELUM-DISIMPAN');
    await user.click(screen.getByTestId('btn-courier-label'));

    await waitFor(() => expect(downloads).toEqual(['label-kurir-PO-1.pdf']));
    expect(renderCourierLabels).toHaveBeenCalledWith([LABEL], STORE);
    expect(messages(toast)).toContain(en.preorders.courier_label_unsaved_ignored);
  });

  it('downloads nothing when the server no longer has a courier shipment for it', async () => {
    const user = await setupUser();
    preorderShippingLabels.mockResolvedValue({ data: [], skipped: [{ preorder_id: 1, preorder_number: 'PO-1', reason: 'no_shipment' }], store_identity: STORE });
    const toast = await openDetail(user);

    await user.click(await screen.findByTestId('btn-courier-label'));

    await waitFor(() => expect(messages(toast)).toContain(en.preorders.courier_label_not_available));
    expect(renderCourierLabels).not.toHaveBeenCalled();
    expect(downloads).toEqual([]);
  });

  it('names the pre-order when its label is too tall and downloads nothing', async () => {
    const user = await setupUser();
    preorderShippingLabels.mockResolvedValue({ data: [LABEL], skipped: [], store_identity: STORE });
    renderCourierLabels.mockRejectedValue(new LabelTooTallError(0, 900, 560));
    const toast = await openDetail(user);

    await user.click(await screen.findByTestId('btn-courier-label'));

    await waitFor(() => expect(messages(toast)).toContain(en.preorders.courier_label_too_tall.replace('{number}', 'PO-1')));
    expect(downloads).toEqual([]);
  });
});

describe('Bulk courier labels from the list selection (069 US2)', () => {
  async function selectAll(opts) {
    const user = await setupUser();
    const toast = await renderView(opts);
    await screen.findByText('PO-1');
    await user.click(screen.getByTestId('select-page'));
    return { user, toast };
  }

  it('sits beside the shipping-slips button and is absent without preorders.export', async () => {
    await selectAll();
    expect(await screen.findByTestId('bulk-btn-courier-labels')).toHaveTextContent(en.preorders.bulk_download_courier_labels);
    expect(screen.getByTestId('bulk-btn-shipping-slips')).toBeInTheDocument();
  });

  it('is hidden for a role without the export action', async () => {
    await selectAll({ permissions: { dashboard: ['view'], preorders: ['view', 'edit'] } });
    expect(screen.queryByTestId('bulk-btn-courier-labels')).not.toBeInTheDocument();
  });

  it('sends every selected id, renders the returned labels and downloads label-kurir.pdf', async () => {
    const labels = [{ ...LABEL }, { ...LABEL, preorder_id: 2, preorder_number: 'PO-2' }];
    preorderShippingLabels.mockResolvedValue({ data: labels, skipped: [], store_identity: STORE });
    const { user, toast } = await selectAll();

    await user.click(await screen.findByTestId('bulk-btn-courier-labels'));

    await waitFor(() => expect(downloads).toEqual(['label-kurir.pdf']));
    expect(preorderShippingLabels).toHaveBeenCalledWith([1, 2, 3]);
    expect(renderCourierLabels).toHaveBeenCalledWith(labels, STORE);
    expect(messages(toast)).toContain(en.preorders.courier_labels_downloaded.replace('{count}', '2'));
  });

  it('lists skipped numbers and only counts the inaccessible ones', async () => {
    preorderShippingLabels.mockResolvedValue({
      data: [{ ...LABEL }],
      skipped: [
        { preorder_id: 3, preorder_number: 'PO-3', reason: 'not_mail_order' },
        { preorder_id: 2, preorder_number: 'PO-2', reason: 'no_shipment' },
        { preorder_id: 9, preorder_number: null, reason: 'not_found' },
      ],
      store_identity: STORE,
    });
    const { user, toast } = await selectAll();

    await user.click(await screen.findByTestId('bulk-btn-courier-labels'));

    await waitFor(() => expect(downloads).toEqual(['label-kurir.pdf']));
    const all = messages(toast);
    expect(all).toContain(en.preorders.courier_labels_skipped.replace('{count}', '2').replace('{numbers}', 'PO-3, PO-2'));
    expect(all).toContain(en.preorders.courier_labels_skipped_hidden.replace('{count}', '1'));
    expect(all.join(' ')).not.toMatch(/null|undefined/);
  });

  it('creates no file and says why when nothing is eligible', async () => {
    preorderShippingLabels.mockResolvedValue({
      data: [], skipped: [{ preorder_id: 3, preorder_number: 'PO-3', reason: 'not_mail_order' }], store_identity: STORE,
    });
    const { user, toast } = await selectAll();

    await user.click(await screen.findByTestId('bulk-btn-courier-labels'));

    await waitFor(() => expect(messages(toast)).toContain(en.preorders.courier_labels_none_eligible));
    expect(renderCourierLabels).not.toHaveBeenCalled();
    expect(downloads).toEqual([]);
  });

  it('shows the server message when the request is refused (e.g. over the cap)', async () => {
    preorderShippingLabels.mockRejectedValue(new Error('Maksimal 200 pre-order per unduhan label kurir.'));
    const { user, toast } = await selectAll();

    await user.click(await screen.findByTestId('bulk-btn-courier-labels'));

    await waitFor(() => expect(messages(toast)).toContain('Maksimal 200 pre-order per unduhan label kurir.'));
    expect(downloads).toEqual([]);
  });

  it('leaves the existing shipping-slip sheet flow unchanged', async () => {
    bulkPreorderInvoices.mockResolvedValue({ data: [{ preorder_number: 'PO-1', store_identity: STORE, customer: { name: 'A' } }] });
    const { user } = await selectAll();

    await user.click(await screen.findByTestId('bulk-btn-shipping-slips'));

    await waitFor(() => expect(downloads).toEqual(['surat-jalan.pdf']));
    expect(bulkPreorderInvoices).toHaveBeenCalledWith([1, 2, 3], 'invoice');
    expect(renderShippingSlipSheet).toHaveBeenCalled();
    expect(preorderShippingLabels).not.toHaveBeenCalled();
  });
});
