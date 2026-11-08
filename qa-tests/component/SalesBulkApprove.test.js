import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { previewBulkApproval } from '../../resources/js/api/orderApprovals';
import { useAuthStore } from '../../resources/js/stores/auth';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), getReference: vi.fn(), getReferenceImageUrl: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ verifyOrderPayments: vi.fn(), getPaymentProofBlobUrl: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const row = (id, status = 'pending') => ({
  key: `order:${id}`, id, order_number: `ORD-0${id}`, status: 'completed', created_at: '2026-09-01T10:00:00Z', cashier_name: 'K', item_count: 1,
  unit_count: 1, total_amount: '10000.00', artist_names: ['A'], payment_methods: ['cash'], payment_state: 'verified',
  approval: { status, total: 1, approved: status === 'full' ? 1 : 0, pending: status === 'full' ? 0 : 1, sellers: [] },
});

/**
 * 064 — tombol "Setujui massal (N)" di daftar Sales: muncul HANYA bagi yang boleh menyetujui (izin aksi DAN punya seller
 * yang bisa disetujui, keduanya ditentukan server), memakai pilihan yang sama dengan aksi massal lain, dan dipasang
 * dengan id yang dipilih saat dibuka.
 */
describe('SalesView — bulk approve (064)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({ event: null, totals: {}, sessions: [], transactions: [row(1), row(2), row(3, 'full')] });
    previewBulkApproval.mockResolvedValue({ selected: 2, eligible_orders: 2, approvals: 2, amount: '20000.00', sellers: [], skipped: [], plan: [] });
  });

  async function open(user = {}) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const ue = userEvent.setup();
    const pinia = createPinia();
    setActivePinia(pinia);
    useAuthStore().user = { id: 1, name: 'U', role: 'Owner', menu_keys: ['sales'], ...user };
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/sales', name: 'sales', component: { template: '<div />' } }] });
    router.push('/sales');
    await router.isReady();
    render(SalesView, { global: { plugins: [pinia, router] } });
    await screen.findAllByRole('button', { name: /^ORD-\d+$/ });
    return ue;
  }

  it('shows the button only once rows are selected, with the count', async () => {
    const user = await open();
    expect(screen.queryByTestId('bulk-approve')).not.toBeInTheDocument();

    await user.click(screen.getByLabelText('Pilih ORD-01'));
    await user.click(screen.getByLabelText('Pilih ORD-02'));

    expect(screen.getByTestId('bulk-approve')).toHaveTextContent('Setujui massal (2)');
  });

  it('is hidden (not disabled) when the role lacks the approve action', async () => {
    const user = await open({ permissions: { sales: ['void'] } });
    await user.click(screen.getByLabelText('Pilih ORD-01'));
    expect(screen.queryByTestId('bulk-approve')).not.toBeInTheDocument();
  });

  it('is hidden when the server says the user has no seller to approve', async () => {
    const user = await open({ role: 'Cashier', can_approve_sellers: false });
    await user.click(screen.getByLabelText('Pilih ORD-01'));
    expect(screen.queryByTestId('bulk-approve')).not.toBeInTheDocument();
  });

  it('opens the dialog and previews exactly the ids selected at that moment', async () => {
    const user = await open();
    await user.click(screen.getByLabelText('Pilih ORD-01'));
    await user.click(screen.getByLabelText('Pilih ORD-02'));

    await user.click(screen.getByTestId('bulk-approve'));
    const dialog = await screen.findByRole('dialog', { name: /setujui transaksi secara massal/i });
    await user.click(within(dialog).getByTestId('method-stamp'));
    await user.click(within(dialog).getByTestId('bulk-continue'));

    await within(dialog).findByTestId('bulk-confirm-step');
    // Urutan = urutan daftar (server mempertahankan urutan permintaan); yang penting HIMPUNANNYA tepat.
    expect([...previewBulkApproval.mock.calls[0][0]].sort()).toEqual([1, 2]);
  });
});
