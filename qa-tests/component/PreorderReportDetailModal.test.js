import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PreorderReportDetailModal from '../../resources/js/components/report/PreorderReportDetailModal.vue';
import { preorderReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/reports', () => ({
  preorderReport: vi.fn(),
}));

const push = vi.fn();
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
}));

// 012-seller-preorder-report-detail-export (US3/T015-T018) — drill-down dari
// satu baris ringkasan (atau per-penjual) laporan Pre-order ke daftar
// pre-order individual, sama seperti StockByArtistDetailModal (research.md R3).
const ROWS = [
  {
    preorder_id: 101,
    preorder_number: 'PO-0101',
    customer_name: 'Budi Santoso',
    order_value: '50000.00',
    collected: '30000.00',
    outstanding: '20000.00',
  },
  {
    preorder_id: 102,
    preorder_number: 'PO-0102',
    customer_name: null,
    order_value: '25000.00',
    collected: '10000.00',
    outstanding: '15000.00',
  },
];

function renderModal(props) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(PreorderReportDetailModal, {
    props: {
      open: true,
      status: 'dp_paid',
      paymentCompleteness: 'partial',
      artistId: null,
      ...props,
    },
    global: { plugins: [pinia] },
  });
}

describe('PreorderReportDetailModal — individual preorder drilldown', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('fetches the individual-preorder list for the given props and renders each row', async () => {
    preorderReport.mockResolvedValue({ rows: ROWS });

    renderModal();

    await waitFor(() =>
      expect(preorderReport).toHaveBeenCalledWith({
        status: 'dp_paid',
        payment_completeness: 'partial',
        artist_id: undefined,
      })
    );

    expect(await screen.findByText('PO-0101')).toBeInTheDocument();
    expect(screen.getByText('Budi Santoso')).toBeInTheDocument();
    expect(screen.getByText('PO-0102')).toBeInTheDocument();
    // Walk-in / no-customer fallback for a null customer_name.
    expect(screen.getByText('Pembeli walk-in')).toBeInTheDocument();
  });

  it('renders amounts that sum to the expected total across both rows', async () => {
    preorderReport.mockResolvedValue({ rows: ROWS });

    renderModal();

    await screen.findByText('PO-0101');

    // order_value: 50000 + 25000 = 75000; collected: 30000 + 10000 = 40000;
    // outstanding: 20000 + 15000 = 35000.
    expect(screen.getByText('Rp 50.000')).toBeInTheDocument();
    expect(screen.getByText('Rp 25.000')).toBeInTheDocument();
    expect(screen.getByText('Rp 30.000')).toBeInTheDocument();
    expect(screen.getByText('Rp 10.000')).toBeInTheDocument();
    expect(screen.getByText('Rp 20.000')).toBeInTheDocument();
    expect(screen.getByText('Rp 15.000')).toBeInTheDocument();
  });

  it('navigates to /preorders with the clicked row preorder_id on row click', async () => {
    preorderReport.mockResolvedValue({ rows: ROWS });

    renderModal();

    const row = await screen.findByText('PO-0101');
    await fireEvent.click(row.closest('tr'));

    expect(push).toHaveBeenCalledWith({ path: '/preorders', query: { preorder_id: 101 } });
  });

  it('shows an empty state when there are no preorders for the combination', async () => {
    preorderReport.mockResolvedValue({ rows: [] });

    renderModal();

    expect(await screen.findByText(/tidak ada pre-order untuk kombinasi ini/i)).toBeInTheDocument();
  });

  it('does not fetch when required props are missing', async () => {
    renderModal({ status: '', paymentCompleteness: '' });

    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(preorderReport).not.toHaveBeenCalled();
  });
});
