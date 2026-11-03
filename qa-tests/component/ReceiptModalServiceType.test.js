import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { useOutletStore } from '../../resources/js/stores/outlet';
import ReceiptModal from '../../resources/js/components/receipt/ReceiptModal.vue';

const base = {
  order_number: 'ORD-0001', store_name: 'Toko', created_at: '2026-09-01T10:00:00Z', cashier_name: 'Budi',
  items: [{ qty: 1, name: 'Kopi', price: '10000.00', line_total: '10000.00', home_outlet_name: 'Outlet A' }],
  subtotal: '10000.00', discount_amount: '0.00', total_amount: '10000.00', payment_summary: [{ method: 'cash', amount: '10000.00' }], change_amount: '0.00',
};
const mockReceipt = vi.hoisted(() => ({ value: null }));
vi.mock('../../resources/js/api/orders', () => ({ getReceipt: vi.fn(() => Promise.resolve(mockReceipt.value)) }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => ({ error: vi.fn(), success: vi.fn() }) }));

function show(over) {
  mockReceipt.value = { ...base, ...over };
  const pinia = createPinia();
  setActivePinia(pinia);
  useOutletStore().multi = false;
  return render(ReceiptModal, { props: { open: true, orderId: 1 }, global: { plugins: [pinia] } });
}

describe('ReceiptModal — cara layanan (090 US5)', () => {
  it('prints Dine-In for a dine-in sale', async () => {
    show({ service_type: 'dine_in', table: { id: 1, name: '5' } });

    expect(await screen.findByTestId('receipt-service-type')).toHaveTextContent('Dine-In');
  });

  it('prints Take Away for a take-away sale made at a table', async () => {
    show({ service_type: 'takeaway', table: { id: 1, name: '5' } });

    expect(await screen.findByTestId('receipt-service-type')).toHaveTextContent('Take Away');
  });

  it('does not repeat the type for a table-less take-away (already printed as the tab label line) or a legacy retail sale', async () => {
    const view = show({ service_type: 'takeaway', table: null, tab_label: 'Budi' });
    await screen.findByText('ORD-0001');
    expect(screen.queryByTestId('receipt-service-type')).not.toBeInTheDocument();
    view.unmount();

    show({ service_type: 'retail' });
    await screen.findByText('ORD-0001');
    expect(screen.queryByTestId('receipt-service-type')).not.toBeInTheDocument();
  });
});
