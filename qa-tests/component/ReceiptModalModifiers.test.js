import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReceiptModal from '../../resources/js/components/receipt/ReceiptModal.vue';

// 084 — struk web memuat pilihan modifier pesanan POS (snapshot server) di bawah nama item, dan tidak menambah baris kosong
// untuk item tanpa modifier.
const mockReceipt = vi.hoisted(() => ({ value: null }));

vi.mock('../../resources/js/api/orders', () => ({ getReceipt: vi.fn(() => Promise.resolve(mockReceipt.value)) }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => ({ error: vi.fn(), success: vi.fn() }) }));

const receipt = (items) => ({
  order_number: 'ORD-0042', store_name: 'Warkop WJM', created_at: '2026-10-30T10:00:00Z', cashier_name: 'Budi',
  items, subtotal: '46000.00', discount_amount: '0.00', total_amount: '46000.00',
  payment_summary: [{ method: 'cash', amount: '50000.00' }], change_amount: '4000.00',
});

function renderModal(items) {
  mockReceipt.value = receipt(items);
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(ReceiptModal, { props: { open: true, orderId: 1 }, global: { plugins: [pinia] } });
}

describe('ReceiptModal modifier (084)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('menampilkan nama opsi modifier di bawah item', async () => {
    renderModal([{
      qty: 2, name: 'Kopi Susu', price: '23000.00', line_total: '46000.00',
      modifiers: [{ group: 'Level gula', option: 'Kurang manis', price_delta: '0.00' }, { group: 'Topping', option: 'Boba', price_delta: '3000.00' }],
    }]);

    expect(await screen.findByText('Kopi Susu')).toBeTruthy();
    expect(screen.getByText('Kurang manis, Boba')).toBeTruthy();
  });

  it('item tanpa modifier tidak menambah baris modifier', async () => {
    renderModal([{ qty: 1, name: 'Roti Bakar', price: '10000.00', line_total: '10000.00', modifiers: [] }]);

    expect(await screen.findByText('Roti Bakar')).toBeTruthy();
    expect(screen.queryByText(/Kurang manis|Boba/)).toBeNull();
  });

  it('nama item varian tunggal bawaan tampil tanpa "Standard"', async () => {
    // Server sudah menulis name_snapshot "Kopi Susu" untuk varian tunggal Standard; struk tidak menambahkannya lagi.
    renderModal([{ qty: 1, name: 'Kopi Susu', price: '20000.00', line_total: '20000.00', modifiers: [] }]);

    expect(await screen.findByText('Kopi Susu')).toBeTruthy();
    expect(screen.queryByText(/Standard/)).toBeNull();
  });
});
