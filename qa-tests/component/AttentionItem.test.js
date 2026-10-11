import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createRouter, createMemoryHistory } from 'vue-router';
import AttentionItem from '../../resources/js/components/page/AttentionItem.vue';

async function renderItem(props) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['sales', 'activity-log', 'session', 'stock'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  router.push('/sales');
  await router.isReady();
  return { router, ...render(AttentionItem, { props, global: { plugins: [router] } }) };
}

describe('AttentionItem (042 T029)', () => {
  it('payments_pending: title, "count · amount" value and the per-method meta', async () => {
    await renderItem({
      kind: 'payments_pending', severity: 'warn', count: 4, amount: '75900.00',
      meta: { by_method: { qr_ewallet: 3, bank_transfer: 1 } }, link: { route: 'sales', query: { pstate: 'pending' } },
    });
    expect(screen.getByText('Pembayaran menunggu verifikasi')).toBeInTheDocument();
    expect(screen.getByTestId('attention-value')).toHaveTextContent('4 · Rp 75.900');
    expect(screen.getByTestId('attention-meta')).toHaveTextContent('QRIS / e-wallet 3 · Transfer bank 1');
  });

  it('void_after_fire: items + amount, share of gross and the unknown-amount note', async () => {
    await renderItem({
      kind: 'void_after_fire', severity: 'warn', count: 9, amount: '214000.00', amountUnknownCount: 2,
      meta: { share_of_gross_pct: 1.1 }, link: { route: 'activity-log', query: { action: 'item_cancelled', date: '2026-10-11' } },
    });
    expect(screen.getByText('Void setelah fire')).toBeInTheDocument();
    expect(screen.getByTestId('attention-value')).toHaveTextContent('9 item · Rp 214.000');
    expect(screen.getByTestId('attention-meta')).toHaveTextContent('1,1% dari gross · 2 item tanpa nilai tercatat');
  });

  it('void_after_fire with no recorded value shows only the item count, never a made-up amount', async () => {
    await renderItem({ kind: 'void_after_fire', severity: 'warn', count: 3, amount: null, amountUnknownCount: 3, meta: {}, link: null });
    expect(screen.getByTestId('attention-value')).toHaveTextContent(/^3 item$/);
    expect(screen.getByTestId('attention-meta')).toHaveTextContent('3 item tanpa nilai tercatat');
  });

  it('cash_difference: signed amount with a typographic minus, and the tolerance', async () => {
    await renderItem({
      kind: 'cash_difference', severity: 'critical', count: 1, amount: '-23500.00',
      meta: { tolerance: '5000.00', session_id: 17 }, link: { route: 'session', query: { id: 17 } },
    });
    expect(screen.getByText('Selisih kas shift')).toBeInTheDocument();
    expect(screen.getByTestId('attention-value')).toHaveTextContent('−Rp 23.500');
    expect(screen.getByTestId('attention-meta')).toHaveTextContent('toleransi Rp 5.000');
  });

  it('low_stock: "n item"', async () => {
    await renderItem({ kind: 'low_stock', severity: 'warn', count: 7, amount: null, meta: {}, link: { route: 'stock', query: { low: 1 } } });
    expect(screen.getByText('Item stok menipis')).toBeInTheDocument();
    expect(screen.getByTestId('attention-value')).toHaveTextContent('7 item');
  });

  it.each([
    ['payments_pending', 'Semua pembayaran terverifikasi'],
    ['void_after_fire', 'Tidak ada void setelah fire'],
    ['cash_difference', 'Semua shift dalam toleransi'],
    ['low_stock', 'Semua stok aman'],
  ])('severity ok on %s shows the all-clear line with a check icon, not a value', async (kind, text) => {
    await renderItem({ kind, severity: 'ok', count: 0, amount: null, meta: {}, link: { route: 'sales', query: {} } });
    expect(screen.getByTestId('attention-ok-text')).toHaveTextContent(text);
    expect(screen.queryByTestId('attention-value')).toBeNull();
    expect(screen.getByTestId(`attention-${kind}`).querySelector('.ph-check-circle')).not.toBeNull();
    // Tidak ada yang perlu ditindaklanjuti -> teks biasa, bukan tautan.
    expect(screen.queryByRole('link')).toBeNull();
  });

  it('renders a link to the destination route and query when `link` is present', async () => {
    await renderItem({ kind: 'payments_pending', severity: 'warn', count: 1, amount: '1000.00', meta: {}, link: { route: 'sales', query: { pstate: 'pending' } } });
    const link = screen.getByRole('link');
    expect(link).toHaveAttribute('href', '/sales?pstate=pending');
    expect(link).toHaveTextContent('Pembayaran menunggu verifikasi');
  });

  it('renders plain text — not a link, not a disabled button — when `link` is null', async () => {
    await renderItem({ kind: 'low_stock', severity: 'warn', count: 2, amount: null, meta: {}, link: null });
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.getByTestId('attention-low_stock')).toHaveTextContent('2 item');
  });

  it('never relies on colour alone: every severity carries an icon and text', async () => {
    const { unmount } = await renderItem({ kind: 'low_stock', severity: 'critical', count: 1, amount: null, meta: {}, link: null });
    expect(screen.getByTestId('severity-label')).toHaveTextContent('Kritis');
    expect(screen.getByTestId('severity-label')).not.toHaveClass('sr-only'); // terlihat
    expect(screen.getByTestId('severity-icon')).toHaveClass('ph-warning-octagon');
    unmount();

    await renderItem({ kind: 'low_stock', severity: 'warn', count: 1, amount: null, meta: {}, link: null });
    expect(screen.getByTestId('severity-label')).toHaveTextContent('Perlu perhatian');
    expect(screen.getByTestId('severity-label')).toHaveClass('sr-only'); // untuk pembaca layar
    expect(screen.getByTestId('severity-icon')).toHaveClass('ph-warning');
  });
});
