import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import ProductFigures from '../../resources/js/components/product/ProductFigures.vue';

// 043 (T033/T030) — strip angka kepala halaman Produk.
const SUMMARY = {
  release_enabled: true,
  active: { count: 22, total: 24, draft: 1, discontinued: 1 },
  sold_out_today: { count: 6, examples: [{ product: 'Es Jeruk', outlet: null }, { product: 'Kopi Susu', outlet: 'WJM2' }] },
  unreleased: { count: 3, summary: ['Harga Kopi Susu'], active_version: 42, active_since: '2026-10-01' },
  quality: { count: 2, examples: ['Tahu Crispy', 'Kentang Goreng'], kind: 'no_station' },
};

function renderFigures(props) {
  const events = [];
  const view = render(ProductFigures, { props: { state: 'ready', summary: SUMMARY, onFilter: (f) => events.push(['filter', f]), onRetry: () => events.push(['retry']), ...props } });
  return { ...view, events };
}

describe('ProductFigures', () => {
  it('shows four cells with label, value and a muted sub-line taken from the summary', () => {
    renderFigures();
    const active = screen.getByTestId('figure-active');
    expect(within(active).getByText('Menu item aktif')).toBeInTheDocument();
    expect(within(active).getByText('22')).toBeInTheDocument();
    expect(within(active).getByText('dari 24 item · 1 draft · 1 discontinued')).toBeInTheDocument();

    const soldOut = screen.getByTestId('figure-sold_out');
    expect(within(soldOut).getByText('6')).toBeInTheDocument();
    expect(within(soldOut).getByText('Es Jeruk, Kopi Susu · WJM2')).toBeInTheDocument();

    const unreleased = screen.getByTestId('figure-unreleased');
    expect(within(unreleased).getByText('3')).toBeInTheDocument();
    expect(unreleased).toHaveTextContent(/Rilis aktif v42 sejak/);

    const quality = screen.getByTestId('figure-quality');
    expect(within(quality).getByText('2')).toBeInTheDocument();
    expect(within(quality).getByText('Tanpa station dapur: Tahu Crispy, Kentang Goreng')).toBeInTheDocument();
  });

  it('shows only three cells (no "Belum dirilis") when releases are off', () => {
    renderFigures({ summary: { ...SUMMARY, release_enabled: false, unreleased: null } });
    expect(screen.queryByTestId('figure-unreleased')).not.toBeInTheDocument();
    expect(screen.getByTestId('product-figures').querySelectorAll('[data-testid^="figure-"]')).toHaveLength(3);
  });

  it('treats zero as a value, never a dash', () => {
    renderFigures({ summary: { ...SUMMARY, sold_out_today: { count: 0, examples: [] }, quality: { count: 0, examples: [], kind: null } } });
    const soldOut = screen.getByTestId('figure-sold_out');
    expect(within(soldOut).getByText('0')).toBeInTheDocument();
    expect(within(soldOut).getByText('Semua item tersedia')).toBeInTheDocument();
    expect(within(screen.getByTestId('figure-quality')).getByText('Semua data lengkap')).toBeInTheDocument();
  });

  it('makes "86 hari ini" and "Kualitas data" buttons that emit a filter; the others are not buttons', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { events } = renderFigures();

    expect(screen.getByTestId('figure-active').tagName).toBe('DIV');
    expect(screen.getByTestId('figure-unreleased').tagName).toBe('DIV');
    await user.click(screen.getByTestId('figure-sold_out'));
    await user.click(screen.getByTestId('figure-quality'));
    expect(events).toEqual([
      ['filter', { kind: 'sold_out' }],
      ['filter', { kind: 'quality', quality: 'no_station' }],
    ]);
  });

  it('does not offer a filter on a zero figure (nothing to filter)', () => {
    renderFigures({ summary: { ...SUMMARY, sold_out_today: { count: 0, examples: [] } } });
    expect(screen.getByTestId('figure-sold_out').tagName).toBe('DIV');
  });

  it('has its own loading state and an error state whose retry is emitted', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const loading = renderFigures({ state: 'loading', summary: null });
    expect(screen.getByRole('status')).toBeInTheDocument();
    loading.unmount();

    const { events } = renderFigures({ state: 'error', summary: null });
    expect(screen.getByRole('alert')).toHaveTextContent('Gagal memuat ringkasan menu');
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(events).toEqual([['retry']]);
  });
});
