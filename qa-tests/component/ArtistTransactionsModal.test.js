import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ArtistTransactionsModal from '../../resources/js/components/report/ArtistTransactionsModal.vue';
import { artistSettlementTransactions } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/reports', () => ({
  artistSettlementTransactions: vi.fn(),
}));

// 040-recap-pos-transactions-only — the seller transaction-detail drilldown lists
// POS transactions only ({key, number, created_at, items, amount_for_artist};
// the 012 `source` field and pre-order entries are gone), so its amounts add up
// to the seller's Sales on the (POS-only) recap row.
const POS_RESPONSE = {
  transactions: [
    {
      key: 'order-201',
      number: 'ORD-0201',
      created_at: '2026-09-01T10:00:00Z',
      items: [{ sku: 'KC-001', name: 'Keychain A', qty: 2, line_total: '30000.00' }],
      amount_for_artist: '30000.00',
    },
    {
      key: 'order-202',
      number: 'ORD-0202',
      created_at: '2026-09-02T11:00:00Z',
      items: [{ sku: 'KC-002', name: 'Keychain B', qty: 1, line_total: '15000.00' }],
      amount_for_artist: '15000.00',
    },
  ],
};

function renderModal(props) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(ArtistTransactionsModal, {
    props: {
      open: true,
      artistId: 5,
      artistName: 'Artist Uno',
      eventId: 1,
      ...props,
    },
    global: { plugins: [pinia] },
  });
}

describe('ArtistTransactionsModal — POS-only transaction detail', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders each POS transaction with its own number/date/items/amount', async () => {
    artistSettlementTransactions.mockResolvedValue(POS_RESPONSE);

    renderModal();

    await waitFor(() => expect(artistSettlementTransactions).toHaveBeenCalledWith(5, 1));

    expect(await screen.findByText('ORD-0201')).toBeInTheDocument();
    expect(screen.getByText('KC-001')).toBeInTheDocument();
    expect(screen.getByText('Keychain A')).toBeInTheDocument();
    expect(screen.getAllByText('Rp 30.000').length).toBeGreaterThan(0);

    expect(screen.getByText('ORD-0202')).toBeInTheDocument();
    expect(screen.getByText('KC-002')).toBeInTheDocument();
    expect(screen.getAllByText('Rp 15.000').length).toBeGreaterThan(0);
  });

  it('shows no Vue key-collision warning when several transactions are rendered', async () => {
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    artistSettlementTransactions.mockResolvedValue(POS_RESPONSE);

    renderModal();
    await screen.findByText('ORD-0201');

    const duplicateKeyWarning = warnSpy.mock.calls.some((args) =>
      args.some((arg) => typeof arg === 'string' && arg.toLowerCase().includes('duplicate key'))
    );
    expect(duplicateKeyWarning).toBe(false);
    warnSpy.mockRestore();
  });

  it('shows no sale / pre-order type badge any more', async () => {
    artistSettlementTransactions.mockResolvedValue(POS_RESPONSE);

    renderModal();
    await screen.findByText('ORD-0201');

    expect(screen.queryByText('Penjualan')).not.toBeInTheDocument();
    expect(screen.queryByText('Pre-order')).not.toBeInTheDocument();
  });

  it('shows an empty state when there are no contributing transactions', async () => {
    artistSettlementTransactions.mockResolvedValue({ transactions: [] });

    renderModal();

    expect(await screen.findByText(/belum ada transaksi yang menyumbang/i)).toBeInTheDocument();
  });
});
