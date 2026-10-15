import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import PaymentSummaryCard from '../../resources/js/components/payment/PaymentSummaryCard.vue';

const summary = (over = {}) => ({
  grand_total: '1000000.00', total_paid: '0.00', remaining: '1000000.00', status: 'unpaid', payment_count: 0, ...over,
});

/**
 * 028-partial-split-payment — kartu ringkasan: Total tagihan, Total terbayar,
 * Sisa tagihan, dan status pembayaran (locale default pengujian: Indonesia).
 */
describe('PaymentSummaryCard (028 US1)', () => {
  it('shows the grand total, total paid and remaining balance prominently', () => {
    render(PaymentSummaryCard, { props: { summary: summary({ total_paid: '400000.00', remaining: '600000.00', status: 'partially_paid', payment_count: 1 }) } });

    expect(screen.getByTestId('summary-grand-total')).toHaveTextContent('1.000.000');
    expect(screen.getByTestId('summary-total-paid')).toHaveTextContent('400.000');
    expect(screen.getByTestId('summary-remaining')).toHaveTextContent('600.000');
  });

  it('labels an unpaid transaction "Belum dibayar" with the full amount remaining', () => {
    render(PaymentSummaryCard, { props: { summary: summary() } });

    expect(screen.getByTestId('summary-status')).toHaveTextContent('Belum dibayar');
    expect(screen.getByTestId('summary-remaining')).toHaveTextContent('1.000.000');
    expect(screen.getByTestId('summary-total-paid')).toHaveTextContent('0');
  });

  it('labels a part-paid transaction "Dibayar sebagian"', () => {
    render(PaymentSummaryCard, { props: { summary: summary({ total_paid: '200000.00', remaining: '800000.00', status: 'partially_paid', payment_count: 1 }) } });

    expect(screen.getByTestId('summary-status')).toHaveTextContent('Dibayar sebagian');
  });

  it('says how many payments were made once there are two or more ("Dibayar sebagian · 2 pembayaran")', () => {
    render(PaymentSummaryCard, { props: { summary: summary({ total_paid: '400000.00', remaining: '600000.00', status: 'partially_paid', payment_count: 2 }) } });

    expect(screen.getByTestId('summary-status')).toHaveTextContent('Dibayar sebagian · 2 pembayaran');
  });

  it('keeps the plain "Dibayar sebagian" label after a single payment', () => {
    render(PaymentSummaryCard, { props: { summary: summary({ total_paid: '400000.00', remaining: '600000.00', status: 'partially_paid', payment_count: 1 }) } });

    expect(screen.getByTestId('summary-status').textContent.trim()).toBe('Dibayar sebagian');
  });

  it('shows a clear Fully Paid banner at zero remaining and none otherwise', () => {
    const { unmount } = render(PaymentSummaryCard, { props: { summary: summary({ total_paid: '1000000.00', remaining: '0.00', status: 'fully_paid', payment_count: 3 }) } });

    expect(screen.getByTestId('summary-status')).toHaveTextContent('Lunas');
    expect(screen.getByTestId('summary-fully-paid')).toHaveTextContent(/lunas/i);
    expect(screen.getByTestId('summary-remaining')).toHaveTextContent('0');
    unmount();

    render(PaymentSummaryCard, { props: { summary: summary({ total_paid: '400000.00', remaining: '600000.00', status: 'partially_paid', payment_count: 1 }) } });
    expect(screen.queryByTestId('summary-fully-paid')).not.toBeInTheDocument();
  });
});
