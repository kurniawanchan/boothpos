import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { getReference, getReferenceImageUrl } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), getReference: vi.fn(), getReferenceImageUrl: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const row = (id, hasReference) => ({
  key: `order:${id}`, id, order_number: `ORD-00${id}`, status: 'completed', created_at: '2026-09-01T10:00:00Z', cashier_name: 'K', item_count: 1,
  unit_count: 1, total_amount: '10000.00', artist_names: [], payment_methods: [], has_reference: hasReference,
});

// 054 (US8) — tautan referensi di tabel hanya pada penjualan yang punya referensi.
describe('SalesView — reference link (054 US8)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({ event: null, totals: {}, sessions: [], transactions: [row(1, true), row(2, false)] });
    getReference.mockResolvedValue({ note: 'Foto WA', has_image: false, can_edit: false, updated_at: null, updated_by_name: null });
  });

  async function open() {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    render(SalesView, { global: { plugins: [pinia, router] } });
    await screen.findByText('ORD-001');
    return user;
  }

  it('shows the link only on rows that have a reference', async () => {
    await open();

    expect(within(screen.getByText('ORD-001').closest('tr')).getByTestId('reference-link')).toBeInTheDocument();
    expect(within(screen.getByText('ORD-002').closest('tr')).queryByTestId('reference-link')).not.toBeInTheDocument();
  });

  it('places the link directly below the transaction number, in the same cell (055)', async () => {
    await open();

    const numberCell = screen.getByText('ORD-001').closest('td');
    const link = within(numberCell).getByTestId('reference-link');
    expect(screen.getByText('ORD-001').compareDocumentPosition(link) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('opens the reference without leaving the page', async () => {
    const user = await open();

    await user.click(screen.getByTestId('reference-link'));

    expect(await screen.findByText('Foto WA')).toBeInTheDocument();
    expect(getReference).toHaveBeenCalledWith(1);
    expect(screen.getByText('ORD-001')).toBeInTheDocument();
  });
});
