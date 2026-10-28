import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { useOutletStore } from '../../resources/js/stores/outlet';
import ReceiptModal from '../../resources/js/components/receipt/ReceiptModal.vue';

const baseReceipt = {
  order_number: 'ORD-0001',
  store_name: 'Toko Sakana Fridge',
  event_name: 'Sakana Fest 2026',
  created_at: '2026-09-01T10:00:00Z',
  cashier_name: 'Budi',
  items: [
    { qty: 1, name: 'Keychain Akatsuki', price: '50000.00', line_total: '50000.00', home_outlet_name: 'Outlet A' },
  ],
  subtotal: '50000.00',
  discount_amount: '0.00',
  total_amount: '50000.00',
  payment_summary: [{ method: 'cash', amount: '50000.00' }],
  change_amount: '0.00',
};

const mockReceipt = vi.hoisted(() => ({ value: null }));

vi.mock('../../resources/js/api/orders', () => ({
  getReceipt: vi.fn(() => Promise.resolve(mockReceipt.value)),
}));

vi.mock('../../resources/js/stores/toast', () => ({
  useToastStore: () => ({ error: vi.fn(), success: vi.fn() }),
}));

function renderModal(receiptOverrides, { multiOutlet = false } = {}) {
  mockReceipt.value = { ...baseReceipt, ...receiptOverrides };
  const pinia = createPinia();
  setActivePinia(pinia);
  useOutletStore().multi = multiOutlet;
  return render(ReceiptModal, { props: { open: true, orderId: 1 }, global: { plugins: [pinia] } });
}

/**
 * 046 — struk tidak lagi memuat event: tanpa nama event, lokasi, atau "Tersedia pada", walau payload
 * lama masih membawa field itu (klien lama / data tersimpan).
 */
describe('ReceiptModal tanpa event (046)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('tidak mencetak lokasi atau tanggal ketersediaan event', async () => {
    renderModal({
      event_name: 'Bazaar Lama',
      event_location: 'Jakarta Convention Center',
      event_available_on_date: '2026-09-02',
    });

    await screen.findByText('ORD-0001');
    expect(screen.queryByText('Jakarta Convention Center')).not.toBeInTheDocument();
    expect(screen.queryByText('Bazaar Lama')).not.toBeInTheDocument();
    expect(screen.queryByText('Lokasi')).not.toBeInTheDocument();
    expect(screen.queryByText('Tersedia pada')).not.toBeInTheDocument();
  });
});

describe('ReceiptModal powered-by line', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows "Powered by" with the configured app name', async () => {
    renderModal({ app_name: 'Kasir Sakana' });

    expect(await screen.findByText('Powered by Kasir Sakana')).toBeInTheDocument();
  });

  it('falls back to "Powered by BoothPOS" when the payload has no app name', async () => {
    renderModal({});

    expect(await screen.findByText('Powered by BoothPOS')).toBeInTheDocument();
  });
});

// 028-partial-split-payment (US5) — struk penjualan yang dibayar sebagian.
describe('ReceiptModal — partially paid sale (028)', () => {
  it('shows the paid amount, remaining balance and status for a partially paid sale', async () => {
    renderModal({
      total_amount: '100000.00', payment_summary: [{ method: 'cash', amount: '40000.00', reference: 'DP-1' }],
      paid_amount: '40000.00', balance_amount: '60000.00', payment_status: 'partially_paid',
    });

    expect(await screen.findByTestId('receipt-paid')).toHaveTextContent('40.000');
    expect(screen.getByTestId('receipt-balance')).toHaveTextContent('60.000');
    expect(screen.getByTestId('receipt-status')).toHaveTextContent('Dibayar sebagian');
    expect(screen.getByTestId('receipt-payment-ref')).toHaveTextContent('DP-1');
  });

  it('leaves a fully paid receipt unchanged', async () => {
    renderModal({ paid_amount: '50000.00', balance_amount: '0.00', payment_status: 'fully_paid' });

    await screen.findByText('Toko Sakana Fridge');
    expect(screen.queryByTestId('receipt-balance')).not.toBeInTheDocument();
    expect(screen.queryByTestId('receipt-status')).not.toBeInTheDocument();
  });
});

// 045 Stage 2 — outlet di struk: hanya bila instalasi punya lebih dari satu outlet.
describe('ReceiptModal outlet line (045)', () => {
  it('prints the selling outlet with several outlets', async () => {
    renderModal({ outlet_name: 'Warkop WJM 2' }, { multiOutlet: true });
    expect(await screen.findByTestId('receipt-outlet')).toHaveTextContent('Warkop WJM 2');
  });

  it('prints nothing extra at a single outlet', async () => {
    renderModal({ outlet_name: 'Warkop WJM 1' });
    await screen.findByText('ORD-0001');
    expect(screen.queryByTestId('receipt-outlet')).toBeNull();
  });
});

/**
 * 079 — identitas toko di struk mengikuti outlet transaksi: nama, alamat, telepon/surel, catatan. Baris yang
 * kosong dihilangkan; telepon/surel pelanggan tetap terpisah dari kontak toko.
 */
describe('ReceiptModal identitas toko per outlet (079)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('mencetak alamat, telepon, surel, dan catatan outlet', async () => {
    renderModal({
      store_name: 'Kedai Satu',
      store_address: 'Jl. Melati 5',
      store_contact_phone: '0812-111',
      store_contact_email: 'satu@example.test',
      store_notes: 'Buka 08–22\nWiFi: kedaisatu',
    });
    expect(await screen.findByText('Kedai Satu')).toBeInTheDocument();
    expect(screen.getByText('Jl. Melati 5')).toBeInTheDocument();
    expect(screen.getByTestId('receipt-store-contact')).toHaveTextContent('0812-111 · satu@example.test');
    expect(screen.getByTestId('receipt-store-notes')).toHaveTextContent('Buka 08–22');
  });

  it('menghilangkan baris yang kosong tanpa label kosong', async () => {
    renderModal({ store_name: 'Kedai Dua', store_address: null, store_contact_phone: null, store_contact_email: null, store_notes: null });
    expect(await screen.findByText('Kedai Dua')).toBeInTheDocument();
    expect(screen.queryByTestId('receipt-store-contact')).not.toBeInTheDocument();
    expect(screen.queryByTestId('receipt-store-notes')).not.toBeInTheDocument();
  });

  it('hanya telepon: tanpa pemisah menggantung', async () => {
    renderModal({ store_name: 'Kedai Tiga', store_contact_phone: '021-555', store_contact_email: null });
    expect(await screen.findByTestId('receipt-store-contact')).toHaveTextContent(/^021-555$/);
  });

  it('kontak pelanggan tidak tercampur dengan kontak toko', async () => {
    renderModal({ store_name: 'Kedai Empat', store_contact_phone: null, customer_name: 'Ani', customer_phone: '0899-1', customer_email: null });
    await screen.findByText('Kedai Empat');
    expect(screen.queryByTestId('receipt-store-contact')).not.toBeInTheDocument();
    expect(screen.getByText('0899-1')).toBeInTheDocument();
  });

  it('tidak menggandakan nama outlet bila sudah sama dengan nama toko', async () => {
    renderModal({ store_name: 'Kedai Lima', outlet_name: 'Kedai Lima' }, { multiOutlet: true });
    await screen.findByText('Kedai Lima');
    expect(screen.queryByTestId('receipt-outlet')).not.toBeInTheDocument();
  });
});
