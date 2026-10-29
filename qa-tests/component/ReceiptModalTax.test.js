import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReceiptModal from '../../resources/js/components/receipt/ReceiptModal.vue';

const receipt = vi.hoisted(() => ({ value: null }));
vi.mock('../../resources/js/api/orders', () => ({ getReceipt: vi.fn(() => Promise.resolve(receipt.value)) }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => ({ error: vi.fn(), success: vi.fn() }) }));

const BASE = {
  order_number: 'ORD-0001', store_name: 'Toko Sakana', created_at: '2026-09-01T10:00:00Z', cashier_name: 'Budi',
  items: [{ qty: 1, name: 'Kopi', price: '7000.00', line_total: '7000.00', home_outlet_name: 'Outlet A' }],
  subtotal: '7000.00', discount_amount: '0.00', total_amount: '7700.00',
  tax_amount: '700.00', tax_rate: '10.00', tax_label: 'PPN', tax_show: true,
  payment_summary: [{ method: 'cash', amount: '7700.00' }], change_amount: '0.00',
};

async function show(over = {}) {
  receipt.value = { ...BASE, ...over };
  const pinia = createPinia();
  setActivePinia(pinia);
  render(ReceiptModal, { props: { open: true, orderId: 1 }, global: { plugins: [pinia] } });
  await screen.findByText('ORD-0001');
}

// 083 / US3 — struk: baris pajak mengikuti pengaturan, total selalu angka tersimpan.
describe('ReceiptModal — pajak', () => {
  beforeEach(() => vi.clearAllMocks());

  it('menampilkan baris pajak di antara diskon dan total saat tampil aktif', async () => {
    await show();

    expect(screen.getByTestId('receipt-tax')).toHaveTextContent('PPN (10%)');
    expect(screen.getByTestId('receipt-tax')).toHaveTextContent('Rp 700');
    expect(screen.getAllByText('Rp 7.700').length).toBeGreaterThan(0);
  });

  it('menyembunyikan baris pajak tetapi total tetap memuat pajak saat tampil mati', async () => {
    await show({ tax_show: false });

    expect(screen.queryByTestId('receipt-tax')).not.toBeInTheDocument();
    expect(screen.getAllByText('Rp 7.700').length).toBeGreaterThan(0);
  });

  it('struk order lama tanpa pajak tidak punya baris pajak', async () => {
    await show({ tax_amount: '0.00', tax_rate: '0.00', total_amount: '7000.00' });

    expect(screen.queryByTestId('receipt-tax')).not.toBeInTheDocument();
  });

  it('tarif snapshot order tetap 10% walau payload lama tanpa tax_show (klien lama)', async () => {
    await show({ tax_show: undefined });

    expect(screen.getByTestId('receipt-tax')).toHaveTextContent('PPN (10%)');
  });
});
