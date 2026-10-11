import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import AttentionPanel from '../../resources/js/components/page/AttentionPanel.vue';
import { useSettingsStore } from '../../resources/js/stores/settings';

const ITEMS = [
  { kind: 'payments_pending', severity: 'warn', count: 4, amount: '75900.00', meta: { by_method: { qr_ewallet: 3, bank_transfer: 1 } }, link: { route: 'sales', query: { pstate: 'pending' } } },
  { kind: 'void_after_fire', severity: 'ok', count: 0, amount: null, meta: {}, link: null },
  { kind: 'cash_difference', severity: 'critical', count: 1, amount: '-23500.00', meta: { tolerance: '5000.00' }, link: null },
  { kind: 'low_stock', severity: 'ok', count: 0, amount: null, meta: {}, link: { route: 'stock', query: { low: 1 } } },
];

async function renderPanel(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['sales', 'stock'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  router.push('/sales');
  await router.isReady();
  const view = render(AttentionPanel, {
    props: { items: ITEMS, count: 2, scopeText: 'Seluruh toko', computedAt: '2026-10-11T12:30:04+07:00', state: 'ready', ...props },
    global: { plugins: [pinia, router] },
  });
  return { ...view, pinia };
}

describe('AttentionPanel (042 T029)', () => {
  it('shows the title, a warning count chip and the scope line with the store-timezone time', async () => {
    await renderPanel();
    expect(screen.getByRole('heading', { level: 2, name: 'Perlu tindakan' })).toBeInTheDocument();
    expect(screen.getByTestId('attention-count')).toHaveTextContent('2');
    expect(screen.getByTestId('attention-count')).toHaveClass('bg-warn-bg');
    expect(screen.getByTestId('attention-scope')).toHaveTextContent('Seluruh toko · per 12.30 · klik untuk menindaklanjuti');
  });

  it('formats the scope-line time in the STORE timezone, not the browser one', async () => {
    const { pinia } = await renderPanel();
    useSettingsStore(pinia).storeTimezone = 'Asia/Jayapura'; // +09:00 -> 14.30
    expect(await screen.findByText(/per 14\.30/)).toBeInTheDocument();
  });

  it('shows a NEUTRAL zero chip (zero is a value) when nothing needs action', async () => {
    await renderPanel({ count: 0, items: ITEMS.map((i) => ({ ...i, severity: 'ok', count: 0, amount: null })) });
    expect(screen.getByTestId('attention-count')).toHaveTextContent('0');
    expect(screen.getByTestId('attention-count')).toHaveClass('bg-line-7');
    expect(screen.getByTestId('attention-count')).not.toHaveClass('bg-warn-bg');
    expect(screen.getAllByTestId('attention-ok-text')).toHaveLength(4);
  });

  it('renders every item; link only where `link` is given, plain text elsewhere', async () => {
    await renderPanel();
    const panel = screen.getByTestId('attention-panel');
    expect(within(panel).getByText('Pembayaran menunggu verifikasi')).toBeInTheDocument();
    expect(within(panel).getByText('Selisih kas shift')).toBeInTheDocument();
    expect(within(panel).getByText('Tidak ada void setelah fire')).toBeInTheDocument();
    // hanya payments_pending yang punya link DAN perlu tindakan (low_stock punya link tetapi ok)
    const links = within(panel).getAllByRole('link');
    expect(links).toHaveLength(1);
    expect(links[0]).toHaveAttribute('href', '/sales?pstate=pending');
    expect(within(panel).queryAllByRole('button')).toHaveLength(0);
  });

  it('shows its own loading state', async () => {
    await renderPanel({ state: 'loading', items: [] });
    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(screen.queryByTestId('attention-count')).toBeNull();
  });

  it('shows an error with Retry and emits `retry`', async () => {
    const user = userEvent.setup();
    const { emitted } = await renderPanel({ state: 'error', items: [] });
    expect(screen.getByRole('alert')).toHaveTextContent('Gagal memuat daftar tindakan');
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(emitted().retry).toHaveLength(1);
  });

  it('shows an empty message when the API returned no items', async () => {
    await renderPanel({ items: [], count: 0 });
    expect(screen.getByText('Tidak ada yang perlu ditindaklanjuti.')).toBeInTheDocument();
  });
});
