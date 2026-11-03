import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import SelfOrderView from '../../resources/js/views/SelfOrderView.vue';
import { getContext, getMenu, submitOrder, uploadProof } from '../../resources/js/api/selfService';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/selfService', () => ({ getContext: vi.fn(), getMenu: vi.fn(), uploadProof: vi.fn(), submitOrder: vi.fn() }));

const TOKEN = 'abc.def';
const context = (over = {}) => ({
  outlet: { name: 'Warkop WJM 3', logo_url: null }, table: { name: '5' }, available: true, unavailable_reason: null,
  tax: { label: 'PB1', rate: 0, show: false },
  payment: { method: 'qris', channel_id: 1, qr_image_url: '/storage/qris.png' }, ...over,
});
const product = (over = {}) => ({
  id: 1, name: 'Mie Goreng', description: null, category_id: 3, category_name: 'Makanan', image_url: null, sold_out: false,
  price_from: '15000.00', price_to: '15000.00', variants: [{ id: 11, name: 'Standard', price: '15000.00' }], modifier_groups: [], ...over,
});
const menu = (products) => ({ categories: [{ id: 3, name: 'Makanan' }], products });

async function mount() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/pesan/:token', name: 'self-service', component: SelfOrderView },
      { path: '/pesan/struk/:receiptToken', name: 'self-service-receipt', component: { template: '<div data-testid="receipt-page" />' } },
    ],
  });
  router.push(`/pesan/${TOKEN}`);
  await router.isReady();
  const pinia = createPinia();
  setActivePinia(pinia);

  return { router, ...render({ template: '<RouterView />' }, { global: { plugins: [pinia, router] } }) };
}

describe('SelfOrderView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    URL.createObjectURL = vi.fn(() => 'blob:preview');
    URL.revokeObjectURL = vi.fn();
    window.scrollTo = vi.fn(); // jsdom tidak mengimplementasikannya
    getContext.mockResolvedValue(context());
    getMenu.mockResolvedValue(menu([product(), product({ id: 2, name: 'Es Teh', sold_out: true, variants: [{ id: 21, name: 'Standard', price: '5000.00' }] })]));
  });

  it('shows the outlet, the table and the menu in Indonesian, with no discount anywhere', async () => {
    await mount();

    await waitFor(() => expect(screen.getByTestId('outlet-name')).toHaveTextContent('Warkop WJM 3'));
    expect(screen.getByTestId('table-chip')).toHaveTextContent('Meja 5');
    expect(await screen.findByText('Mie Goreng')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('Cari menu…')).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/diskon|discount/i);
    expect(getContext).toHaveBeenCalledWith(TOKEN);
  });

  it('does not let a sold-out item be picked', async () => {
    await mount();

    const card = (await screen.findByText('Es Teh')).closest('button');
    expect(card).toBeDisabled();
    expect(within(card).getByText('Habis')).toBeInTheDocument();
  });

  it('adds a simple item straight to the cart and shows the bar with count and total', async () => {
    const user = userEvent.setup();
    await mount();

    await user.click((await screen.findByText('Mie Goreng')).closest('button'));
    await user.click((await screen.findByText('Mie Goreng')).closest('button'));

    const bar = await screen.findByTestId('cart-bar');
    expect(bar).toHaveTextContent('2 item');
    expect(bar).toHaveTextContent('Rp 30.000');
  });

  it('lets the customer pick Dine-In or Take Away in the cart, Dine-In first', async () => {
    const user = userEvent.setup();
    await mount();
    await user.click((await screen.findByText('Mie Goreng')).closest('button'));
    await user.click(await screen.findByTestId('cart-bar'));

    const dineIn = await screen.findByRole('button', { name: 'Dine-In' });
    const takeaway = screen.getByRole('button', { name: 'Take Away' });
    expect(dineIn).toHaveAttribute('aria-pressed', 'true');
    expect(takeaway).toHaveAttribute('aria-pressed', 'false');

    await user.click(takeaway);
    expect(takeaway).toHaveAttribute('aria-pressed', 'true');
  });

  it('shows only QRIS with the amount and blocks sending until a proof photo is attached', async () => {
    const user = userEvent.setup();
    await mount();
    await user.click((await screen.findByText('Mie Goreng')).closest('button'));
    await user.click(await screen.findByTestId('cart-bar'));
    await user.click(await screen.findByRole('button', { name: 'Lanjut bayar' }));

    expect(await screen.findByTestId('qris-image')).toHaveAttribute('src', '/storage/qris.png');
    expect(screen.getByTestId('pay-amount')).toHaveTextContent('Rp 15.000');
    expect(document.body.textContent).not.toMatch(/transfer|tunai|cash|kartu/i);
    expect(screen.getByTestId('submit-order')).toBeDisabled();
    expect(screen.getByText('Lampirkan foto bukti pembayaran dulu.')).toBeInTheDocument();

    await user.upload(document.querySelector('[data-testid="proof-gallery"]'), new File(['x'], 'bukti.jpg', { type: 'image/jpeg' }));

    await waitFor(() => expect(screen.getByTestId('submit-order')).toBeEnabled());
    expect(screen.getByAltText('Pratinjau bukti pembayaran')).toBeInTheDocument();
  });

  it('uploads the proof, sends an order without any money fields and opens the receipt', async () => {
    uploadProof.mockResolvedValue({ proof_token: 'proof-uuid' });
    submitOrder.mockResolvedValue({ receipt_token: 'rcpt', reference_no: 'SS-251103-001', status: 'awaiting_verification' });
    const user = userEvent.setup();
    const { router } = await mount();
    await user.click((await screen.findByText('Mie Goreng')).closest('button'));
    await user.click(await screen.findByTestId('cart-bar'));
    await user.click(await screen.findByRole('button', { name: 'Take Away' }));
    await user.click(screen.getByRole('button', { name: 'Lanjut bayar' }));
    await user.upload(document.querySelector('[data-testid="proof-camera"]'), new File(['x'], 'foto.jpg', { type: 'image/jpeg' }));
    await waitFor(() => expect(screen.getByTestId('submit-order')).toBeEnabled());

    await user.click(screen.getByTestId('submit-order'));

    // Setelah kirim pelanggan langsung dibawa ke struknya (FR-011).
    await waitFor(() => expect(router.currentRoute.value.name).toBe('self-service-receipt'));
    expect(router.currentRoute.value.params.receiptToken).toBe('rcpt');
    expect(uploadProof).toHaveBeenCalledWith(TOKEN, expect.any(File), 'webcam');
    const [, payload] = submitOrder.mock.calls[0];
    expect(payload).toMatchObject({ service_type: 'takeaway', proof_token: 'proof-uuid', items: [{ variant_id: 11, qty: 1 }] });
    expect(payload.local_ref).toMatch(/^[0-9a-f-]{36}$/);
    for (const forbidden of ['price', 'discount', 'total', 'outlet', 'table']) expect(JSON.stringify(payload)).not.toContain(forbidden);
    expect(localStorage.getItem(`ss-draft:${TOKEN}`)).toBeNull(); // draf dibersihkan setelah terkirim
  });

  it('keeps the cart when sending fails because an item became unavailable, and marks it', async () => {
    uploadProof.mockResolvedValue({ proof_token: 'proof-uuid' });
    submitOrder.mockRejectedValue(new ApiError('Beberapa item tidak bisa dipesan.', { status: 409, code: 'item_unavailable', data: { items: [{ variant_id: 11, reason: 'sold_out' }] } }));
    const user = userEvent.setup();
    await mount();
    await user.click((await screen.findByText('Mie Goreng')).closest('button'));
    await user.click(await screen.findByTestId('cart-bar'));
    await user.click(screen.getByRole('button', { name: 'Lanjut bayar' }));
    await user.upload(document.querySelector('[data-testid="proof-gallery"]'), new File(['x'], 'a.jpg', { type: 'image/jpeg' }));
    await waitFor(() => expect(screen.getByTestId('submit-order')).toBeEnabled());
    await user.click(screen.getByTestId('submit-order'));

    expect(await screen.findByText('Mie Goreng tidak tersedia')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('Beberapa item tidak tersedia lagi');
    expect(JSON.parse(localStorage.getItem(`ss-draft:${TOKEN}`)).lines).toHaveLength(1);
  });

  it('restores a saved draft after a reload', async () => {
    localStorage.setItem(`ss-draft:${TOKEN}`, JSON.stringify({
      lines: [{ key: 'k', product_id: 1, variant_id: 11, name: 'Mie Goreng', qty: 3, unit_price: 15000, note: '', option_ids: [], quantities: {}, modifiers: [] }],
      serviceType: 'takeaway', localRef: '11111111-1111-4111-8111-111111111111', savedAt: Date.now(),
    }));
    await mount();

    const bar = await screen.findByTestId('cart-bar');
    expect(bar).toHaveTextContent('3 item');
    expect(bar).toHaveTextContent('Rp 45.000');
  });

  it('still works when localStorage is unavailable', async () => {
    const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked'); });
    const user = userEvent.setup();
    await mount();

    await user.click((await screen.findByText('Mie Goreng')).closest('button'));
    expect(await screen.findByTestId('cart-bar')).toBeInTheDocument();
    spy.mockRestore();
  });

  it.each([
    [404, 'qr_invalid', 'Kode QR ini tidak berlaku. Silakan minta bantuan staf.'],
    [409, 'table_unavailable', 'Meja ini sedang tidak melayani pesanan mandiri. Silakan minta bantuan staf.'],
    [423, null, 'Layanan sedang tidak tersedia. Silakan minta bantuan staf.'],
  ])('shows a friendly message for a %s answer instead of the menu', async (status, code, text) => {
    getContext.mockRejectedValue(new ApiError('x', { status, code }));
    await mount();

    expect(await screen.findByTestId('fatal')).toHaveTextContent(text);
    expect(screen.queryByText('Mie Goreng')).not.toBeInTheDocument();
  });

  it('offers a retry when the network is down', async () => {
    getContext.mockRejectedValueOnce(new ApiError('offline', { status: null }));
    const user = userEvent.setup();
    await mount();

    await user.click(await screen.findByRole('button', { name: 'Coba lagi' }));

    expect(await screen.findByText('Mie Goreng')).toBeInTheDocument();
  });

  it('tells the customer when ordering is not available at this outlet', async () => {
    getContext.mockResolvedValue(context({ available: false, unavailable_reason: 'qris_unavailable', payment: null }));
    await mount();

    expect(await screen.findByTestId('unavailable')).toHaveTextContent('belum tersedia di outlet ini');
    expect(screen.queryByTestId('cart-bar')).not.toBeInTheDocument();
  });

  it('opens a picker for items with modifiers and prices the choice', async () => {
    getMenu.mockResolvedValue(menu([product({ modifier_groups: [{
      id: 7, name: 'Level Pedas', selection_type: 'single', is_required: true, min_select: 1, max_select: 1, portion_per_option: false,
      options: [{ id: 71, name: 'Pedas', price_delta: '2000.00', min_qty: 1, max_qty: 1 }, { id: 72, name: 'Sedang', price_delta: '0.00', min_qty: 1, max_qty: 1 }],
    }] })]));
    const user = userEvent.setup();
    await mount();

    await user.click((await screen.findByText('Mie Goreng')).closest('button'));
    const sheet = await screen.findByRole('dialog', { name: 'Mie Goreng' });
    const add = within(sheet).getByRole('button', { name: /Tambah ke keranjang/ });
    expect(add).toBeDisabled(); // pilihan wajib belum diisi

    await user.click(within(sheet).getByLabelText('Pedas'));
    expect(add).toBeEnabled();
    expect(add).toHaveTextContent('Rp 17.000');
    await user.click(add);

    expect(await screen.findByTestId('cart-bar')).toHaveTextContent('Rp 17.000');
  });

  it('sends no customer block for a guest, and keeps the typed details out of localStorage', async () => {
    uploadProof.mockResolvedValue({ proof_token: 'p' });
    submitOrder.mockResolvedValue({ receipt_token: 'rcpt', reference_no: 'SS-1', status: 'awaiting_verification' });
    const user = userEvent.setup();
    await mount();
    await user.click((await screen.findByText('Mie Goreng')).closest('button'));
    await user.click(await screen.findByTestId('cart-bar'));
    await user.click(screen.getByRole('button', { name: 'Lanjut bayar' }));
    await user.upload(document.querySelector('[data-testid="proof-gallery"]'), new File(['x'], 'a.jpg', { type: 'image/jpeg' }));
    await waitFor(() => expect(screen.getByTestId('submit-order')).toBeEnabled());

    await user.click(screen.getByTestId('submit-order'));

    await waitFor(() => expect(submitOrder).toHaveBeenCalled());
    expect(Object.keys(submitOrder.mock.calls[0][1])).not.toContain('customer');
  });

  it('blocks sending until name and phone are valid, then sends the details but never stores them', async () => {
    uploadProof.mockResolvedValue({ proof_token: 'p' });
    submitOrder.mockResolvedValue({ receipt_token: 'rcpt', reference_no: 'SS-1', status: 'awaiting_verification' });
    const user = userEvent.setup();
    await mount();
    await user.click((await screen.findByText('Mie Goreng')).closest('button'));
    await user.click(await screen.findByTestId('cart-bar'));
    await user.click(screen.getByRole('button', { name: 'Lanjut bayar' }));
    await user.upload(document.querySelector('[data-testid="proof-gallery"]'), new File(['x'], 'a.jpg', { type: 'image/jpeg' }));
    await waitFor(() => expect(screen.getByTestId('submit-order')).toBeEnabled());
    await user.click(screen.getByTestId('customer-details'));

    await user.click(screen.getByTestId('submit-order'));
    expect(submitOrder).not.toHaveBeenCalled();
    expect(screen.getByText('Nama wajib diisi.')).toBeInTheDocument();

    await user.type(screen.getByTestId('customer-name'), 'Budi');
    await user.type(screen.getByTestId('customer-phone'), '0812-3456-7890');
    expect(JSON.stringify({ ...localStorage })).not.toContain('Budi');
    expect(JSON.stringify({ ...localStorage })).not.toContain('3456');
    await user.click(screen.getByTestId('submit-order'));

    await waitFor(() => expect(submitOrder).toHaveBeenCalled());
    expect(submitOrder.mock.calls[0][1].customer).toEqual({ name: 'Budi', phone: '0812-3456-7890' });
  });

  it('marks the phone field when the server refuses it', async () => {
    uploadProof.mockResolvedValue({ proof_token: 'p' });
    submitOrder.mockRejectedValue(new ApiError('Invalid', { status: 422, errors: { 'customer.phone': ['Nomor telepon tidak valid.'] } }));
    const user = userEvent.setup();
    await mount();
    await user.click((await screen.findByText('Mie Goreng')).closest('button'));
    await user.click(await screen.findByTestId('cart-bar'));
    await user.click(screen.getByRole('button', { name: 'Lanjut bayar' }));
    await user.upload(document.querySelector('[data-testid="proof-gallery"]'), new File(['x'], 'a.jpg', { type: 'image/jpeg' }));
    await waitFor(() => expect(screen.getByTestId('submit-order')).toBeEnabled());
    await user.click(screen.getByTestId('customer-details'));
    await user.type(screen.getByTestId('customer-name'), 'Budi');
    await user.type(screen.getByTestId('customer-phone'), '081234567890');

    await user.click(screen.getByTestId('submit-order'));

    expect(await screen.findByText('Nomor telepon tidak valid. Contoh: 081234567890.')).toBeInTheDocument();
  });
});
