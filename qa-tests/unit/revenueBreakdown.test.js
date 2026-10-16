import { describe, it, expect } from 'vitest';
import { revenueBreakdown } from '../../resources/js/utils/revenueBreakdown';

describe('revenueBreakdown — Bruto = Net − Diskon + Pajak (048)', () => {
  it('60.000 − 10.000 at 10% (tax on 50.000): Bruto is 55.000, equal to the receipt total', () => {
    const b = revenueBreakdown({
      net_sales: '60000.00',
      order_discount_total: '10000.00',
      tax_total: '5000.00',
      grand_total: '55000.00',
    });

    expect(b).toEqual({ net: 60000, orderDiscount: 10000, tax: 5000, gross: 55000 });
    expect(b.gross).toBe(b.net - b.orderDiscount + b.tax);
  });

  it('computes Bruto with the same formula when the server omits grand_total', () => {
    const b = revenueBreakdown({ net_sales: '60000.00', order_discount_total: '10000.00', tax_total: '5000.00' });

    expect(b.gross).toBe(55000);
  });

  it('reads a missing report as zeros rather than failing', () => {
    expect(revenueBreakdown(null)).toEqual({ net: 0, orderDiscount: 0, tax: 0, gross: 0 });
  });
});
