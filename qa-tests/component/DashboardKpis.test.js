import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import DashboardKpis from '../../resources/js/components/dashboard/DashboardKpis.vue';

const pt = (t, v) => ({ t, v });

function summary(overrides = {}) {
  return {
    computed_at: '2026-10-11T12:30:04+07:00',
    business_date: '2026-10-11',
    period: 'today',
    comparison: { basis: 'same_time_yesterday', up_to: '12:30' },
    kpis: {
      net_sales: { value: '18420500.00', comparison: '17247000.00', change_pct: 6.8, series: [pt('00:00', '0.00'), pt('01:00', '5.00')], comparison_series: [pt('00:00', '0.00'), pt('01:00', '4.00')] },
      orders: { value: 486, comparison: 466, change_pct: 4.3, series: [], comparison_series: [] },
      avg_order_value: { value: '37902.00', comparison: '37020.00', change_pct: 2.4, series: [], comparison_series: [] },
      low_stock: { value: 7, comparison: 4, change_abs: 3, scope: 'organisation', series: [], comparison_series: [] },
      ...overrides,
    },
  };
}

const cell = (key) => screen.getByTestId(`kpi-${key}`);

describe('DashboardKpis (042)', () => {
  it('renders four cells with label, unit note, value and change chip', () => {
    render(DashboardKpis, { props: { summary: summary(), state: 'ready' } });
    expect(within(cell('net_sales')).getByText('Total penjualan')).toBeInTheDocument();
    expect(within(cell('net_sales')).getByText('Net · IDR')).toBeInTheDocument();
    expect(within(cell('net_sales')).getByText('Rp 18.420.500')).toBeInTheDocument();
    expect(within(cell('net_sales')).getByTestId('change-chip')).toHaveTextContent('+6,8%');
    expect(within(cell('net_sales')).getByText('vs kemarin s.d. 12.30')).toBeInTheDocument();

    expect(within(cell('orders')).getByText('Semua status')).toBeInTheDocument();
    expect(within(cell('orders')).getByText('486')).toBeInTheDocument();
    expect(within(cell('avg_order_value')).getByText('Net ÷ order')).toBeInTheDocument();
    expect(within(cell('avg_order_value')).getByText('Rp 37.902')).toBeInTheDocument();
    expect(within(cell('low_stock')).getByText('Di bawah reorder point')).toBeInTheDocument();
    expect(within(cell('low_stock')).getByText('7')).toBeInTheDocument();
  });

  it('shows the low-stock change in items and treats a rise as bad', () => {
    render(DashboardKpis, { props: { summary: summary(), state: 'ready' } });
    const chip = within(cell('low_stock')).getByTestId('change-chip');
    expect(chip).toHaveTextContent('+3 item');
    expect(chip.dataset.tone).toBe('bad');
    expect(within(cell('net_sales')).getByTestId('change-chip').dataset.tone).toBe('good');
  });

  it('draws one trend line per cell, each described for assistive tech', () => {
    render(DashboardKpis, { props: { summary: summary(), state: 'ready' } });
    expect(screen.getAllByRole('img')).toHaveLength(4);
    expect(within(cell('net_sales')).getByRole('img').getAttribute('aria-label')).toContain('Total penjualan');
  });

  it('shows zero as a value, never a dash, with the no-comparison chip when nothing to compare', () => {
    const s = summary({
      net_sales: { value: '0.00', comparison: '0.00', change_pct: null, series: [], comparison_series: [] },
      orders: { value: 0, comparison: 0, change_pct: null, series: [], comparison_series: [] },
      avg_order_value: { value: null, comparison: null, change_pct: null, series: [], comparison_series: [] },
      low_stock: { value: 0, comparison: 0, change_abs: 0, scope: 'organisation', series: [], comparison_series: [] },
    });
    render(DashboardKpis, { props: { summary: s, state: 'ready' } });
    expect(within(cell('net_sales')).getByText('Rp 0')).toBeInTheDocument();
    expect(within(cell('orders')).getByText('0')).toBeInTheDocument();
    expect(within(cell('avg_order_value')).getByText('Rp 0')).toBeInTheDocument(); // value null = tidak ada order selesai
    expect(within(cell('low_stock')).getByText('0')).toBeInTheDocument();
    expect(within(cell('net_sales')).getByTestId('change-chip')).toHaveTextContent('Tidak ada pembanding');
    expect(screen.getByTestId('dashboard-kpis').textContent).not.toContain('—');
  });

  it('shows "tidak tersedia" for a figure the server omitted, and still renders the others', () => {
    const s = summary();
    delete s.kpis.net_sales; // peran tanpa hak melihat uang: server menghilangkan angkanya, bukan menjadikannya nol
    render(DashboardKpis, { props: { summary: s, state: 'ready' } });
    expect(within(cell('net_sales')).getByText('Tidak tersedia')).toBeInTheDocument();
    expect(within(cell('net_sales')).queryByText(/Rp/)).toBeNull();
    expect(within(cell('orders')).getByText('486')).toBeInTheDocument();
  });

  it('uses the previous-period wording for 7d/30d', () => {
    const s = summary();
    s.comparison = { basis: 'previous_period' };
    render(DashboardKpis, { props: { summary: s, state: 'ready' } });
    expect(within(cell('net_sales')).getByText('vs periode sebelumnya')).toBeInTheDocument();
  });

  it('notes "Seluruh organisasi" on the low-stock cell only when an outlet is selected and scope is organisation', () => {
    const { unmount } = render(DashboardKpis, { props: { summary: summary(), state: 'ready', outletSelected: false } });
    expect(screen.queryByTestId('kpi-org-scope')).toBeNull();
    unmount();

    const { unmount: u2 } = render(DashboardKpis, { props: { summary: summary(), state: 'ready', outletSelected: true } });
    expect(within(cell('low_stock')).getByTestId('kpi-org-scope')).toHaveTextContent('Seluruh organisasi');
    u2();

    const s = summary();
    s.kpis.low_stock.scope = 'outlet';
    render(DashboardKpis, { props: { summary: s, state: 'ready', outletSelected: true } });
    expect(screen.queryByTestId('kpi-org-scope')).toBeNull();
  });

  it('shows its own loading state', () => {
    render(DashboardKpis, { props: { summary: null, state: 'loading' } });
    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(screen.queryByTestId('kpi-net_sales')).toBeNull();
  });

  it('shows an error with Retry that emits retry', async () => {
    const { emitted } = render(DashboardKpis, { props: { summary: null, state: 'error' } });
    expect(screen.getByRole('alert')).toHaveTextContent('Gagal memuat angka utama');
    await userEvent.setup().click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(emitted().retry).toHaveLength(1);
  });

  it('shows an empty state when ready but the response carries no figures at all', () => {
    render(DashboardKpis, { props: { summary: { kpis: null }, state: 'ready' } });
    expect(screen.getByText('Belum ada angka untuk ditampilkan.')).toBeInTheDocument();
  });
});
