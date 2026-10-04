import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within, cleanup } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import TransactionItemsModal from '../../resources/js/components/sales/TransactionItemsModal.vue';
import { getOrder, getReceipt, voidOrder, addOrderPayment, deleteOrderPayment } from '../../resources/js/api/orders';
import { useAuthStore } from '../../resources/js/stores/auth';
import { getProduct } from '../../resources/js/api/products';

vi.mock('../../resources/js/api/orders', () => ({
  getOrder: vi.fn(), getReceipt: vi.fn(), voidOrder: vi.fn(), addOrderPayment: vi.fn(), deleteOrderPayment: vi.fn(),
}));
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }),
  uploadPaymentProof: vi.fn(),
}));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => toast }));

/**
 * Detail transaksi (dulu hanya tabel "Produk Terjual"): siapa, kapan, di event mana,
 * produk apa dari penjual mana beserta foto/diskonnya, bagaimana total dihitung, dan
 * bagaimana dibayar. Angka: kotor 90.000 − diskon item 2.000 − diskon pesanan 3.000 = 85.000.
 */
const FULL_ORDER = {
  id: 101,
  order_number: 'TRX-20260927-0001',
  status: 'completed',
  created_at: '2026-09-27T11:23:00Z',
  notes: 'Ambil jam 3',
  channel: 'offline',
  subtotal: '88000.00',
  discount_amount: '3000.00',
  total_amount: '85000.00',
  paid_amount: '100000.00',
  change_amount: '15000.00',
  void_reason: null,
  customer: { id: 5, name: 'Rara Anindya', phone: '0812-0000-1111', email: 'rara@contoh.test' },
  cashier: { id: 1, name: 'Owner Dummy' },
  event: { id: 1, name: 'Sakana Meet & Greet' },
  items: [
    { id: 1, product_id: 10, sku_snapshot: 'NEKKYAKT0001', name_snapshot: 'Akatsuki Keychain', variant_name: 'Blue', artist_name: 'Nekoyama Studio', category_name: 'Keychain', qty: 3, sell_price: '10000.00', discount_amount: '0.00', line_total: '30000.00', image_url: 'http://localhost/storage/variant.png' },
    { id: 2, product_id: 11, sku_snapshot: 'YUKSTSAK0001', name_snapshot: 'Sakura Sticker', variant_name: 'Pink', artist_name: 'Yukishiro Works', category_name: 'Sticker', qty: 3, sell_price: '20000.00', discount_amount: '2000.00', line_total: '58000.00', image_url: null },
  ],
  // 028 — ringkasan turunan: dibayar 100.000 tunai/QRIS − kembalian 15.000 = 85.000 = lunas.
  payment_summary: { grand_total: '85000.00', total_paid: '85000.00', remaining: '0.00', status: 'fully_paid', payment_count: 2 },
  payments: [
    { id: 1, method: 'cash', amount: '50000.00', verification: 'verified', status: 'paid', paid_at: '2026-09-27T11:24:00Z', provider: null },
    { id: 2, method: 'qr_ewallet', amount: '50000.00', verification: 'verified', status: 'paid', paid_at: '2026-09-27T11:25:00Z', provider: 'GoPay' },
  ],
};

// Modal ini menyarangkan ProductDetailModal/ReceiptModal yang memakai store Pinia.
// `menuKeys`: menu yang dimiliki pengguna yang login — membatalkan transaksi digerbang menu 'settings'
// (sama seperti di server: OrderController::void()).
function renderModal(props, menuKeys = []) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Tester', menu_keys: menuKeys };

  return render(TransactionItemsModal, { props, global: { plugins: [pinia] } });
}

async function open(order = FULL_ORDER, menuKeys = []) {
  getOrder.mockResolvedValue(order);
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  const view = renderModal({ open: true, orderId: order.id, onChanged: onChanged }, menuKeys);
  await screen.findByText(order.order_number);

  return user;
}

const onChanged = vi.fn();

describe('TransactionItemsModal — order header', () => {
  beforeEach(() => vi.clearAllMocks());

  it('is titled as a transaction detail, not just a product list', async () => {
    await open();

    expect(screen.getByRole('dialog', { name: 'Detail transaksi' })).toBeInTheDocument();
  });

  it('says who bought, who sold it, where, and any note', async () => {
    await open();

    expect(screen.getByText('Rara Anindya')).toBeInTheDocument();
    expect(screen.getByText('0812-0000-1111')).toBeInTheDocument();
    expect(screen.getByText('Owner Dummy')).toBeInTheDocument();
    expect(screen.getByText('Sakana Meet & Greet')).toBeInTheDocument();
    expect(screen.getByText('Ambil jam 3')).toBeInTheDocument();
  });

  it('shows Walk-in for an order without a customer and never prints "null" or "undefined"', async () => {
    await open({ ...FULL_ORDER, customer: null, notes: null });

    expect(screen.getByText('Walk-in')).toBeInTheDocument();
    const text = screen.getByRole('dialog').textContent;
    expect(text).not.toMatch(/null|undefined/);
    expect(screen.queryByText('Catatan')).not.toBeInTheDocument(); // tanpa catatan → bagian disembunyikan
  });

  it('summarises how many lines and units the order has', async () => {
    await open();

    expect(screen.getByText('2 item · 6 unit')).toBeInTheDocument();
  });

  it('flags a voided order with the reason', async () => {
    await open({ ...FULL_ORDER, status: 'voided', void_reason: 'Salah input' });

    expect(screen.getByRole('alert')).toHaveTextContent('Transaksi dibatalkan');
    expect(screen.getByRole('alert')).toHaveTextContent('Salah input');
  });

  it('shows no voided banner for a normal order', async () => {
    await open();

    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});

describe('TransactionItemsModal — items', () => {
  beforeEach(() => vi.clearAllMocks());

  it('describes each product: variant, SKU, seller, category', async () => {
    await open();
    const row = screen.getByText('Akatsuki Keychain').closest('li');

    expect(within(row).getByText('Blue')).toBeInTheDocument();
    expect(within(row).getByText('NEKKYAKT0001')).toBeInTheDocument();
    expect(within(row).getByText('Nekoyama Studio')).toBeInTheDocument();
    expect(within(row).getByText('Keychain')).toBeInTheDocument();
  });

  it('shows quantity × unit price and the line total', async () => {
    await open();
    const row = screen.getByText('Akatsuki Keychain').closest('li');

    expect(row).toHaveTextContent(/3\s*×\s*Rp\s*10\.000/);
    expect(row).toHaveTextContent(/Rp\s*30\.000/);
  });

  it('shows the per-line discount only when there is one', async () => {
    await open();

    expect(screen.getByText('Sakura Sticker').closest('li')).toHaveTextContent(/Diskon.*2\.000/);
    expect(screen.getByText('Akatsuki Keychain').closest('li')).not.toHaveTextContent('Diskon');
  });

  it('shows the product photo when there is one, and a placeholder when there is not', async () => {
    await open();

    const withImage = screen.getByText('Akatsuki Keychain').closest('li');
    expect(within(withImage).getByRole('img')).toHaveAttribute('src', 'http://localhost/storage/variant.png');

    const withoutImage = screen.getByText('Sakura Sticker').closest('li');
    expect(within(withoutImage).queryByRole('img')).not.toBeInTheDocument();
  });

  it('opens the product detail when a product name is clicked', async () => {
    getProduct.mockResolvedValue({ id: 10, name: 'Akatsuki Keychain', variants: [], total_stock: 5 });
    const user = await open();

    await user.click(screen.getByRole('button', { name: 'Akatsuki Keychain' }));

    await waitFor(() => expect(getProduct).toHaveBeenCalledWith(10));
  });

  it('still works with an older payload that lacks the new fields', async () => {
    await open({
      id: 7, order_number: 'ORD-OLD', total_amount: '60000.00',
      items: [{ id: 1, product_id: 10, sku_snapshot: 'ABCST0001', name_snapshot: 'Stiker Holografik', qty: 2, sell_price: '30000.00', line_total: '60000.00' }],
    });

    expect(screen.getByText('Stiker Holografik')).toBeInTheDocument();
    expect(screen.getByRole('dialog').textContent).not.toMatch(/null|undefined|NaN/);
  });

  it('says so when the order has no items', async () => {
    await open({ ...FULL_ORDER, items: [] });

    expect(screen.getByText('Tidak ada item pada transaksi ini.')).toBeInTheDocument();
  });
});

describe('TransactionItemsModal — totals and payments', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows how the total is built: gross, item discounts, order discount, total', async () => {
    await open();
    const totals = screen.getByTestId('order-totals');

    expect(within(totals).getByText('Subtotal').parentElement).toHaveTextContent(/Rp\s*90\.000/);
    expect(within(totals).getByText('Diskon item').parentElement).toHaveTextContent(/2\.000/);
    expect(within(totals).getByText('Diskon pesanan').parentElement).toHaveTextContent(/3\.000/);
    expect(within(totals).getByText('Total').parentElement).toHaveTextContent(/Rp\s*85\.000/);
  });

  it('hides discount lines that are zero', async () => {
    await open({ ...FULL_ORDER, discount_amount: '0.00', items: FULL_ORDER.items.map((i) => ({ ...i, discount_amount: '0.00' })) });
    const totals = screen.getByTestId('order-totals');

    expect(within(totals).queryByText('Diskon item')).not.toBeInTheDocument();
    expect(within(totals).queryByText('Diskon pesanan')).not.toBeInTheDocument();
  });

  it('lists every payment with its method, channel, amount and time', async () => {
    await open();
    const payments = screen.getByTestId('order-payments');

    expect(within(payments).getByText('Tunai')).toBeInTheDocument();
    expect(within(payments).getByText(/QRIS \/ e-wallet/)).toHaveTextContent('GoPay');
    expect(payments).toHaveTextContent(/50\.000/);
  });

  it('shows the payment summary (paid net of change) and the change, and hides the change when it is zero', async () => {
    await open();
    expect(screen.getByTestId('summary-total-paid')).toHaveTextContent(/85\.000/);
    expect(screen.getByTestId('summary-status')).toHaveTextContent('Lunas');
    expect(screen.getByTestId('order-payments')).toHaveTextContent(/Kembalian.*15\.000/);

    // dirender ulang tanpa kembalian
    vi.clearAllMocks();
    cleanup();
    await open({ ...FULL_ORDER, change_amount: '0.00' });
    expect(screen.getByTestId('order-payments')).not.toHaveTextContent('Kembalian');
  });
});

describe('TransactionItemsModal — actions and states', () => {
  beforeEach(() => vi.clearAllMocks());

  it('opens the receipt right from the detail (works wherever this modal is reused)', async () => {
    getReceipt.mockResolvedValue({ order_number: 'TRX-20260927-0001', store_name: 'Sakana Fridge', items: [], created_at: '2026-09-27T11:23:00Z' });
    const user = await open();

    await user.click(screen.getByRole('button', { name: 'Lihat struk' }));

    await waitFor(() => expect(getReceipt).toHaveBeenCalledWith(101));
  });

  it('reports a load failure instead of showing an empty modal silently', async () => {
    getOrder.mockRejectedValue(new Error('Gagal memuat detail transaksi.'));
    renderModal({ open: true, orderId: 101 });

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Gagal memuat detail transaksi.'));
  });

  it('shows a loading state before the order arrives', async () => {
    getOrder.mockReturnValue(new Promise(() => {}));
    renderModal({ open: true, orderId: 101 });

    expect(await screen.findByText('Memuat data…')).toBeInTheDocument();
  });
});

describe('TransactionItemsModal — payment entry status', () => {
  beforeEach(() => vi.clearAllMocks());

  it('labels every counted entry "Dibayar" (no verification workflow) and marks rejected ones', async () => {
    await open({
      ...FULL_ORDER,
      payments: [
        { id: 1, method: 'cash', amount: '50000.00', verification: 'verified', status: 'paid', paid_at: '2026-09-27T11:24:00Z', provider: null },
        { id: 2, method: 'bank_transfer', amount: '20000.00', verification: 'pending', status: 'paid', paid_at: '2026-09-27T11:25:00Z', provider: 'BCA' },
        { id: 3, method: 'qr_ewallet', amount: '15000.00', verification: 'rejected', status: 'rejected', paid_at: '2026-09-27T11:26:00Z', provider: 'GoPay' },
      ],
    });
    const payments = screen.getByTestId('order-payments');

    expect(within(payments).getAllByText('Dibayar')).toHaveLength(2);
    expect(within(payments).getByText('Ditolak')).toBeInTheDocument();
    expect(within(payments).queryByText('Belum diverifikasi')).not.toBeInTheDocument();
  });
});

// 028-partial-split-payment (US5) — penjualan POS yang dibayar sebagian.
describe('TransactionItemsModal — partially paid sale (028 US5)', () => {
  beforeEach(() => vi.clearAllMocks());

  const PARTIAL = {
    ...FULL_ORDER, paid_amount: '40000.00', change_amount: '0.00',
    payment_summary: { grand_total: '85000.00', total_paid: '40000.00', remaining: '45000.00', status: 'partially_paid', payment_count: 1 },
    payments: [{ id: 1, method: 'cash', amount: '40000.00', verification: 'verified', status: 'paid', paid_at: '2026-09-27T11:24:00Z', provider: null, recorded_by_name: 'Owner Dummy' }],
  };

  it('shows the partial status with the remaining balance and an Add Payment button', async () => {
    await open(PARTIAL);

    expect(screen.getByTestId('summary-status')).toHaveTextContent('Dibayar sebagian');
    expect(screen.getByTestId('summary-remaining')).toHaveTextContent('45.000');
    expect(screen.getByTestId('add-payment')).toBeInTheDocument();
  });

  it('hides Add Payment when fully paid and when the sale is voided', async () => {
    await open(FULL_ORDER);
    expect(screen.queryByTestId('add-payment')).not.toBeInTheDocument();

    cleanup();
    vi.clearAllMocks();
    await open({ ...PARTIAL, status: 'voided', void_reason: 'salah' });
    expect(screen.queryByTestId('add-payment')).not.toBeInTheDocument();
  });

  it('records the remaining payment from the dialog and tells the list to reload', async () => {
    const user = await open(PARTIAL);
    const paid = {
      ...PARTIAL, paid_amount: '85000.00',
      payment_summary: { grand_total: '85000.00', total_paid: '85000.00', remaining: '0.00', status: 'fully_paid', payment_count: 2 },
      payments: [...PARTIAL.payments, { id: 2, method: 'cash', amount: '45000.00', verification: 'verified', status: 'paid', paid_at: '2026-09-27T12:00:00Z', provider: null }],
    };
    addOrderPayment.mockResolvedValue(paid);

    await user.click(screen.getByTestId('add-payment'));
    const amount = await screen.findByLabelText(/jumlah dibayar/i);
    await waitFor(() => expect(amount).toHaveValue(45000));
    await user.click(screen.getByRole('button', { name: /simpan pembayaran/i }));

    await waitFor(() => expect(addOrderPayment).toHaveBeenCalledWith(101, expect.objectContaining({ method: 'cash', amount: '45000.00', purpose: 'full' })));
    await waitFor(() => expect(screen.getByTestId('summary-status')).toHaveTextContent('Lunas'));
    expect(onChanged).toHaveBeenCalled();
    expect(screen.queryByTestId('add-payment')).not.toBeInTheDocument();
  });

  it('offers Delete on a payment to the owner only, behind a confirmation', async () => {
    const user = await open(PARTIAL);
    deleteOrderPayment.mockResolvedValue({
      ...PARTIAL, paid_amount: '0.00',
      payment_summary: { grand_total: '85000.00', total_paid: '0.00', remaining: '85000.00', status: 'unpaid', payment_count: 0 },
      payments: [],
    });

    await user.click(screen.getByTestId('delete-payment'));
    const dialog = await screen.findByRole('dialog', { name: 'Hapus pembayaran' });
    expect(dialog).toHaveTextContent('40.000');
    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));

    await waitFor(() => expect(deleteOrderPayment).toHaveBeenCalledWith(101, 1));
    await waitFor(() => expect(screen.getByTestId('summary-status')).toHaveTextContent('Belum dibayar'));
    expect(onChanged).toHaveBeenCalled();
  });

  it('shows no Delete for a cashier', async () => {
    getOrder.mockResolvedValue(PARTIAL);
    const pinia = createPinia();
    setActivePinia(pinia);
    useAuthStore().user = { id: 2, role: 'Cashier', name: 'Kasir', menu_keys: [] };
    render(TransactionItemsModal, { props: { open: true, orderId: PARTIAL.id }, global: { plugins: [pinia] } });
    await screen.findByText(PARTIAL.order_number);

    expect(screen.queryByTestId('delete-payment')).not.toBeInTheDocument();
    expect(screen.getByTestId('add-payment')).toBeInTheDocument();
  });
});

describe('TransactionItemsModal — voiding a transaction', () => {
  beforeEach(() => vi.clearAllMocks());

  const asOwner = ['settings'];
  const openVoidDialog = async (user) => {
    await user.click(screen.getByRole('button', { name: 'Batalkan transaksi' }));

    return screen.findByRole('dialog', { name: 'Batalkan transaksi?' });
  };

  it('is offered only to users who may void, and only for a completed order', async () => {
    await open(FULL_ORDER, []);
    expect(screen.queryByRole('button', { name: 'Batalkan transaksi' })).not.toBeInTheDocument(); // kasir

    cleanup();
    await open(FULL_ORDER, asOwner);
    expect(screen.getByRole('button', { name: 'Batalkan transaksi' })).toBeInTheDocument();

    cleanup();
    await open({ ...FULL_ORDER, status: 'voided', void_reason: 'Salah input' }, asOwner);
    expect(screen.queryByRole('button', { name: 'Batalkan transaksi' })).not.toBeInTheDocument(); // sudah batal
  });

  it('warns what voiding does and needs a reason before it can be confirmed', async () => {
    const user = await open(FULL_ORDER, asOwner);
    const dialog = await openVoidDialog(user);
    const confirm = within(dialog).getByRole('button', { name: 'Ya, batalkan transaksi' });

    expect(within(dialog).getByText(/stok.*dikembalikan/i)).toBeInTheDocument();
    expect(confirm).toBeDisabled();

    await user.type(within(dialog).getByLabelText('Alasan pembatalan'), '   ');
    expect(confirm).toBeDisabled(); // spasi saja bukan alasan

    await user.type(within(dialog).getByLabelText('Alasan pembatalan'), 'Salah input');
    expect(confirm).toBeEnabled();
  });

  it('voids with the trimmed reason, tells the user, refreshes the order and lets the list know', async () => {
    voidOrder.mockResolvedValue({ ...FULL_ORDER, status: 'voided' });
    const user = await open(FULL_ORDER, asOwner);
    getOrder.mockResolvedValue({ ...FULL_ORDER, status: 'voided', void_reason: 'Salah input' });
    const dialog = await openVoidDialog(user);
    await user.type(within(dialog).getByLabelText('Alasan pembatalan'), '  Salah input  ');

    await user.click(within(dialog).getByRole('button', { name: 'Ya, batalkan transaksi' }));

    await waitFor(() => expect(voidOrder).toHaveBeenCalledWith(101, 'Salah input'));
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('Transaksi dibatalkan.'));
    await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(1));
    expect(await screen.findByRole('alert')).toHaveTextContent('Salah input');                 // banner batal muncul
    expect(screen.queryByRole('button', { name: 'Batalkan transaksi' })).not.toBeInTheDocument(); // tak bisa dibatalkan dua kali
  });

  it('keeps the order untouched and reports the reason when voiding fails', async () => {
    voidOrder.mockRejectedValue(new Error('Transaksi sudah dibatalkan.'));
    const user = await open(FULL_ORDER, asOwner);
    const dialog = await openVoidDialog(user);
    await user.type(within(dialog).getByLabelText('Alasan pembatalan'), 'Salah input');

    await user.click(within(dialog).getByRole('button', { name: 'Ya, batalkan transaksi' }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Transaksi sudah dibatalkan.'));
    expect(toast.success).not.toHaveBeenCalled();
    expect(onChanged).not.toHaveBeenCalled();
  });

  it('going back from the dialog voids nothing', async () => {
    const user = await open(FULL_ORDER, asOwner);
    const dialog = await openVoidDialog(user);

    await user.click(within(dialog).getByRole('button', { name: 'Kembali' }));

    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Batalkan transaksi?' })).not.toBeInTheDocument());
    expect(voidOrder).not.toHaveBeenCalled();
  });
});

