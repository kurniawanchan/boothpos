import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import SelfOrderReceiptView from '../../resources/js/views/SelfOrderReceiptView.vue';
import { getReceipt } from '../../resources/js/api/selfService';
import { downloadElementAsPdf, downloadElementAsPng } from '../../resources/js/utils/pdfCapture';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/selfService', () => ({ getContext: vi.fn(), getMenu: vi.fn(), uploadProof: vi.fn(), submitOrder: vi.fn(), getReceipt: vi.fn() }));
vi.mock('../../resources/js/utils/pdfCapture', () => ({ downloadElementAsPng: vi.fn(), downloadElementAsPdf: vi.fn() }));

const receipt = (over = {}) => ({
  reference_no: 'SS-251103-001', pos_order_number: null, status: 'awaiting_verification', reject_reason: null,
  store: { name: 'Warkop WJM 3', address: 'Jl. Mawar 1', phone: '021-555', logo_url: '/storage/logo.png' },
  table_name: '5', service_type: 'dine_in',
  lines: [
    { name: 'Kopi Susu', qty: 2, unit_price: '20000.00', line_total: '40000.00', note: 'tanpa gula', modifiers: [{ group: 'Level', option: 'Extra Shot', qty: 1 }] },
    { name: 'Nasi Goreng', qty: 1, unit_price: '15000.00', line_total: '15000.00', note: null, modifiers: [] },
  ],
  subtotal: '55000.00', tax_label: null, tax_amount: '0.00', total_amount: '55000.00',
  payment: { method: 'QRIS', status: 'waiting_verification' }, customer_name: null, created_at: '2026-11-03T05:00:00+00:00', ...over,
});

async function mount(token = 'rcpt-token') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/pesan/struk/:receiptToken', name: 'self-service-receipt', component: SelfOrderReceiptView }],
  });
  router.push(`/pesan/struk/${token}`);
  await router.isReady();
  const pinia = createPinia();
  setActivePinia(pinia);

  return render({ template: '<RouterView />' }, { global: { plugins: [pinia, router] } });
}

describe('SelfOrderReceiptView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    getReceipt.mockResolvedValue(receipt());
  });
  afterEach(() => vi.useRealTimers());

  it('shows the whole receipt in Indonesian, loaded by the receipt token', async () => {
    await mount();

    expect(await screen.findByTestId('receipt-document')).toBeInTheDocument();
    expect(getReceipt).toHaveBeenCalledWith('rcpt-token');
    expect(screen.getByTestId('receipt-store')).toHaveTextContent('Warkop WJM 3');
    expect(screen.getByText('Jl. Mawar 1')).toBeInTheDocument();
    expect(screen.getByTestId('receipt-ref')).toHaveTextContent('SS-251103-001');
    expect(screen.getByText('2× Kopi Susu')).toBeInTheDocument();
    expect(screen.getByText('Extra Shot')).toBeInTheDocument();
    expect(screen.getByText('“tanpa gula”')).toBeInTheDocument();
    expect(screen.getByTestId('receipt-total')).toHaveTextContent('Rp 55.000');
    expect(screen.getByText('Dine-In')).toBeInTheDocument();
    expect(screen.getByTestId('receipt-payment-status')).toHaveTextContent('Menunggu verifikasi');
    expect(screen.getByTestId('receipt-status')).toHaveTextContent('Menunggu verifikasi pembayaran oleh staf');
  });

  it('never claims the order is paid while the payment is only waiting for verification', async () => {
    await mount();
    await screen.findByTestId('receipt-document');

    expect(document.body.textContent).not.toMatch(/lunas/i);
  });

  it('shows the tax line only when there is tax, and the customer name only when given', async () => {
    getReceipt.mockResolvedValue(receipt({ tax_label: 'PB1', tax_amount: '5500.00', total_amount: '60500.00', customer_name: 'Budi' }));
    await mount();

    await screen.findByTestId('receipt-document');
    expect(screen.getByText('PB1')).toBeInTheDocument();
    expect(screen.getByText('Rp 5.500')).toBeInTheDocument();
    expect(screen.getByTestId('receipt-customer')).toHaveTextContent('Budi');
  });

  it.each([
    ['verified', 'Pembayaran terverifikasi, pesanan segera diproses'],
    ['dispatched', 'Pesanan sedang diproses di dapur'],
  ])('shows the latest status "%s"', async (status, text) => {
    getReceipt.mockResolvedValue(receipt({ status, pos_order_number: status === 'dispatched' ? 'TRX-7' : null, payment: { method: 'QRIS', status: 'verified' } }));
    await mount();

    expect(await screen.findByTestId('receipt-status')).toHaveTextContent(text);
    if (status === 'dispatched') expect(screen.getByTestId('receipt-pos-order')).toHaveTextContent('TRX-7');
    else expect(screen.queryByTestId('receipt-pos-order')).not.toBeInTheDocument();
  });

  it('shows the rejection and its reason', async () => {
    getReceipt.mockResolvedValue(receipt({ status: 'rejected', reject_reason: 'Bukti tidak cocok', payment: { method: 'QRIS', status: 'rejected' } }));
    await mount();

    expect(await screen.findByTestId('receipt-status')).toHaveTextContent('Pesanan ditolak');
    expect(screen.getByTestId('receipt-reason')).toHaveTextContent('Alasan: Bukti tidak cocok');
  });

  it('refreshes by itself while the order is not final, and stops once it is', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    getReceipt.mockResolvedValueOnce(receipt()).mockResolvedValue(receipt({ status: 'dispatched', pos_order_number: 'TRX-9', payment: { method: 'QRIS', status: 'verified' } }));
    await mount();
    await screen.findByTestId('receipt-document');
    expect(screen.getByText('Status diperbarui otomatis.')).toBeInTheDocument();

    await vi.advanceTimersByTimeAsync(16000);
    await waitFor(() => expect(screen.getByTestId('receipt-pos-order')).toHaveTextContent('TRX-9'));
    expect(screen.queryByText('Status diperbarui otomatis.')).not.toBeInTheDocument();

    const calls = getReceipt.mock.calls.length;
    await vi.advanceTimersByTimeAsync(60000);
    expect(getReceipt.mock.calls.length).toBe(calls);
  });

  it('keeps showing the receipt when a refresh fails', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    getReceipt.mockResolvedValueOnce(receipt()).mockRejectedValue(new ApiError('offline', { status: null }));
    await mount();
    await screen.findByTestId('receipt-document');

    await vi.advanceTimersByTimeAsync(16000);

    expect(screen.getByTestId('receipt-document')).toBeInTheDocument();
  });

  it('saves the receipt document as an image and as a PDF, named after the reference', async () => {
    const user = userEvent.setup();
    await mount();
    const documentEl = await screen.findByTestId('receipt-document');

    await user.click(screen.getByTestId('save-image'));
    await user.click(screen.getByTestId('save-pdf'));

    expect(downloadElementAsPng).toHaveBeenCalledWith(documentEl, 'struk-ss-251103-001.png');
    expect(downloadElementAsPdf).toHaveBeenCalledWith(documentEl, 'struk-ss-251103-001.pdf');
  });

  it('captures only the receipt even when other images are on the page, and the logo keeps its own src', async () => {
    const decoy = document.createElement('img');
    decoy.setAttribute('src', '/storage/avatar.png');
    document.body.prepend(decoy);
    const user = userEvent.setup();
    await mount();
    const documentEl = await screen.findByTestId('receipt-document');

    await user.click(screen.getByTestId('save-image'));

    const captured = downloadElementAsPng.mock.calls[0][0];
    expect(captured).toBe(documentEl);
    const imgs = captured.querySelectorAll('img');
    expect(imgs).toHaveLength(1);
    expect(imgs[0].getAttribute('src')).toBe('/storage/logo.png');
    decoy.remove();
  });

  it('says so when saving fails, and lets the customer try again', async () => {
    downloadElementAsPng.mockRejectedValueOnce(new Error('boom'));
    const user = userEvent.setup();
    await mount();
    await screen.findByTestId('receipt-document');

    await user.click(screen.getByTestId('save-image'));

    expect(await screen.findByRole('alert')).toHaveTextContent('Struk tidak dapat disimpan');
    expect(screen.getByTestId('save-image')).toBeEnabled();
  });

  it('offers to order again at the last table when it is known', async () => {
    localStorage.setItem('ss-last-table', 'tok.sig');
    await mount();

    expect(await screen.findByRole('link', { name: 'Pesan lagi' })).toHaveAttribute('href', '/pesan/tok.sig');
  });

  it('shows a friendly message for an unknown receipt link', async () => {
    getReceipt.mockRejectedValue(new ApiError('Not found', { status: 404 }));
    await mount();

    expect(await screen.findByTestId('receipt-notfound')).toHaveTextContent('Struk tidak ditemukan');
  });
});
