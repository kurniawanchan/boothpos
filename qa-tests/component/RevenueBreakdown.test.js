import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import RevenueBreakdown from '../../resources/js/components/report/RevenueBreakdown.vue';
import { revenueBreakdown } from '../../resources/js/utils/revenueBreakdown';
import { formatIDR } from '../../resources/js/utils/formatters';

// Contoh server (tests/Feature/RetailTaxReportingTest.php): 60.000 − diskon tingkat order 10.000, pajak 10% atas 50.000.
const EXAMPLE = { net_sales: '60000.00', order_discount_total: '10000.00', tax_total: '5000.00', grand_total: '55000.00' };

describe('RevenueBreakdown (048) — Net, Diskon, Pajak, Bruto berdampingan', () => {
  it('shows each figure, and Bruto equals Net − Diskon + Pajak (55.000 = the receipt total)', () => {
    render(RevenueBreakdown, { props: { revenue: EXAMPLE } });

    expect(screen.getByTestId('revenue-net')).toHaveTextContent('Rp 60.000');
    expect(screen.getByTestId('revenue-order-discount')).toHaveTextContent('Rp 10.000');
    expect(screen.getByTestId('revenue-tax')).toHaveTextContent('Rp 5.000');
    expect(screen.getByTestId('revenue-gross')).toHaveTextContent('Rp 55.000');

    const b = revenueBreakdown(EXAMPLE);
    expect(screen.getByTestId('revenue-gross')).toHaveTextContent(formatIDR(b.net - b.orderDiscount + b.tax));
  });

  it('labels the tax column with the tax label from settings', () => {
    render(RevenueBreakdown, { props: { revenue: EXAMPLE, taxLabel: 'PPN' } });

    expect(screen.getByText('PPN')).toBeInTheDocument();
  });

  it('falls back to the generic "Pajak" label when settings give none', () => {
    render(RevenueBreakdown, { props: { revenue: EXAMPLE } });

    expect(screen.getByText('Pajak')).toBeInTheDocument();
  });
});
