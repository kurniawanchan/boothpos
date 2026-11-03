import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render as baseRender, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import SalesByProductTab from '../../resources/js/components/report/SalesByProductTab.vue';
import ReportTransactionsModal from '../../resources/js/components/report/ReportTransactionsModal.vue';
import { salesByProductReport, exportSalesByProduct } from '../../resources/js/api/reports';
import { saveBlob } from '../../resources/js/utils/saveBlob';

vi.mock('../../resources/js/api/reports', () => ({
  salesByProductReport: vi.fn(), salesByProductTransactions: vi.fn(), exportSalesByProduct: vi.fn(),
}));
vi.mock('../../resources/js/utils/saveBlob', () => ({ saveBlob: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
const push = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }));

function render(component, options = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return baseRender(component, { ...options, global: { plugins: [pinia] } });
}

const REPORT = {
  scope: {}, best_seller_rule: { top: 3, metric: 'units', ties_included: true },
  options: { artists: [{ value: 1, label: 'Nekoyama' }, { value: 2, label: 'vlaisca' }], categories: [{ value: 5, label: 'Keychain' }], products: [{ value: 1, label: 'Cats' }] },
  totals: { units: 9, pos_units: 7, preorder_units: 2, sales: '90000.00', pos_sales: '70000.00', preorder_sales: '20000.00', transaction_count: 3, stock_in: 40, stock_remaining: 25 },
  data: [{
    product_id: 1, product_name: 'Cats', artist_id: 1, artist_name: 'Nekoyama', category_id: 5, category_name: 'Keychain',
    units: 9, pos_units: 7, preorder_units: 2, sales: '90000.00', pos_sales: '70000.00', preorder_sales: '20000.00', transaction_count: 3, stock_in: 40, stock_remaining: 25, rank: 1, is_best_seller: true,
    variants: [
      { variant_id: 1, variant_name: 'Black', units: 6, pos_units: 5, preorder_units: 1, sales: '60000.00', stock_in: 20, stock_remaining: 12, is_top: true },
      { variant_id: 2, variant_name: 'White', units: 3, pos_units: 2, preorder_units: 1, sales: '30000.00', stock_in: 20, stock_remaining: 13, is_top: false },
    ],
  }],
};

describe('SalesByProductTab — filters, category, top variant, export', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    salesByProductReport.mockResolvedValue(REPORT);
    exportSalesByProduct.mockResolvedValue(new Blob(['x']));
  });

  it('shows the category beside the seller and the POS / pre-order split', async () => {
    render(SalesByProductTab, { props: { eventId: 1 } });

    expect(await screen.findByTestId('product-meta')).toHaveTextContent('Nekoyama · Keychain');
    expect(screen.getByText(/POS 7 · pre-order 2/i)).toBeInTheDocument();
  });

  it('shows the statistic cards and per-product stock in / remaining', async () => {
    render(SalesByProductTab, { props: { eventId: 1 } });

    expect(await screen.findByTestId('stat-units')).toHaveTextContent('9');
    expect(screen.getByTestId('stat-transactions')).toHaveTextContent('3');
    expect(screen.getByTestId('stat-stock_in')).toHaveTextContent('40');
    expect(screen.getByTestId('stat-stock_remaining')).toHaveTextContent('25');
    expect(screen.getByTestId('stock-in')).toHaveTextContent('40');
    expect(screen.getByTestId('stock-remaining')).toHaveTextContent('25');
  });

  it('marks only the top variant inside the expanded breakdown', async () => {
    const user = userEvent.setup();
    render(SalesByProductTab, { props: { eventId: 1 } });
    await user.click(await screen.findByRole('button', { name: /2 varian|2 variants/i }));

    expect(screen.getAllByTestId('top-variant')).toHaveLength(1);
    expect(screen.getByTestId('top-variant')).toHaveTextContent('Black');
    expect(screen.getByTestId('variant')).toHaveTextContent('White');
  });

  it('offers the server-provided options and sends the chosen seller as a filter', async () => {
    const user = userEvent.setup();
    render(SalesByProductTab, { props: { eventId: 1 } });
    await screen.findByText('Cats');

    await user.click(within(screen.getByTestId('product-filters')).getAllByRole('button')[0]);
    await user.click(await screen.findByText('vlaisca'));

    await waitFor(() => expect(salesByProductReport).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: [2] })));
  });

  it('downloads the workbook with the current filters', async () => {
    const user = userEvent.setup();
    render(SalesByProductTab, { props: { eventId: 1 } });
    await screen.findByText('Cats');

    await user.click(screen.getByTestId('export-product-report'));

    await waitFor(() => expect(saveBlob).toHaveBeenCalledTimes(1));
    expect(exportSalesByProduct).toHaveBeenCalledWith(expect.objectContaining({ event_id: 1 }));
  });
});

describe('ReportTransactionsModal — mixed sales and pre-orders', () => {
  it('badges a pre-order and opens the pre-order screen instead of the sale detail', async () => {
    const user = userEvent.setup();
    const loader = vi.fn().mockResolvedValue({
      data: [
        { kind: 'sale', order_id: 4, order_number: 'TRX-4', created_at: '2026-11-01T10:00:00Z', cashier_name: 'A', qty: 1, amount: '10000.00' },
        { kind: 'preorder', order_id: 4, order_number: 'PO-4', created_at: '2026-11-01T09:00:00Z', cashier_name: 'B', qty: 2, amount: '20000.00' },
      ],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
    render(ReportTransactionsModal, { props: { open: true, title: 't', loader, amountLabel: 'Amount', showQty: true } });

    expect(await screen.findByText('TRX-4')).toBeInTheDocument();
    expect(screen.getAllByTestId('preorder-badge')).toHaveLength(1);

    await user.click(screen.getByText('PO-4'));
    expect(push).toHaveBeenCalledWith({ name: 'preorders', query: { preorder_id: 4 } });
  });
});
